<?php
require __DIR__ . "/src/bootstrap.php";
const CACHE_TIMEOUT = 14400;
const MAX_THREAD_THRESHOLD = 8;

use NHMP\Auth;
use NHMP\Database;
use NHMP\RedisCache;

// Setting this to true locks core allocation in couples of threads
const SMT = true;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION["resource_control_csrf"])) {
    $_SESSION["resource_control_csrf"] = bin2hex(random_bytes(32));
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($payload);
    exit();
}

function authorizedTenantsForUser(string $userId): array
{
    $pdo = Database::pdo();
    $stmt = $pdo->prepare('
        SELECT up.tenant AS plmn, t.subnet
        FROM UserPermissions up
        JOIN Tenant t ON t.PLMN = up.tenant
        WHERE up.userId = :userId
        ORDER BY up.tenant
    ');
    $stmt->execute(["userId" => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $result = [];
    foreach ($rows as $row) {
        $plmn = trim((string)($row["plmn"] ?? ""));
        $subnet = trim((string)($row["subnet"] ?? ""));
        if ($plmn !== "" && $subnet !== "") {
            $result[$plmn] = $subnet;
        }
    }
    return $result;
}

function isValidIpv4(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function ipInCidr(string $ip, string $cidr): bool
{
    if (!str_contains($cidr, "/")) {
        return $ip === $cidr;
    }
    [$subnet, $bits] = explode("/", $cidr, 2);
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    $bits = (int)$bits;

    if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
        return false;
    }

    $mask = $bits === 0 ? 0 : -1 << (32 - $bits);
    $subnetLong &= $mask;
    return ($ipLong & $mask) === $subnetLong;
}

function fetchTenantStatus(array $tenantMap): array
{
    $redis = RedisCache::client();
    if (!$redis) {
        return [];
    }

    $rows = [];
    foreach ($tenantMap as $plmn => $subnet) {
        $payload = $redis->hGetAll("dashboard:status:$plmn");
        if (!$payload) {
            continue;
        }

        $decoded = json_decode(
                (string)($payload["allocated_cores"] ?? "[]"),
                true,
        );
        $allocated = [];
        if (is_array($decoded)) {
            $allocated = array_values(
                    array_map(
                            "intval",
                            array_filter(
                                    $decoded,
                                    fn($v) => is_int($v) || ctype_digit((string)$v),
                            ),
                    ),
            );
        }

        $rows[$plmn] = [
                "tenant" => $plmn,
                "subnet" => $subnet,
                "deployment" => (string)($payload["deployment"] ?? ""),
                "allocated_cores" => $allocated,
                "fronthaul_core" => trim(
                        (string)($payload["fronthaul_core"] ?? "None"),
                ),
                "current_governor" =>
                        (string)($payload["current_governor"] ?? "unknown"),
                "base_governor" =>
                        (string)($payload["base_governor"] ?? "unknown"),
                "hw_error" => filter_var(
                        $payload["hw_error"] ?? false,
                        FILTER_VALIDATE_BOOLEAN,
                ),
                "active_users" => (int)($payload["active_users"] ?? 0),
                "timestamp" => (float)($payload["timestamp"] ?? 0),
        ];
    }
    ksort($rows);
    return array_values($rows);
}

function fetchConsumptionRows(array $tenants): array
{
    if (!$tenants) {
        return [];
    }
    $pdo = Database::pdo();
    $ph = implode(",", array_fill(0, count($tenants), "?"));
    $stmt = $pdo->prepare("
        SELECT tenant, cpu_usage, dynamic_watts, fixed_watts, start, \"end\"
        FROM polimi.TenantConsumption
        WHERE tenant IN ($ph)
        AND start >= NOW() - INTERVAL '8 weeks'
        ORDER BY start ASC
    ");
    $stmt->execute(array_values($tenants));
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetchEnergyDataFromApi(): array
{
    $redis = RedisCache::client();
    $cacheKey = "dashboard:energy_prices_history";

    // Redis cache
    if ($redis) {
        $cached = $redis->get($cacheKey);
        if ($cached) {
            $parsed = json_decode($cached, true);
            if (
                    is_array($parsed) &&
                    isset($parsed["current"], $parsed["history"])
            ) {
                return $parsed;
            }
        }
    }

    // API update if no cache
    $ctx = stream_context_create(["http" => ["timeout" => 8]]);
    $startDate = gmdate("Y-m-d", strtotime("-8 weeks"));
    $endDate = gmdate("Y-m-d", strtotime("+1 day"));

    $url = "https://api.energy-charts.info/price?bzn=IT-North&start={$startDate}&end={$endDate}";
    $raw = @file_get_contents($url, false, $ctx);

    $currentPrice = 0.18; // Fallback value
    $dailyPrices = [];
    $apiSuccess = false;

    if ($raw !== false) {
        $j = json_decode($raw, true);
        if (is_array($j) && !empty($j["unix_seconds"]) && !empty($j["price"])) {
            $apiSuccess = true;
            $now = time();
            $closestDiff = PHP_INT_MAX;

            $timestamps = $j["unix_seconds"];
            $prices = $j["price"];
            $dailyAgg = [];

            foreach ($timestamps as $index => $ts) {
                $p = $prices[$index] ?? null;
                if ($p === null) {
                    continue;
                }

                $priceKwh = (float)$p / 1000.0;

                // Weekly cost estimation
                $date = gmdate("Y-m-d", $ts);
                if (!isset($dailyAgg[$date])) {
                    $dailyAgg[$date] = ["sum" => 0.0, "count" => 0];
                }
                $dailyAgg[$date]["sum"] += $priceKwh;
                $dailyAgg[$date]["count"]++;

                // Detect the current price
                $diff = abs($ts - $now);
                if ($diff < $closestDiff) {
                    $closestDiff = $diff;
                    $currentPrice = $priceKwh;
                }
            }

            foreach ($dailyAgg as $date => $data) {
                $dailyPrices[$date] = $data["sum"] / max(1, $data["count"]);
            }
        }
    }

    $result = [
            "current" => ["as_of" => gmdate("c"), "eur_per_kwh" => $currentPrice],
            "history" => $dailyPrices,
    ];

    // Cache expiration policy
    if ($redis && $apiSuccess) {
        $redis->setex($cacheKey, CACHE_TIMEOUT, json_encode($result));
    }

    return $result;
}

function hourlySeriesForTenant(array $rows, string $tenant): array
{
    $end = new DateTimeImmutable("now");
    $start = $end->sub(new DateInterval("PT24H"));
    $buckets = [];

    for ($i = 0; $i < 24; $i++) {
        $t = $start->add(new DateInterval("PT" . $i . "H"));
        $key = $t->format("Y-m-d H:00");
        $buckets[$key] = [
                "label" => $t->format("H:00"),
                "dynamic" => 0.0,
                "fixed" => 0.0,
        ];
    }

    foreach ($rows as $r) {
        if ((string)$r["tenant"] !== $tenant) {
            continue;
        }

        $s = new DateTimeImmutable((string)$r["start"]);
        $e = new DateTimeImmutable((string)$r["end"]);

        if ($s < $start || $s > $end) {
            continue;
        }

        $key = $s->format("Y-m-d H:00");
        if (!isset($buckets[$key])) {
            continue;
        }

        $seconds = max(1, $e->getTimestamp() - $s->getTimestamp());
        $dynW = max(0.0, (float)$r["dynamic_watts"]);
        $fixW = max(0.0, (float)$r["fixed_watts"]);

        $buckets[$key]["dynamic"] += ($dynW * ($seconds / 3600.0)) / 1000.0;
        $buckets[$key]["fixed"] += ($fixW * ($seconds / 3600.0)) / 1000.0;
    }

    return [
            "labels" => array_values(array_map(fn($x) => $x["label"], $buckets)),
            "dynamic" => array_values(array_map(fn($x) => $x["dynamic"], $buckets)),
            "fixed" => array_values(array_map(fn($x) => $x["fixed"], $buckets)),
    ];
}

function weeklyCostRows(array $rows, array $pricesByDay): array
{
    $agg = [];
    foreach ($rows as $r) {
        $tenant = (string)$r["tenant"];
        $s = new DateTimeImmutable((string)$r["start"]);
        $e = new DateTimeImmutable((string)$r["end"]);

        $seconds = max(1, $e->getTimestamp() - $s->getTimestamp());
        $dynKwh =
                (max(0.0, (float)$r["dynamic_watts"]) * ($seconds / 3600.0)) /
                1000.0;
        $fixKwh =
                (max(0.0, (float)$r["fixed_watts"]) * ($seconds / 3600.0)) /
                1000.0;
        $energyKwh = $dynKwh + $fixKwh;

        $dayKey = $s->format("Y-m-d");
        $price = $pricesByDay[$dayKey] ?? 0.18;

        $weekKey = $s->format("o-\\WW");
        $weekStart = new DateTimeImmutable($s->format("o-\\WW-1"))->format(
                "Y-m-d 00:00:00",
        );

        $agg[$tenant][$weekKey]["week_start"] = $weekStart;
        $agg[$tenant][$weekKey]["energy_kwh"] =
                ($agg[$tenant][$weekKey]["energy_kwh"] ?? 0) + $energyKwh;
        $agg[$tenant][$weekKey]["cost_eur"] =
                ($agg[$tenant][$weekKey]["cost_eur"] ?? 0) + $energyKwh * $price;
    }

    $out = [];
    foreach ($agg as $tenant => $weeks) {
        foreach ($weeks as $weekKey => $v) {
            $out[] = [
                    "tenant" => $tenant,
                    "week_start" => $v["week_start"],
                    "energy_kwh" => round($v["energy_kwh"], 3),
                    "estimated_cost_eur" => round($v["cost_eur"], 2),
            ];
        }
    }

    usort(
            $out,
            fn($a, $b) => [$b["week_start"], $a["tenant"]] <=> [
                            $a["week_start"],
                            $b["tenant"],
                    ],
    );
    return $out;
}

function postToController(string $path, array $payload): array
{
    $url =
            "http://resource-controller-svc.resource-controller.svc.cluster.local" .
            $path;
    $ch = curl_init($url);

    curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                    "Content-Type: application/json",
                    "Accept: application/json",
            ],
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false) {
        return [
                "ok" => false,
                "status" => 502,
                "message" => $err ?: "Connection error",
                "payload" => null,
        ];
    }

    $dec = json_decode($raw, true);
    $ok = $code >= 200 && $code < 300;

    return [
            "ok" => $ok,
            "status" => $code ?: ($ok ? 200 : 502),
            "message" => is_array($dec)
                    ? $dec["detail"] ?? ($dec["message"] ?? "Request rejected")
                    : trim($raw),
            "payload" => is_array($dec) ? $dec : ["raw" => $raw],
    ];
}

// Data initialization
$user = Auth::requireUser();
$tenantMap = authorizedTenantsForUser((string)$user["id"]);
$tenantKeys = array_keys($tenantMap);
$statuses = fetchTenantStatus($tenantMap);
$consumptionRows = fetchConsumptionRows($tenantKeys);

$energyData = fetchEnergyDataFromApi();
$energy = $energyData["current"];
$dailyPrices = $energyData["history"];

$seriesTenant = $tenantKeys[0] ?? "";
$consumptionByTenant = [];

foreach ($tenantKeys as $tk) {
    $consumptionByTenant[$tk] = array_values(
            array_filter(
                    $consumptionRows,
                    fn($r) => (string)$r["tenant"] === (string)$tk,
            ),
    );
}

$series =
        $seriesTenant !== ""
                ? hourlySeriesForTenant(
                $consumptionByTenant[$seriesTenant],
                $seriesTenant,
        )
                : ["labels" => [], "dynamic" => [], "fixed" => []];
$weeklyRows = weeklyCostRows($consumptionRows, $dailyPrices);

// Total performance metrics computation
$totals = ["cores" => 0, "users" => 0, "dynamic" => 0.0, "fixed" => 0.0];
foreach ($statuses as $s) {
    $totals["cores"] += count($s["allocated_cores"]);
    $totals["users"] += (int)$s["active_users"];
}

$latestConsumption = [];
foreach ($consumptionRows as $r) {
    $latestConsumption[$r["tenant"]] = $r;
}
foreach ($latestConsumption as $r) {
    $totals["dynamic"] += (float)$r["dynamic_watts"];
    $totals["fixed"] += (float)$r["fixed_watts"];
}

// Router API
if (($_GET["action"] ?? "") === "status") {
    jsonResponse([
            "ok" => true,
            "tenants" => $statuses,
            "totals" => $totals,
            "price" => $energy,
            "_meta" => ["generated_at" => gmdate("c")],
    ]);
}

if (($_GET["action"] ?? "") === "series") {
    $tenant = trim((string)($_GET["tenant"] ?? ""));
    if (!isset($tenantMap[$tenant])) {
        jsonResponse(["ok" => false, "message" => "Unauthorized tenant"], 403);
    }
    jsonResponse([
            "ok" => true,
            "tenant" => $tenant,
            "series" => hourlySeriesForTenant(
                    $consumptionByTenant[$tenant] ?? [],
                    $tenant,
            ),
    ]);
}

if (($_GET["action"] ?? "") === "weekly") {
    jsonResponse([
            "ok" => true,
            "rows" => $weeklyRows,
            "_meta" => ["generated_at" => gmdate("c")],
    ]);
}

if (
        $_SERVER["REQUEST_METHOD"] === "POST" &&
        ($_POST["action"] ?? "") === "scale"
) {
    if (defined("DEMO") && DEMO) {
        jsonResponse(
                [
                        "ok" => false,
                        "message" => "Demo mode: hardware control is not allowed",
                ],
                403,
        );
    }

    $tenant = trim((string)($_POST["tenant_id"] ?? ""));
    $cores = (int)($_POST["target_cores"] ?? 0);

    if (!isset($tenantMap[$tenant])) {
        jsonResponse(["ok" => false, "message" => "Unauthorized tenant"], 403);
    }

    // SMT evaluation
    $minCores = SMT ? 2 : 1;
    if (
            $cores < $minCores ||
            $cores > MAX_THREAD_THRESHOLD ||
            (SMT && $cores % 2 !== 0)
    ) {
        $msg = SMT
                ? "Invalid core count (must be a multiple of 2)"
                : "Invalid core count";
        jsonResponse(["ok" => false, "message" => $msg], 422);
    }

    $r = postToController("/api/tenant/scale", [
            "tenant_id" => $tenant,
            "target_cores" => $cores,
    ]);
    if (!$r["ok"]) {
        jsonResponse($r, $r["status"]);
    }

    jsonResponse([
            "ok" => true,
            "message" => "Scale request submitted successfully",
            "response" => $r["payload"],
    ]);
}

if (
        $_SERVER["REQUEST_METHOD"] === "POST" &&
        ($_POST["action"] ?? "") === "governor"
) {
    if (defined("DEMO") && DEMO) {
        jsonResponse(
                [
                        "ok" => false,
                        "message" => "Demo mode: hardware control is not allowed",
                ],
                403,
        );
    }
    $tenant = trim((string)($_POST["tenant_id"] ?? ""));
    $governor = trim((string)($_POST["governor"] ?? ""));

    if (!isset($tenantMap[$tenant])) {
        jsonResponse(["ok" => false, "message" => "Unauthorized tenant"], 403);
    }
    if (
            !in_array(
                    $governor,
                    [
                            "powersave",
                            "conservative",
                            "ondemand",
                            "schedutil",
                            "performance",
                    ],
                    true,
            )
    ) {
        jsonResponse(["ok" => false, "message" => "Invalid governor"], 422);
    }

    $r = postToController("/api/tenant/governor", [
            "tenant_id" => $tenant,
            "governor" => $governor,
    ]);
    if (!$r["ok"]) {
        jsonResponse($r, $r["status"]);
    }

    jsonResponse([
            "ok" => true,
            "message" => "Governor request submitted successfully",
            "response" => $r["payload"],
    ]);
}
?>
<!doctype html>
<html lang="en" data-theme="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Resource Control</title>
        <link rel="preconnect" href="https://api.fontshare.com">
        <link href="https://api.fontshare.com/v2/css?f[]=satoshi@400,500,700,900&display=swap" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.0"></script>
        <style>
            :root, [data-theme="light"] {
                --bg: #f7f6f2;
                --surface: #fbfbf9;
                --surface2: #f1ede7;
                --text: #25231d;
                --muted: #6f6d66;
                --primary: #0b6b74;
                --primary2: #dbe8e7;
                --success: #2d7a36;
                --error: #9f3344;
                --shadow: 0 12px 28px rgba(37, 35, 29, .08);
                --radius: 18px;
                --font: 'Satoshi', system-ui, sans-serif;
            }

            [data-theme="dark"] {
                --bg: #161514;
                --surface: #1b1a18;
                --surface2: #21201d;
                --text: #ece8df;
                --muted: #a8a39a;
                --primary: #57aab3;
                --primary2: #22373a;
                --success: #76c96f;
                --error: #dd6b77;
                --shadow: 0 12px 28px rgba(0, 0, 0, .28);
            }

            * {
                box-sizing: border-box;
            }

            html, body {
                min-height: 100%;
            }

            body {
                margin: 0;
                font-family: var(--font);
                background: radial-gradient(circle at top right, color-mix(in srgb, var(--primary) 10%, transparent), transparent 30%), var(--bg);
                color: var(--text);
            }

            button, input, select {
                font: inherit;
                color: inherit;
            }

            button {
                cursor: pointer;
                border: 0;
            }

            .page {
                max-width: 1680px;
                margin: 0 auto;
                padding: 24px;
            }

            .top {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 16px;
                margin-bottom: 20px;
                flex-wrap: wrap;
            }

            .title h1 {
                margin: 0;
                font-size: clamp(1.6rem, 1.2rem + 1.4vw, 2.4rem);
                line-height: 1.05;
            }

            .title p {
                margin: .45rem 0 0;
                color: var(--muted);
                max-width: 92ch;
            }

            .actions {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
                align-items: center;
            }

            .pill {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: .45rem .75rem;
                border-radius: 999px;
                background: var(--primary2);
                color: var(--primary);
                font-size: .84rem;
            }

            .btn {
                height: 44px;
                padding: 0 14px;
                border-radius: 999px;
                background: var(--surface);
                border: 1px solid color-mix(in srgb, var(--text) 10%, transparent);
            }

            .btn.primary {
                background: var(--primary);
                color: #fff;
                border-color: transparent;
            }

            .grid-kpi {
                display: grid;
                grid-template-columns: repeat(5, minmax(0, 1fr));
                gap: 16px;
                margin-bottom: 18px;
            }

            .card {
                background: color-mix(in srgb, var(--surface) 92%, transparent);
                border: 1px solid color-mix(in srgb, var(--text) 10%, transparent);
                border-radius: var(--radius);
                box-shadow: var(--shadow);
            }

            .kpi {
                padding: 16px;
            }

            .kpi label {
                display: block;
                font-size: .76rem;
                text-transform: uppercase;
                letter-spacing: .08em;
                color: var(--muted);
            }

            .kpi strong {
                display: block;
                margin-top: 10px;
                font-size: clamp(1.45rem, 1.2rem + 1vw, 2.2rem);
                font-variant-numeric: tabular-nums;
            }

            .kpi span {
                color: var(--muted);
                font-size: .86rem;
            }

            .split {
                display: grid;
                grid-template-columns: 1.05fr .95fr;
                gap: 16px;
                align-items: start;
            }

            .panel {
                padding: 18px;
            }

            .section-title {
                display: flex;
                justify-content: space-between;
                gap: 10px;
                align-items: flex-start;
                margin-bottom: 14px;
            }

            .section-title h2 {
                margin: 0;
                font-size: .9rem;
                text-transform: uppercase;
                letter-spacing: .1em;
                color: var(--muted);
            }

            .section-title p {
                margin: 6px 0 0;
                color: var(--muted);
            }

            .table-wrap {
                overflow: auto;
                border-radius: 14px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                min-width: 980px;
            }

            th, td {
                padding: 12px 10px;
                border-bottom: 1px solid color-mix(in srgb, var(--text) 9%, transparent);
                font-size: .92rem;
                vertical-align: middle;
            }

            th {
                position: sticky;
                top: 0;
                background: var(--surface);
                z-index: 1;
                font-size: .74rem;
                text-transform: uppercase;
                letter-spacing: .09em;
                color: var(--muted);
                text-align: left;
            }

            .mono {
                font-variant-numeric: tabular-nums;
            }

            .sub {
                display: block;
                margin-top: 4px;
                color: var(--muted);
                font-size: .78rem;
            }

            .bars {
                display: grid;
                gap: 7px;
                min-width: 140px;
            }

            .rowbar {
                display: grid;
                gap: 4px;
            }

            .lab {
                font-size: .74rem;
                color: var(--muted);
            }

            .track {
                height: 8px;
                border-radius: 999px;
                background: var(--surface2);
                overflow: hidden;
            }

            .fill {
                height: 100%;
                border-radius: 999px;
            }

            .fill.dyn {
                background: linear-gradient(90deg, #0b6b74, #5bbdc8);
            }

            .fill.fix {
                background: linear-gradient(90deg, #7c3aed, #c4b5fd);
            }

            .status-row {
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                gap: 12px;
                align-items: start;
            }

            .control {
                padding: 12px;
                border-radius: 16px;
                background: var(--surface2);
                border: 1px solid color-mix(in srgb, var(--text) 8%, transparent);
            }

            .control label {
                display: block;
                font-size: .72rem;
                color: var(--muted);
                text-transform: uppercase;
                letter-spacing: .08em;
                margin-bottom: 8px;
            }

            /* -- STILI AGGIUNTI PER LEGGIBILITA' INPUT -- */
            .cores-input, .gov-select {
                width: 100%;
                height: 38px;
                padding: 0 10px;
                border-radius: 8px;
                border: 1px solid color-mix(in srgb, var(--text) 20%, transparent);
                background: var(--surface);
                color: var(--text);
                font-weight: 500;
            }

            .small {
                font-size: .8rem;
                color: var(--muted);
            }

            .range-shell {
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 8px;
                align-items: center;
            }

            .range-shell input[type=range] {
                width: 100%;
                accent-color: var(--primary);
            }

            .value {
                min-width: 52px;
                text-align: right;
                font-weight: 700;
            }

            .select {
                width: 100%;
                height: 40px;
                border-radius: 999px;
                border: 1px solid color-mix(in srgb, var(--text) 10%, transparent);
                background: var(--surface);
                padding: 0 12px;
            }

            .hint {
                margin-top: 8px;
                font-size: .76rem;
                color: var(--muted);
                line-height: 1.4;
            }

            .msg {
                min-height: 1rem;
                margin-top: 8px;
                font-size: .78rem;
            }

            .ok {
                color: var(--success);
            }

            .err {
                color: var(--error);
            }

            .chart-shell {
                padding: 18px;
            }

            .chart-top {
                display: flex;
                justify-content: space-between;
                gap: 10px;
                align-items: flex-start;
                margin-bottom: 12px;
                flex-wrap: wrap;
            }

            .legend {
                display: flex;
                gap: 12px;
                flex-wrap: wrap;
                font-size: .8rem;
                color: var(--muted);
            }

            .legend span {
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }

            .dot {
                width: 10px;
                height: 10px;
                border-radius: 999px;
                display: inline-block;
            }

            .dot.dyn {
                background: #0b6b74;
            }

            .dot.fix {
                background: #7c3aed;
            }

            .chart-box {
                height: 340px;
            }

            .table-small {
                width: 100%;
                border-collapse: collapse;
            }

            .table-small th, .table-small td {
                padding: 10px 8px;
                font-size: .88rem;
            }

            .table-small th {
                background: var(--surface);
            }

            @media (max-width: 1200px) {
                .grid-kpi {
                    grid-template-columns: 1fr 1fr 1fr;
                }

                .split {
                    grid-template-columns: 1fr;
                }

                .status-row {
                    grid-template-columns: 1fr;
                }
            }

            @media (max-width: 780px) {
                .page {
                    padding: 16px;
                }

                .grid-kpi {
                    grid-template-columns: 1fr 1fr;
                }

                .chart-box {
                    height: 280px;
                }
            }

            .nav-menu {
                display: flex;
                gap: 12px;
                align-items: center;
                flex-wrap: wrap;
            }

            .nav-link {
                text-decoration: none;
                padding: 8px 16px;
                border-radius: 999px;
                color: var(--muted);
                font-weight: 500;
                font-size: .88rem;
                border: 1px solid color-mix(in srgb, var(--text) 10%, transparent);
                background: var(--surface);
                transition: all 0.2s ease;
            }

            .nav-link:hover {
                color: var(--text);
                border-color: color-mix(in srgb, var(--text) 20%, transparent);
            }

            .nav-link.active {
                background: var(--primary);
                color: #fff;
                border-color: transparent;
            }
        </style>
    </head>
    <body>
        <div class="page">
            <div class="top">
                <div class="title">
                    <h1>Resource Control</h1>
                </div>
                <nav class="nav-menu">
                    <a href="index.php" class="nav-link">Overview</a>
                    <a href="prb-map.php" class="nav-link">PRB Heatmap</a>
                    <a href="traffic-control.php" class="nav-link">Traffic Control</a>
                    <a href="resource-control.php" class="nav-link active">Resource Control</a>
                </nav>
                <div class="actions">
                    <span class="pill"
                          id="viewerPill"><?= h(
                                ($user["name"] ?? "") .
                                " " .
                                ($user["surname"] ?? ""),
                        ) ?></span>
                    <span class="pill">Energy price: <strong
                                id="energyPrice">€ <?= h(
                                    number_format(
                                            $energy["eur_per_kwh"],
                                            3,
                                            ",",
                                            ".",
                                    ),
                            ) ?>/kWh</strong></span>
                    <span class="pill" id="lastUpdate">Updated just now</span>
                    <button class="btn" id="themeBtn">◐</button>
                </div>
            </div>

            <section class="grid-kpi">
                <article class="card kpi">
                    <label>Allocated CPU cores</label>
                    <strong id="kpiCores"><?= h($totals["cores"]) ?></strong>
                </article>
                <article class="card kpi">
                    <label>Active users</label>
                    <strong id="kpiUsers"><?= h($totals["users"]) ?></strong>
                </article>
                <article class="card kpi">
                    <label>Dynamic power</label>
                    <strong id="kpiDyn"><?= h(
                                number_format($totals["dynamic"], 2, ",", "."),
                        ) ?></strong>
                    <span>Watts</span>
                </article>
                <article class="card kpi">
                    <label>Fixed power</label>
                    <strong id="kpiFix"><?= h(
                                number_format($totals["fixed"], 2, ",", "."),
                        ) ?></strong>
                    <span>Watts</span>
                </article>
            </section>

            <div class="split">
                <section class="card panel">
                    <div class="section-title">
                        <div>
                            <h2>Tenant status</h2>
                            <p>Live governor profile, allocated cores and current hardware alerts.</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tenant</th>
                                    <th>Deployment</th>
                                    <th>Allocated CPU</th>
                                    <th>Governor</th>
                                    <th>Users</th>
                                    <th>Status</th>
                                    <th>Control</th>
                                </tr>
                            </thead>
                            <tbody id="statusBody">
                                <?php foreach ($statuses as $s): ?>
                                    <tr data-tenant="<?= h($s["tenant"]) ?>">
                                        <td class="mono">
                                            <?= h($s["tenant"]) ?>
                                            <span class="sub"><?= h(
                                                        $s["subnet"],
                                                ) ?></span>
                                        </td>
                                        <td><?= h($s["deployment"]) ?></td>
                                        <td class="mono cores-cell">
                                            <?= h(
                                                    implode(
                                                            ", ",
                                                            $s["allocated_cores"],
                                                    ),
                                            ) ?>
                                            <span class="sub">Fronthaul core: <?= h(
                                                        $s["fronthaul_core"],
                                                ) ?></span>
                                        </td>
                                        <td>
                                            <span class="pill gov-pill"><?= h(
                                                        $s["current_governor"],
                                                ) ?></span>
                                            <span class="sub base-pill">Base: <?= h(
                                                        $s["base_governor"],
                                                ) ?></span>
                                        </td>
                                        <td class="mono users-cell"><?= h(
                                                    $s["active_users"],
                                            ) ?></td>
                                        <td class="mono">
                                            <span class="hwflag <?= $s["hw_error"]
                                                    ? "err"
                                                    : "ok" ?>">
                                                <?= $s["hw_error"]
                                                        ? "HW alert"
                                                        : "OK" ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="status-row">
                                                <div class="control">
                                                    <label>CPU cores</label>
                                                    <input type="number" min="<?= SMT
                                                            ? 2
                                                            : 1 ?>" max="64"
                                                           step="<?= SMT
                                                                   ? 2
                                                                   : 1 ?>"
                                                           value="<?= h(
                                                                   count(
                                                                           $s["allocated_cores"],
                                                                   ) ?:
                                                                           (SMT
                                                                                   ? 2
                                                                                   : 1),
                                                           ) ?>"
                                                           class="cores-input">
                                                    <div class="hint">Adjust the isolated CPU count.</div>
                                                </div>
                                                <div class="control">
                                                    <label>Governor</label>
                                                    <select class="gov-select">
                                                        <?php foreach (
                                                                [
                                                                        "powersave",
                                                                        "conservative",
                                                                        "ondemand",
                                                                        "schedutil",
                                                                        "performance",
                                                                ]
                                                                as $g
                                                        ): ?>
                                                            <option value="<?= h(
                                                                    $g,
                                                            ) ?>" <?= $g ===
                                                            $s["base_governor"]
                                                                    ? "selected"
                                                                    : "" ?>><?= h($g) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <div class="hint">Sets the floor governor policy.</div>
                                                </div>
                                                <div class="control">
                                                    <label>Apply</label>
                                                    <button class="btn primary apply-btn"
                                                            data-tenant="<?= h(
                                                                    $s["tenant"],
                                                            ) ?>">Apply
                                                    </button>
                                                    <div class="msg"></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="card panel chart-shell">
                    <div class="chart-top">
                        <div>
                            <h2 style="margin:0;font-size:.9rem;text-transform:uppercase;letter-spacing:.1em;color:var(--muted)">
                                Energy history</h2>
                            <p class="small">Last 24 hours per tenant, with both dynamic and fixed energy
                                consumption.</p>
                        </div>
                        <div class="legend">
                            <span><i class="dot dyn"></i>Dynamic</span>
                            <span><i class="dot fix"></i>Fixed</span>
                        </div>
                    </div>
                    <div class="control"
                         style="margin-bottom:12px;display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap">
                        <div style="min-width:240px;flex:1">
                            <label style="display:block;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">Choose
                                a tenant</label>
                            <select id="chartTenant" class="select"></select>
                        </div>
                        <div class="small" id="chartSummary"></div>
                    </div>
                    <div class="chart-box">
                        <canvas id="powerChart"></canvas>
                    </div>
                </section>
            </div>

            <section class="card panel" style="margin-top:16px">
                <div class="section-title">
                    <div>
                        <h2>Weekly energy cost estimate</h2>
                        <p>Costs are computed from tenant energy records and the public energy price available at record
                            time.</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="table-small">
                        <thead>
                            <tr>
                                <th>Tenant</th>
                                <th>Week start</th>
                                <th>Energy</th>
                                <th>Estimated cost</th>
                            </tr>
                        </thead>
                        <tbody id="weeklyBody">
                            <?php foreach ($weeklyRows as $w): ?>
                                <tr>
                                    <td class="mono"><?= h($w["tenant"]) ?></td>
                                    <td class="mono"><?= h(
                                                $w["week_start"],
                                        ) ?></td>
                                    <td class="mono"><?= h(
                                                number_format(
                                                        (float)$w["energy_kwh"],
                                                        3,
                                                        ",",
                                                        ".",
                                                ),
                                        ) ?>kWh
                                    </td>
                                    <td class="mono">
                                        € <?= h(
                                                number_format(
                                                        (float)$w["estimated_cost_eur"],
                                                        2,
                                                        ",",
                                                        ".",
                                                ),
                                        ) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <script>
            (() => {
                const csrf = <?= json_encode(
                        $_SESSION["resource_control_csrf"],
                        JSON_UNESCAPED_SLASHES,
                ) ?>;
                const tenants = <?= json_encode(
                        $tenantKeys,
                        JSON_UNESCAPED_SLASHES,
                ) ?>;
                const themeBtn = document.getElementById('themeBtn');
                const statusBody = document.getElementById('statusBody');
                const weeklyBody = document.getElementById('weeklyBody');
                const chartSelect = document.getElementById('chartTenant');
                const chartSummary = document.getElementById('chartSummary');
                const lastUpdate = document.getElementById('lastUpdate');
                const energyPriceEl = document.getElementById('energyPrice');

                let theme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', theme);

                themeBtn.addEventListener('click', () => {
                    theme = theme === 'dark' ? 'light' : 'dark';
                    document.documentElement.setAttribute('data-theme', theme);
                    if (window.powerChart) window.powerChart.update();
                });

                chartSelect.innerHTML = tenants.map(t => `<option value="${t}">${t}</option>`).join('');
                if (!chartSelect.value && tenants.length) {
                    chartSelect.value = tenants[0];
                }

                const fmt = v => new Intl.NumberFormat('it-IT', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }).format(Number(v || 0));
                const fmt3 = v => new Intl.NumberFormat('it-IT', {
                    minimumFractionDigits: 3,
                    maximumFractionDigits: 3
                }).format(Number(v || 0));
                const esc = s => String(s ?? '').replace(/[&<>"]|'/g, m => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[m]));

                const rowState = new Map();

                function rememberState() {
                    document.querySelectorAll('tr[data-tenant]').forEach(tr => {
                        rowState.set(tr.dataset.tenant, {
                            cores: tr.querySelector('.cores-input')?.value ?? '1',
                            governor: tr.querySelector('.gov-select')?.value ?? 'powersave'
                        });
                    });
                }

                function applyState(tr, tenant) {
                    const saved = rowState.get(tenant);
                    if (!saved) return;
                    const cores = tr.querySelector('.cores-input');
                    const governor = tr.querySelector('.gov-select');
                    if (cores) cores.value = saved.cores;
                    if (governor) governor.value = saved.governor;
                }

                function updateTenantRow(tr, tenantData) {
                    tr.querySelector('.gov-pill').textContent = tenantData.current_governor || 'unknown';
                    tr.querySelector('.base-pill').textContent = `Base: ${tenantData.base_governor || 'unknown'}`;
                    tr.querySelector('.users-cell').textContent = String(tenantData.active_users ?? 0);
                    tr.querySelector('.hwflag').textContent = tenantData.hw_error ? 'HW alert' : 'OK';
                    tr.querySelector('.hwflag').className = `hwflag ${tenantData.hw_error ? 'err' : 'ok'}`;
                    tr.querySelector('.cores-cell').innerHTML = `${(tenantData.allocated_cores || []).join(', ')}<span class="sub">Fronthaul core: ${esc(tenantData.fronthaul_core)}</span>`;
                    applyState(tr, tenantData.tenant);
                }

                function currentColors() {
                    const cs = getComputedStyle(document.documentElement);
                    return {
                        grid: cs.getPropertyValue('--surface2').trim(),
                        text: cs.getPropertyValue('--muted').trim()
                    };
                }

                const initialSeries = <?= json_encode(
                        $series,
                        JSON_UNESCAPED_SLASHES,
                ) ?>;
                const ctx = document.getElementById('powerChart').getContext('2d');

                const powerChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: initialSeries.labels,
                        datasets: [
                            {
                                label: 'Dynamic kWh',
                                data: initialSeries.dynamic,
                                borderColor: '#0b6b74',
                                backgroundColor: 'rgba(11,107,116,.12)',
                                tension: .3,
                                fill: true,
                                pointRadius: 2,
                                pointHoverRadius: 4
                            },
                            {
                                label: 'Fixed kWh',
                                data: initialSeries.fixed,
                                borderColor: '#7c3aed',
                                backgroundColor: 'rgba(124,58,237,.10)',
                                tension: .3,
                                fill: true,
                                pointRadius: 2,
                                pointHoverRadius: 4
                            }
                        ]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: {legend: {display: false}, tooltip: {mode: 'index', intersect: false}},
                        scales: {
                            x: {ticks: {color: currentColors().text}, grid: {color: currentColors().grid}},
                            y: {
                                beginAtZero: true,
                                ticks: {color: currentColors().text},
                                grid: {color: currentColors().grid}
                            }
                        }
                    }
                });
                window.powerChart = powerChart;

                async function loadSeries(tenant) {
                    const res = await fetch(`?action=series&tenant=${encodeURIComponent(tenant)}`, {
                        credentials: 'same-origin',
                        headers: {'Accept': 'application/json'}
                    });
                    const j = await res.json();
                    if (!j.ok) throw new Error(j.message || 'Failed');
                    return j.series;
                }

                async function refreshChart() {
                    const tenant = chartSelect.value;
                    if (!tenant) return;
                    const series = await loadSeries(tenant);
                    powerChart.data.labels = series.labels;
                    powerChart.data.datasets[0].data = series.dynamic;
                    powerChart.data.datasets[1].data = series.fixed;
                    powerChart.update('none');
                    chartSummary.textContent = `Tenant ${tenant} · last 24 hours`;
                }

                async function refreshAll() {
                    rememberState();
                    const [statusRes, weeklyRes] = await Promise.all([
                        fetch('?action=status', {credentials: 'same-origin', headers: {'Accept': 'application/json'}}),
                        fetch('?action=weekly', {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
                    ]);
                    const statusJson = await statusRes.json();
                    const weeklyJson = await weeklyRes.json();

                    if (statusJson.ok) {
                        const tenantsData = statusJson.tenants || [];
                        const totals = statusJson.totals || {dynamic: 0, fixed: 0, cores: 0, users: 0};

                        document.getElementById('kpiCores').textContent = String(totals.cores);
                        document.getElementById('kpiUsers').textContent = String(totals.users);
                        document.getElementById('kpiDyn').textContent = fmt(totals.dynamic);
                        document.getElementById('kpiFix').textContent = fmt(totals.fixed);

                        energyPriceEl.textContent = `€ ${fmt3(statusJson.price?.eur_per_kwh ?? 0.18)}/kWh`;
                        lastUpdate.textContent = `Updated ${new Date().toLocaleTimeString('it-IT')}`;

                        tenantsData.forEach(t => {
                            const tr = document.querySelector(`tr[data-tenant="${CSS.escape(t.tenant)}"]`);
                            if (tr) updateTenantRow(tr, t);
                        });
                    }

                    if (weeklyJson.ok) {
                        weeklyBody.innerHTML = (weeklyJson.rows || []).map(w =>
                            `<tr>
                                <td class="mono">${esc(w.tenant)}</td>
                                <td class="mono">${esc(w.week_start)}</td>
                                <td class="mono">${fmt3(w.energy_kwh)} kWh</td>
                                <td class="mono">€ ${fmt(w.estimated_cost_eur)}</td>
                            </tr>`
                        ).join('');
                    }
                    await refreshChart();
                }

                document.querySelectorAll('.apply-btn').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        const tr = btn.closest('tr');
                        const tenant = btn.dataset.tenant;
                        const cores = tr.querySelector('.cores-input').value;
                        const governor = tr.querySelector('.gov-select').value;
                        const msg = tr.querySelector('.msg');

                        btn.disabled = true;
                        msg.className = 'msg';
                        msg.textContent = 'Submitting request…';

                        try {
                            const post = async (action, body) => {
                                const res = await fetch(window.location.href, {
                                    method: 'POST', credentials: 'same-origin',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded',
                                        'X-Requested-With': 'XMLHttpRequest',
                                        'Accept': 'application/json'
                                    },
                                    body: new URLSearchParams({
                                        action,
                                        csrf_token: csrf,
                                        tenant_id: tenant, ...body
                                    }).toString()
                                });
                                const raw = await res.text();
                                let json;
                                try {
                                    json = raw ? JSON.parse(raw) : null;
                                } catch (_) {
                                    json = {ok: res.ok, message: raw || `HTTP ${res.status}`};
                                }
                                if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
                                return json;
                            };

                            await post('scale', {target_cores: String(cores)});
                            await post('governor', {governor});

                            msg.className = 'msg ok';
                            msg.textContent = 'Requests submitted successfully';
                            setTimeout(() => refreshAll().catch(() => {
                            }), 500);
                        } catch (e) {
                            msg.className = 'msg err';
                            msg.textContent = e.message || 'Request failed';
                        } finally {
                            btn.disabled = false;
                        }
                    });
                });

                const REFRESH_INTERVAL = 5000;
                chartSelect.addEventListener('change', () => refreshChart().catch(() => {
                }));
                refreshAll().catch(() => {
                });
                setInterval(() => refreshAll().catch(() => {
                }), REFRESH_INTERVAL);
            })();
        </script>
    </body>
</html>
