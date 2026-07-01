<?php
const DEBUG = false;
const TENANT_MAPPING = ["gnb2" => "99992"/*, "gnb3" => "99993", "gnb4" => "99994"*/];

// Crash and exception handling
ini_set("display_errors", 0);
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    // Environment and DB setup
    if (!class_exists("Redis")) {
        throw new Exception("Redis extension missing!");
    }

    $host = "postgres-postgresql.postgres.svc.cluster.local";
    $db = "polimi";
    $user = "polimi";
    $pass = "polimi";

    $dsn = "pgsql:host=$host;dbname=$db";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, $user, $pass, $options);

    // Redis setup
    $redis = new Redis();
    $REDIS_HOST = "redis-master.redis.svc.cluster.local";
    $REDIS_PORT = 6379;
    $REDIS_AUTH = "polimi";
    if (!@$redis->connect($REDIS_HOST, $REDIS_PORT)) {
        throw new Exception("Failed Redis connection enstablishment");
    }
    if (!@$redis->auth($REDIS_AUTH)) {
        throw new Exception("Failed Redis authentication");
    }

    $json_payload = file_get_contents("php://input");
    if (empty($json_payload)) {
        http_response_code(400);
        exit("No payload");
    }
    $events = json_decode($json_payload, true);

    // PostgreSQL query setup
    $qInsertSess = $pdo->prepare(
        'INSERT INTO polimi.CustomerSession (imsi, ue_id, amf_id, rnti, ipv4, "start") VALUES (:imsi, :ue_id, :amf_id, :rnti, :ipv4, :start) RETURNING id',
    );
    // "Excluded" values are the one proposed but conflicting and used for update
    $qUpsertPerf = $pdo->prepare('
        INSERT INTO polimi.CustomerPerformance (session_id, imsi, tx_kbytes, rx_kbytes)
        VALUES (:session_id, :imsi, :tx, :rx)
        ON CONFLICT (session_id, imsi) DO UPDATE
        SET tx_kbytes = EXCLUDED.tx_kbytes, rx_kbytes = EXCLUDED.rx_kbytes
    ');
    $qCloseSess = $pdo->prepare(
        'UPDATE polimi.CustomerSession SET "end" = :end_time WHERE id = :db_id AND "end" IS NULL',
    );

    // Aggregation structures
    $local_mappings = [];
    $local_sessions = [];
    $performance_updates = [];

    // Events processing loop
    foreach ($events as $e) {
        if (empty($e["rnti"])) {
            continue;
        }

        $type = $e["metric_type"];
        $rnti = strtolower($e["rnti"]);
        $timestamp = $e["timestamp"] ?? date("Y-m-d H:i:s");

        // Tenant identification
        $tenant_log = $e["tenant_instance"] ?? "oai-gnb";
        // Matching is impossible against "oai-gnb" because "gnb" includes also "gnb2", "gnb3", "gnb4", ...
        // So the base case is "gnb"
        $tenant = TENANT_MAPPING[$tenant_log] ?? "99991";

        $cache_key = "{$tenant}:{$rnti}";
        $redis_target_key = "{$cache_key}:mapping";

        // Mapping detection
        if (!array_key_exists($cache_key, $local_mappings)) {
            $hash_data = $redis->hGetAll($redis_target_key);
            $local_mappings[$cache_key] = !empty($hash_data)
                ? $hash_data
                : null;
        }
        $mapping = $local_mappings[$cache_key];

        // Retrieving active session ID from Postgres
        $sess_redis_key = "active_sess:{$cache_key}";
        if (!array_key_exists($cache_key, $local_sessions)) {
            $local_sessions[$cache_key] = $redis->get($sess_redis_key);
        }
        $db_id = $local_sessions[$cache_key];

        // Disconnection logic
        if (!$mapping || $type === "gnb_rrc_disconnected") {
            if ($db_id) {
                try {
                    $qCloseSess->execute([
                        ":end_time" => $timestamp,
                        ":db_id" => $db_id,
                    ]);
                } catch (\Exception $ex) {
                    if (DEBUG) {
                        file_put_contents(
                            "/var/www/html/webhook_db_err.log",
                            date("Y-m-d H:i:s") . " - CLOSE ERR: " . $ex->getMessage() . "\n",
                            FILE_APPEND,
                        );
                    }
                }
                $redis->del($sess_redis_key);
                $local_sessions[$cache_key] = false;
            }
            continue;
        }

        // New session detected: initialization
        $imsi = $mapping["IMSI"] ?? "";

        if (!$db_id && $imsi) {
            try {
                $qInsertSess->execute([
                    ":imsi" => $imsi,
                    ":ue_id" => $mapping["ran_ue_id"] ?? 0,
                    ":amf_id" => $mapping["amf_id"] ?? 0,
                    ":rnti" => $rnti,
                    ":ipv4" => !empty($mapping["IPv4"])
                        ? $mapping["IPv4"]
                        : null,
                    ":start" => $timestamp,
                ]);
                $db_id = $qInsertSess->fetch()["id"];

                $redis->set($sess_redis_key, $db_id);
                $local_sessions[$cache_key] = $db_id;
            } catch (\Exception $ex) {
                if (DEBUG) {
                    file_put_contents(
                        "/var/www/html/webhook_db_err.log",
                        date("Y-m-d H:i:s") . " - INSERT SESS ERR: " . $ex->getMessage() . "\n",
                        FILE_APPEND,
                    );
                }
                continue;
            }
        }

        // Performance metrics cumulative
        if ($db_id && $type === "gnb_mac_throughput") {
            $performance_updates[$db_id] = [
                "imsi" => $imsi,
                "tx" => (int)floor($e["tx_bytes"] / 1024),
                "rx" => (int)floor($e["rx_bytes"] / 1024),
            ];
        }

        // Dashboard metrics update
        if ($imsi) {
            $metrics_key = "dashboard:metrics:{$imsi}";
            $live_data = [];

            if ($type === "gnb_signal_quality") {
                $live_data["rsrp"] = $e["rsrp_dbm"];
            }
            if ($type === "gnb_mcs_dl") {
                $live_data["mcs_dl"] = $e["mcs_dl"];
            }
            if ($type === "gnb_mcs_ul") {
                $live_data["mcs_ul"] = $e["mcs_ul"];
                if (isset($e["snr_db"])) {
                    $live_data["snr"] = $e["snr_db"];
                }
            }
            if ($type === "gnb_mac_throughput") {
                $live_data["tx_kb"] = (int)floor($e["tx_bytes"] / 1024);
                $live_data["rx_kb"] = (int)floor($e["rx_bytes"] / 1024);
            }

            if (!empty($live_data)) {
                $redis->hMSet($metrics_key, $live_data);
                $redis->expire($metrics_key, 600);
            }
        }
    }

    // Mass throughput upgrade on DB (one UE at a time)
    foreach ($performance_updates as $sid => $perf) {
        try {
            $qUpsertPerf->execute([
                ":session_id" => $sid,
                ":imsi" => $perf["imsi"],
                ":tx" => $perf["tx"],
                ":rx" => $perf["rx"],
            ]);
        } catch (\Exception $ex) {
            if (DEBUG) {
                file_put_contents(
                    "/var/www/html/webhook_db_err.log",
                    date("Y-m-d H:i:s") . " - UPSERT PERF ERR: " . $ex->getMessage() . "\n",
                    FILE_APPEND,
                );
            }
        }
    }

    http_response_code(200);
    echo "OK";
} catch (\Throwable $e) {
    $error_msg = date("Y-m-d H:i:s") . " - FATAL ERROR: " . $e->getMessage() . " in " . $e->getFile() . " at line " . $e->getLine() . "\n";
    if (DEBUG) {
        file_put_contents(
            "/var/www/html/php_webhook_error.log",
            $error_msg,
            FILE_APPEND,
        );
    }
    http_response_code(500);
    echo "Internal error";
}
