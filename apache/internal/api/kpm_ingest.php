<?php
header("Content-Type: application/json");

$json_data = file_get_contents("php://input");

if (empty($json_data)) {
    http_response_code(400);
    die(json_encode(["error" => "No data received"]));
}

$data = json_decode($json_data, true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($data["ue_list"])) {
    http_response_code(400);
    die(json_encode(["error" => "Could not parse JSON data or format"]));
}

$redis_host = "redis-master.redis.svc.cluster.local";
$redis_port = 6379;
// $redis_user = "polimi";
$redis_pass = "polimi";

try {
    $redis = new Redis();

    if (!$redis->connect($redis_host, $redis_port, 2)) {
        throw new Exception(
            "Connection to Redis failed. $redis_host:$redis_port",
        );
    }

    // Redis ACL
    $redis->auth($redis_pass);

    $timestamp = $data["timestamp"];
    $keys_updated = 0;

    foreach ($data["ue_list"] as $ue) {
        $amf_id = $ue["amf_ue_ngap_id"];
        $redis_key = "dashboard:kpm:{$amf_id}";

        // Key-Value pairs derived from KPM
        $hash_data = [
            "timestamp" => $timestamp,
            "ran_ue_id" => $ue["ran_ue_id"],
            "PdcpSduVolumeDL_Mb" => $ue["PdcpSduVolumeDL_Mb"],
            "PdcpSduVolumeUL_Mb" => $ue["PdcpSduVolumeUL_Mb"],
            "RlcSduDelayDl_s" => $ue["RlcSduDelayDl_s"],
            "UEThpDl_kbps" => $ue["UEThpDl_kbps"],
            "UEThpUl_kbps" => $ue["UEThpUl_kbps"],
            "PrbTotDl_pct" => $ue["PrbTotDl_pct"],
            "PrbTotUl_pct" => $ue["PrbTotUl_pct"],
            "PacketLossRateDl_pct" => $ue["PacketLossRateDl_pct"],
            "PdcpDropRateDl_pct" => $ue["PdcpDropRateDl_pct"],
        ];

        $redis->hMSet($redis_key, $hash_data);

        // Zombie sessions are discarded within a 25 second timeout
        $redis->expire($redis_key, 25);

        $keys_updated++;
    }

    echo json_encode([
        "status" => "success",
        "message" => "Success. Registered $keys_updated entries.",
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Exception: " . $e->getMessage()]);
}
?>
