<?php
require __DIR__ . "/src/bootstrap.php";

use NHMP\Auth;
use NHMP\Database;
use NHMP\DashboardService;
use NHMP\RedisCache;

const MIN_LIMIT_PCT = 1;
const MAX_LIMIT_PCT = 95;
const MIN_DURATION = 2;
const MAX_DURATION = 1440;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// CRSF token
if (empty($_SESSION["traffic_control_csrf"])) {
    $_SESSION["traffic_control_csrf"] = bin2hex(random_bytes(32));
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

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

function fetchTenantMappings(array $tenantMap): array
{
    $redis = RedisCache::client();
    if (!$redis) {
        return [];
    }

    $rows = [];

    foreach ($tenantMap as $plmn => $subnet) {
        $keys = $redis->keys($plmn . ":*:mapping");

        foreach ($keys as $key) {
            $data = $redis->hGetAll($key);
            if (!$data) {
                continue;
            }

            $parts = explode(":", $key);
            if (count($parts) < 3) {
                continue;
            }

            $keyPlmn = trim((string)$parts[0]);
            $keyRnti = trim((string)$parts[1]);

            $amfId = trim((string)($data["amf_id"] ?? ""));
            $ranUeId = trim((string)($data["ran_ue_id"] ?? ""));
            $imsi = trim((string)($data["IMSI"] ?? ""));
            $ipv4 = trim((string)($data["IPv4"] ?? ""));
            $rnti = trim((string)($data["RNTI"] ?? $keyRnti));

            if ($keyPlmn === "" || $ipv4 === "" || !isValidIpv4($ipv4)) {
                continue;
            }

            if (!isset($tenantMap[$keyPlmn])) {
                continue;
            }

            if (!ipInCidr($ipv4, $subnet)) {
                continue;
            }

            $rows[] = [
                    "redis_key" => $key,
                    "tenant" => $keyPlmn,
                    "subnet" => $subnet,
                    "imsi" => $imsi,
                    "amf_id" => $amfId,
                    "ran_ue_id" => $ranUeId,
                    "rnti" => $rnti,
                    "ipv4" => $ipv4,
            ];
        }
    }

    usort($rows, static function (array $a, array $b) {
        return [$a["tenant"], $a["imsi"], $a["ipv4"], $a["amf_id"]] <=> [
                        $b["tenant"],
                        $b["imsi"],
                        $b["ipv4"],
                        $b["amf_id"],
                ];
    });

    return $rows;
}

function buildTrafficPayload(array $user): array
{
    $tenantMap = authorizedTenantsForUser((string)$user["id"]);
    $dashboard = DashboardService::payloadForUser((string)$user["id"]);
    $liveKpm = $dashboard["live_metrics"]["kpm"] ?? [];
    $mappings = fetchTenantMappings($tenantMap);

    $rows = [];
    foreach ($mappings as $mapping) {
        $amfId = $mapping["amf_id"];
        $kpm = $amfId !== "" ? $liveKpm[$amfId] ?? [] : [];

        $dlKbps = (float)($kpm["UEThpDl_kbps"] ?? 0);
        $ulKbps = (float)($kpm["UEThpUl_kbps"] ?? 0);

        $rows[] = [
                "tenant" => $mapping["tenant"],
                "subnet" => $mapping["subnet"],
                "imsi" => $mapping["imsi"],
                "amf_id" => $mapping["amf_id"],
                "ran_ue_id" => $mapping["ran_ue_id"],
                "rnti" => $mapping["rnti"],
                "ipv4" => $mapping["ipv4"],
                "prb_dl_pct" => (float)($kpm["PrbTotDl_pct"] ?? 0),
                "prb_ul_pct" => (float)($kpm["PrbTotUl_pct"] ?? 0),
                "vol_dl_mb" => (float)($kpm["PdcpSduVolumeDL_Mb"] ?? 0),
                "vol_ul_mb" => (float)($kpm["PdcpSduVolumeUL_Mb"] ?? 0),
                "thp_dl_mbps" => $dlKbps / 1000,
                "thp_ul_mbps" => $ulKbps / 1000,
        ];
    }

    return [
            "viewer" => $dashboard["viewer"] ?? $user,
            "tenants" => array_keys($tenantMap),
            "tenant_map" => $tenantMap,
            "rows" => $rows,
            "kpis" => [
                    "ue_count" => count($rows),
                    "tenant_count" => count($tenantMap),
                    "total_dl_mbps" => array_sum(array_column($rows, "thp_dl_mbps")),
                    "total_ul_mbps" => array_sum(array_column($rows, "thp_ul_mbps")),
            ],
            "_meta" => [
                    "generated_at" => gmdate("c"),
                    "cache" => $dashboard["_meta"]["cache"] ?? "miss",
            ],
    ];
}

function allowedIpsForUser(array $user): array
{
    $payload = buildTrafficPayload($user);
    $allowed = [];

    foreach ($payload["rows"] ?? [] as $row) {
        $ip = trim((string)($row["ipv4"] ?? ""));
        $tenant = trim((string)($row["tenant"] ?? ""));
        $subnet = trim((string)($row["subnet"] ?? ""));

        if ($ip === "" || $tenant === "" || $subnet === "") {
            continue;
        }

        if (!isValidIpv4($ip)) {
            continue;
        }

        if (!ipInCidr($ip, $subnet)) {
            continue;
        }

        $allowed[$ip] = [
                "tenant" => $tenant,
                "subnet" => $subnet,
        ];
    }

    return $allowed;
}

function forwardTrafficControl(
        string $ip,
        int    $limitPct,
        int    $durationMinutes,
): array
{
    $url =
            "http://apache-internal-svc.apache-internal.svc.cluster.local/api/traffic_control.php";

    $postFields = http_build_query([
            "ip" => $ip,
            "limit_pct" => $limitPct,
            "duration_minutes" => $durationMinutes,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                    "Content-Type: application/x-www-form-urlencoded",
                    "Accept: application/json, text/plain, */*",
            ],
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($raw === false) {
        return [
                "ok" => false,
                "status" => 502, // Bad gateway
                "message" =>
                        "Connection error while calling the internal service: " .
                        $curlError,
        ];
    }

    $decoded = json_decode($raw, true);

    if ($status >= 200 && $status < 300) {
        return [
                "ok" => true,
                "status" => $status,
                "payload" => is_array($decoded)
                        ? $decoded
                        : [
                                "raw" => $raw,
                                "content_type" => $contentType,
                        ],
        ];
    }

    return [
            "ok" => false,
            "status" => $status ?: 502,
            "message" => is_array($decoded)
                    ? (string)($decoded["message"] ?? "Request rejected")
                    : (trim($raw) !== ""
                            ? trim($raw)
                            : "Request rejected"),
            "content_type" => $contentType,
    ];
}

$user = Auth::requireUser();

if (
        $_SERVER["REQUEST_METHOD"] === "POST" &&
        ($_POST["action"] ?? "") === "limit"
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

    $csrf = (string)($_POST["csrf_token"] ?? "");
    $ip = trim((string)($_POST["ip"] ?? ""));
    $limitPct = (int)($_POST["limit_pct"] ?? 0);
    $durationMinutes = (int)($_POST["duration_minutes"] ?? 0);

    if (!hash_equals($_SESSION["traffic_control_csrf"], $csrf)) {
        jsonResponse(["ok" => false, "message" => "Invalid CSRF token"], 403);
    }

    if (!isValidIpv4($ip)) {
        jsonResponse(["ok" => false, "message" => "Invalid IPv4 address"], 422);
    }

    if ($limitPct < MIN_LIMIT_PCT || $limitPct > MAX_LIMIT_PCT) {
        jsonResponse(
                ["ok" => false, "message" => "Invalid reduction percentage"],
                422,
        );
    }

    if ($durationMinutes < MIN_DURATION || $durationMinutes > MAX_DURATION) {
        jsonResponse(
                [
                        "ok" => false,
                        "message" => "Duration must be between 2 minutes and 24 hours",
                ],
                422,
        );
    }

    $allowedIps = allowedIpsForUser($user);
    if (!isset($allowedIps[$ip])) {
        jsonResponse(
                [
                        "ok" => false,
                        "message" =>
                                "IP address is not authorized for the current user",
                ],
                403,
        );
    }

    $tenant = $allowedIps[$ip]["tenant"];
    $subnet = $allowedIps[$ip]["subnet"];

    if (!ipInCidr($ip, $subnet)) {
        jsonResponse(
                [
                        "ok" => false,
                        "message" =>
                                "IP address is outside the allowed subnet for tenant " .
                                $tenant,
                ],
                403,
        );
    }

    $response = forwardTrafficControl($ip, $limitPct, $durationMinutes);

    if (!$response["ok"]) {
        jsonResponse($response, $response["status"] ?? 502);
    }

    jsonResponse([
            "ok" => true,
            "message" => "Traffic control request submitted successfully",
            "response" => $response["payload"] ?? null,
    ]);
}

$action = $_GET["action"] ?? null;

if ($action === "data") {
    jsonResponse(buildTrafficPayload($user));
}

if ($action === "stream") {
    header("Content-Type: text/event-stream");
    header("Cache-Control: no-cache");
    header("Connection: keep-alive");
    @ob_end_flush();
    @ob_implicit_flush(true);

    for ($i = 0; $i < 12; $i++) {
        $payload = buildTrafficPayload($user);
        echo "event: traffic\n";
        echo "data: " . json_encode($payload) . "\n\n";
        flush();
        sleep(5);
    }
    exit();
}

$payload = buildTrafficPayload($user);
$rows = $payload["rows"] ?? [];
$tenants = $payload["tenants"] ?? [];
$totalDl = (float)($payload["kpis"]["total_dl_mbps"] ?? 0);
$totalUl = (float)($payload["kpis"]["total_ul_mbps"] ?? 0);
?>
<!doctype html>
<html lang="en" data-theme="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Traffic Control</title>
        <link rel="preconnect" href="https://api.fontshare.com">
        <link href="https://api.fontshare.com/v2/css?f[]=satoshi@400,500,700,900&display=swap" rel="stylesheet">
        <style>
            :root, [data-theme="light"] {
                --text-xs: clamp(.75rem, .7rem + .25vw, .875rem);
                --text-sm: clamp(.875rem, .8rem + .35vw, 1rem);
                --text-base: clamp(1rem, .95rem + .25vw, 1.125rem);
                --text-xl: clamp(1.5rem, 1.2rem + 1.25vw, 2.25rem);
                --space-2: .5rem;
                --space-3: .75rem;
                --space-4: 1rem;
                --space-5: 1.25rem;
                --space-6: 1.5rem;
                --space-8: 2rem;
                --space-10: 2.5rem;
                --color-bg: #f7f6f2;
                --color-surface: #fbfbf9;
                --color-surface-2: #f3f0ec;
                --color-text: #28251d;
                --color-text-muted: #6f6d66;
                --color-primary: #01696f;
                --color-primary-2: #dbe8e7;
                --color-success: #437a22;
                --color-error: #a13544;
                --shadow-sm: 0 1px 2px rgba(40, 37, 29, .06);
                --radius-md: .9rem;
                --radius-lg: 1.25rem;
                --radius-full: 999px;
                --font-body: 'Satoshi', system-ui, sans-serif
            }

            [data-theme="dark"] {
                --color-bg: #171614;
                --color-surface: #1c1b19;
                --color-surface-2: #22211f;
                --color-text: #ece8df;
                --color-text-muted: #afaaa0;
                --color-primary: #4f98a3;
                --color-primary-2: #253336;
                --color-success: #76b85d;
                --color-error: #dd6974;
                --shadow-sm: 0 1px 2px rgba(0, 0, 0, .2)
            }

            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0
            }

            html, body {
                min-height: 100%
            }

            body {
                font-family: var(--font-body);
                font-size: var(--text-base);
                color: var(--color-text);
                background: radial-gradient(circle at top right, color-mix(in srgb, var(--color-primary) 8%, transparent), transparent 32%), var(--color-bg)
            }

            button, input, select {
                font: inherit;
                color: inherit
            }

            button {
                border: 0;
                background: none;
                cursor: pointer
            }

            .page {
                max-width: 1680px;
                margin: 0 auto;
                padding: var(--space-6)
            }

            .header {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: var(--space-4);
                margin-bottom: var(--space-6)
            }

            .title h1 {
                font-size: var(--text-xl);
                line-height: 1.05
            }

            .title p {
                margin-top: var(--space-2);
                color: var(--color-text-muted);
                max-width: 90ch
            }

            .actions {
                display: flex;
                gap: var(--space-3);
                align-items: center;
                flex-wrap: wrap
            }

            .btn {
                min-height: 44px;
                padding: 0 var(--space-4);
                border-radius: var(--radius-full);
                border: 1px solid color-mix(in srgb, var(--color-text) 10%, transparent);
                background: var(--color-surface)
            }

            .btn.primary {
                background: var(--color-primary);
                color: #fff;
                border-color: transparent
            }

            .card {
                background: color-mix(in srgb, var(--color-surface) 92%, transparent);
                border: 1px solid color-mix(in srgb, var(--color-text) 10%, transparent);
                border-radius: var(--radius-lg);
                box-shadow: var(--shadow-sm)
            }

            .grid {
                display: grid;
                gap: var(--space-5)
            }

            .kpis {
                grid-template-columns:repeat(4, minmax(0, 1fr));
                margin-bottom: var(--space-6)
            }

            .kpi {
                padding: var(--space-5)
            }

            .kpi label {
                font-size: var(--text-xs);
                text-transform: uppercase;
                letter-spacing: .08em;
                color: var(--color-text-muted)
            }

            .kpi strong {
                display: block;
                margin-top: var(--space-3);
                font-size: clamp(1.6rem, 1.4rem + 1vw, 2.2rem);
                line-height: 1;
                font-variant-numeric: tabular-nums
            }

            .muted {
                color: var(--color-text-muted)
            }

            .pill {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: .38rem .7rem;
                border-radius: 999px;
                font-size: var(--text-xs);
                background: var(--color-primary-2);
                color: var(--color-primary)
            }

            .panel {
                padding: var(--space-5)
            }

            .split {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: var(--space-4)
            }

            .toolbar {
                margin-bottom: var(--space-4)
            }

            .table-wrap {
                overflow: auto
            }

            table {
                width: 100%;
                border-collapse: collapse
            }

            th, td {
                text-align: left;
                padding: var(--space-3);
                border-bottom: 1px solid color-mix(in srgb, var(--color-text) 8%, transparent);
                font-size: var(--text-sm);
                vertical-align: middle
            }

            th {
                color: var(--color-text-muted);
                font-size: var(--text-xs);
                text-transform: uppercase;
                letter-spacing: .08em;
                position: sticky;
                top: 0;
                background: var(--color-surface);
                z-index: 1
            }

            .mono {
                font-variant-numeric: tabular-nums
            }

            .sub {
                font-size: var(--text-xs);
                color: var(--color-text-muted);
                margin-top: 4px
            }

            .bars {
                display: grid;
                gap: 8px;
                min-width: 140px
            }

            .bars .row {
                display: grid;
                gap: 4px
            }

            .bars .label {
                font-size: var(--text-xs);
                color: var(--color-text-muted)
            }

            .bars .track {
                height: 8px;
                border-radius: 999px;
                background: var(--color-surface-2);
                overflow: hidden
            }

            .bars .fill {
                height: 100%;
                border-radius: 999px
            }

            .bars .fill.dl {
                background: linear-gradient(90deg, var(--color-primary), color-mix(in srgb, var(--color-primary) 55%, white))
            }

            .bars .fill.ul {
                background: linear-gradient(90deg, #7c3aed, #c4b5fd)
            }

            .throughput {
                min-width: 140px
            }

            .control-cell {
                min-width: 340px
            }

            .control-stack {
                display: grid;
                gap: 10px
            }

            .control-line {
                display: grid;
                grid-template-columns:minmax(160px, 1fr) 118px auto;
                gap: 10px;
                align-items: center
            }

            .range-shell {
                display: grid;
                grid-template-columns:1fr auto;
                align-items: center;
                gap: 10px;
                padding: 10px 12px;
                border-radius: var(--radius-full);
                background: var(--color-surface-2)
            }

            input[type="range"] {
                width: 100%;
                accent-color: var(--color-primary)
            }

            .range-value {
                min-width: 48px;
                text-align: right;
                font-weight: 700
            }

            .duration-select {
                min-height: 42px;
                padding: 0 14px;
                border-radius: var(--radius-full);
                border: 1px solid color-mix(in srgb, var(--color-text) 10%, transparent);
                background: var(--color-surface);
                width: 100%
            }

            .hint {
                font-size: var(--text-xs);
                color: var(--color-text-muted);
                line-height: 1.4
            }

            .status {
                font-size: var(--text-xs);
                min-height: 1rem
            }

            .status.ok {
                color: var(--color-success)
            }

            .status.err {
                color: var(--color-error)
            }

            .empty {
                text-align: center;
                padding: var(--space-8);
                color: var(--color-text-muted)
            }

            code {
                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace
            }

            @media (max-width: 1220px) {
                .kpis {
                    grid-template-columns:1fr 1fr
                }

                .control-line {
                    grid-template-columns:1fr 118px
                }
            }

            @media (max-width: 940px) {
                .page {
                    padding: var(--space-5)
                }

                .header {
                    flex-direction: column;
                    align-items: flex-start
                }

                .kpis {
                    grid-template-columns:1fr
                }

                .control-cell {
                    min-width: 300px
                }

                .control-line {
                    grid-template-columns:1fr
                }

                .btn.primary {
                    width: 100%
                }
            }

            .nav-menu {
                display: flex;
                gap: var(--space-3);
                align-items: center;
                flex-wrap: wrap;
            }

            .nav-link {
                text-decoration: none;
                padding: var(--space-2) var(--space-4);
                border-radius: var(--radius-full);
                color: var(--color-text-muted);
                font-weight: 500;
                font-size: var(--text-sm);
                border: 1px solid color-mix(in srgb, var(--color-text) 10%, transparent);
                background: var(--color-surface);
                transition: all 0.2s ease;
            }

            .nav-link:hover {
                color: var(--color-text);
                border-color: color-mix(in srgb, var(--color-text) 20%, transparent);
            }

            .nav-link.active {
                background: var(--color-primary);
                color: #fff;
                border-color: transparent;
            }
        </style>
    </head>
    <body>
        <div class="page">
            <header class="header">
                <div class="title">
                    <h1>Traffic Control</h1>
                </div>
                <nav class="nav-menu">
                    <a href="index.php" class="nav-link">Overview</a>
                    <a href="prb-map.php" class="nav-link">PRB Heatmap</a>
                    <a href="traffic-control.php" class="nav-link active">Traffic Control</a>
                    <a href="resource-control.php" class="nav-link">Resource Control</a>
                </nav>
                <div class="actions">
        <span class="pill" id="viewerPill"><?= h(
                    ($payload["viewer"]["name"] ?? "") .
                    " " .
                    ($payload["viewer"]["surname"] ?? ""),
            ) ?></span>
                    <?php
                    /*
                                        <span class="pill" id="tenantPill"><?= h(
                                                    "Tenants: " . (implode(", ", $tenants) ?: "none"),
                                            ) ?></span> */
                    ?>
                    <span class="muted" id="lastUpdate">Updated just now</span>
                    <button class="btn" id="themeBtn" type="button">◐</button>
                </div>
            </header>

            <section class="grid kpis">
                <article class="card kpi">
                    <label>Active sessions</label>
                    <strong class="mono" id="kpiUe"><?= h(
                                count($rows),
                        ) ?></strong>
                </article>
                <?php
                /*
                                <article class="card kpi">
                                    <label>Authorized tenants</label>
                                    <strong class="mono" id="kpiTenants"><?= h(count($tenants)) ?></strong>
                                    <span class="muted" id="kpiTenantsList"><?= h(
                                                implode(", ", $tenants) ?: "—",
                                        ) ?></span>
                                </article>
                                */
                ?>
                <article class="card kpi">
                    <label>Total DL throughput</label>
                    <strong class="mono" id="kpiDl"><?= h(
                                number_format($totalDl, 2, ",", "."),
                        ) ?></strong>
                    <span class="muted">Mb/s</span>
                </article>
                <article class="card kpi">
                    <label>Total UL throughput</label>
                    <strong class="mono" id="kpiUl"><?= h(
                                number_format($totalUl, 2, ",", "."),
                        ) ?></strong>
                    <span class="muted">Mb/s</span>
                </article>
            </section>

            <section class="card panel">
                <div class="toolbar split">
                    <div>
                        <h2 style="font-size:var(--text-sm);text-transform:uppercase;letter-spacing:.08em;color:var(--color-text-muted)">
                            Global traffic view</h2>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>IMSI</th>
                                <th>IPv4</th>
                                <th>Tenant</th>
                                <th>PRB</th>
                                <th>Throughput</th>
                                <th>Reduction</th>
                            </tr>
                        </thead>
                        <tbody id="ueBody">
                            <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="6" class="empty">No mapping is currently available for the authorized
                                        tenants.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr
                                            data-ip="<?= h($row["ipv4"]) ?>"
                                            data-dl="<?= h(
                                                    number_format(
                                                            $row["thp_dl_mbps"],
                                                            4,
                                                            ".",
                                                            "",
                                                    ),
                                            ) ?>"
                                            data-ul="<?= h(
                                                    number_format(
                                                            $row["thp_ul_mbps"],
                                                            4,
                                                            ".",
                                                            "",
                                                    ),
                                            ) ?>"
                                    >
                                        <td class="mono">
                                            <?= h($row["imsi"]) ?>
                                            <div class="sub">Mapped subscriber</div>
                                        </td>
                                        <td class="mono">
                                            <?= h($row["ipv4"]) ?>
                                            <div class="sub"><?= h(
                                                        $row["subnet"],
                                                ) ?></div>
                                        </td>
                                        <td><span class="pill"><?= h(
                                                        $row["tenant"],
                                                ) ?></span></td>
                                        <td>
                                            <div class="bars">
                                                <div class="row">
                                                    <div class="label">DL PRB <?= h(
                                                                number_format(
                                                                        $row["prb_dl_pct"],
                                                                        0,
                                                                        ",",
                                                                        ".",
                                                                ),
                                                        ) ?>%
                                                    </div>
                                                    <div class="track">
                                                        <div class="fill dl" style="width:<?= h(
                                                                max(
                                                                        0,
                                                                        min(
                                                                                100,
                                                                                $row["prb_dl_pct"],
                                                                        ),
                                                                ),
                                                        ) ?>%"></div>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="label">UL PRB <?= h(
                                                                number_format(
                                                                        $row["prb_ul_pct"],
                                                                        0,
                                                                        ",",
                                                                        ".",
                                                                ),
                                                        ) ?>%
                                                    </div>
                                                    <div class="track">
                                                        <div class="fill ul" style="width:<?= h(
                                                                max(
                                                                        0,
                                                                        min(
                                                                                100,
                                                                                $row["prb_ul_pct"],
                                                                        ),
                                                                ),
                                                        ) ?>%"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="mono throughput">
                                            DL <?= h(
                                                    number_format(
                                                            $row["thp_dl_mbps"],
                                                            2,
                                                            ",",
                                                            ".",
                                                    ),
                                            ) ?> Mb/s
                                            <div class="sub">UL <?= h(
                                                        number_format(
                                                                $row["thp_ul_mbps"],
                                                                2,
                                                                ",",
                                                                ".",
                                                        ),
                                                ) ?> Mb/s
                                            </div>
                                        </td>
                                        <td class="control-cell">
                                            <div class="control-stack">
                                                <div class="control-line">
                                                    <div class="range-shell">
                                                        <input type="range" min="1" max="95" step="1" value="25"
                                                               class="limit-slider" aria-label="Reduction percentage">
                                                        <span class="range-value">25%</span>
                                                    </div>

                                                    <select class="duration-select" aria-label="Duration">
                                                        <option value="2">2 min</option>
                                                        <option value="5">5 min</option>
                                                        <option value="10">10 min</option>
                                                        <option value="15">15 min</option>
                                                        <option value="30" selected>30 min</option>
                                                        <option value="60">1 h</option>
                                                        <option value="120">2 h</option>
                                                        <option value="240">4 h</option>
                                                        <option value="480">8 h</option>
                                                        <option value="720">12 h</option>
                                                        <option value="1440">24 h</option>
                                                    </select>

                                                    <button type="button" class="btn primary control-btn">Apply</button>
                                                </div>

                                                <div class="hint">
                                                    Target DL: <span class="target-dl"><?= h(
                                                                number_format(
                                                                        $row["thp_dl_mbps"] * 0.75,
                                                                        2,
                                                                        ",",
                                                                        ".",
                                                                ),
                                                        ) ?></span> Mb/s ·
                                                    Target UL: <span class="target-ul"><?= h(
                                                                number_format(
                                                                        $row["thp_ul_mbps"] * 0.75,
                                                                        2,
                                                                        ",",
                                                                        ".",
                                                                ),
                                                        ) ?></span> Mb/s ·
                                                    Duration: <span class="duration-text">30 min</span>
                                                </div>

                                                <div class="status"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <script>
            (() => {
                const fmt = value => new Intl.NumberFormat('it-IT', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }).format(Number(value || 0));

                const esc = str => String(str ?? '').replace(/[&<>"']/g, m => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
                }[m]));

                const themeInitial = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', themeInitial);
                let theme = themeInitial;

                document.getElementById('themeBtn').addEventListener('click', () => {
                    theme = theme === 'dark' ? 'light' : 'dark';
                    document.documentElement.setAttribute('data-theme', theme);
                });

                const csrf = <?= json_encode(
                        $_SESSION["traffic_control_csrf"],
                        JSON_UNESCAPED_SLASHES,
                ) ?>;
                const bodyEl = document.getElementById('ueBody');
                const lastUpdateEl = document.getElementById('lastUpdate');
                let stream = null;
                let poller = null;
                const controlState = new Map();

                function durationLabel(minutes) {
                    const value = Number(minutes || 0);
                    if (value < 60) return `${value} min`;
                    if (value % 60 === 0) return `${value / 60} h`;
                    const h = Math.floor(value / 60);
                    const m = value % 60;
                    return `${h} h ${m} min`;
                }

                function rememberControlState() {
                    document.querySelectorAll('#ueBody tr[data-ip]').forEach(row => {
                        const slider = row.querySelector('.limit-slider');
                        const duration = row.querySelector('.duration-select');
                        if (slider && duration) {
                            controlState.set(row.dataset.ip, {
                                limitPct: slider.value,
                                durationMinutes: duration.value
                            });
                        }
                    });
                }

                function bar(label, value, cls) {
                    const pct = Math.max(0, Math.min(100, Number(value || 0)));
                    return `
          <div class="row">
            <div class="label">${label} ${pct.toFixed(0)}%</div>
            <div class="track"><div class="fill ${cls}" style="width:${pct}%"></div></div>
          </div>
        `;
                }

                function rowHtml(row) {
                    const saved = controlState.get(row.ipv4) || {limitPct: '25', durationMinutes: '30'};
                    const factor = (100 - Number(saved.limitPct)) / 100;

                    return `
          <tr data-ip="${esc(row.ipv4)}" data-dl="${Number(row.thp_dl_mbps || 0)}" data-ul="${Number(row.thp_ul_mbps || 0)}">
            <td class="mono">
              ${esc(row.imsi)}
              <div class="sub">Mapped subscriber</div>
            </td>
            <td class="mono">
              ${esc(row.ipv4)}
              <div class="sub">${esc(row.subnet)}</div>
            </td>
            <td><span class="pill">${esc(row.tenant)}</span></td>
            <td>
              <div class="bars">
                ${bar('DL PRB', row.prb_dl_pct, 'dl')}
                ${bar('UL PRB', row.prb_ul_pct, 'ul')}
              </div>
            </td>
            <td class="mono throughput">
              DL ${fmt(row.thp_dl_mbps)} Mb/s
              <div class="sub">UL ${fmt(row.thp_ul_mbps)} Mb/s</div>
            </td>
            <td class="control-cell">
              <div class="control-stack">
                <div class="control-line">
                  <div class="range-shell">
                    <input type="range" min="1" max="95" step="1" value="${saved.limitPct}" class="limit-slider" aria-label="Reduction percentage">
                    <span class="range-value">${saved.limitPct}%</span>
                  </div>

                  <select class="duration-select" aria-label="Duration">
                    ${[2, 5, 10, 15, 30, 60, 120, 240, 480, 720, 1440].map(v => `<option value="${v}" ${String(v) === String(saved.durationMinutes) ? 'selected' : ''}>${durationLabel(v)}</option>`).join('')}
                  </select>

                  <button type="button" class="btn primary control-btn">Apply</button>
                </div>

                <div class="hint">
                  Target DL: <span class="target-dl">${fmt(Number(row.thp_dl_mbps || 0) * factor)}</span> Mb/s ·
                  Target UL: <span class="target-ul">${fmt(Number(row.thp_ul_mbps || 0) * factor)}</span> Mb/s ·
                  Duration: <span class="duration-text">${durationLabel(saved.durationMinutes)}</span>
                </div>

                <div class="status"></div>
              </div>
            </td>
          </tr>
        `;
                }

                function attachRowHandlers(row) {
                    const slider = row.querySelector('.limit-slider');
                    const rangeValue = row.querySelector('.range-value');
                    const duration = row.querySelector('.duration-select');
                    const targetDl = row.querySelector('.target-dl');
                    const targetUl = row.querySelector('.target-ul');
                    const durationText = row.querySelector('.duration-text');
                    const button = row.querySelector('.control-btn');
                    const status = row.querySelector('.status');

                    const updateTargets = () => {
                        const baseDl = Number(row.dataset.dl || '0');
                        const baseUl = Number(row.dataset.ul || '0');
                        const pct = Number(slider.value || '0');
                        const factor = (100 - pct) / 100;
                        const durationMinutes = String(duration.value || '30');

                        controlState.set(row.dataset.ip, {
                            limitPct: String(slider.value),
                            durationMinutes
                        });

                        rangeValue.textContent = `${pct}%`;
                        targetDl.textContent = fmt(baseDl * factor);
                        targetUl.textContent = fmt(baseUl * factor);
                        durationText.textContent = durationLabel(durationMinutes);
                    };

                    slider.addEventListener('input', updateTargets);
                    duration.addEventListener('change', updateTargets);
                    updateTargets();

                    button.addEventListener('click', async () => {
                        const body = new URLSearchParams({
                            action: 'limit',
                            csrf_token: csrf,
                            ip: row.dataset.ip,
                            limit_pct: String(slider.value),
                            duration_minutes: String(duration.value)
                        });

                        button.disabled = true;
                        status.className = 'status';
                        status.textContent = 'Submitting request…';

                        try {
                            const res = await fetch(window.location.href, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'Accept': 'application/json, text/plain, */*'
                                },
                                body: body.toString()
                            });

                            const raw = await res.text();
                            let data;

                            try {
                                data = raw ? JSON.parse(raw) : null;
                            } catch (_) {
                                data = {ok: res.ok, message: raw || `HTTP ${res.status}`};
                            }

                            if (!res.ok || !data?.ok) {
                                throw new Error(data?.message || `HTTP ${res.status}`);
                            }

                            status.className = 'status ok';
                            status.textContent = data.message || 'Traffic control applied';
                            setTimeout(loadSnapshot, 800);
                        } catch (err) {
                            status.className = 'status err';
                            status.textContent = err.message || 'Request failed';
                        } finally {
                            button.disabled = false;
                        }
                    });
                }

                function bindAllRows() {
                    document.querySelectorAll('#ueBody tr[data-ip]').forEach(attachRowHandlers);
                }

                function renderSnapshot(data) {
                    rememberControlState();

                    document.getElementById('viewerPill').textContent =
                        `${data.viewer?.name ?? ''} ${data.viewer?.surname ?? ''}`.trim() || 'Viewer';

                    /* document.getElementById('tenantPill').textContent =
                        `Tenants: ${(data.tenants || []).join(', ') || 'none'}`; */

                    document.getElementById('kpiUe').textContent = String(data.kpis?.ue_count ?? 0);
                    document.getElementById('kpiTenants').textContent = String(data.kpis?.tenant_count ?? 0);
                    document.getElementById('kpiTenantsList').textContent = (data.tenants || []).join(', ') || '—';
                    document.getElementById('kpiDl').textContent = fmt(data.kpis?.total_dl_mbps ?? 0);
                    document.getElementById('kpiUl').textContent = fmt(data.kpis?.total_ul_mbps ?? 0);
                    // lastUpdateEl.textContent = `Updated ${new Date().toLocaleTimeString('en-GB')} · ${data._meta?.cache === 'redis' ? 'Redis' : 'Postgres'}`;
                    lastUpdateEl.textContent = `Updated ${new Date().toLocaleTimeString('it-IT')}`;

                    const rows = data.rows || [];
                    bodyEl.innerHTML = rows.length
                        ? rows.map(rowHtml).join('')
                        : '<tr><td colspan="6" class="empty">No mapping is currently available for the authorized tenants.</td></tr>';

                    bindAllRows();
                }

                async function loadSnapshot() {
                    const res = await fetch(`${window.location.pathname}?action=data`, {
                        credentials: 'same-origin',
                        headers: {'Accept': 'application/json'}
                    });

                    if (res.status === 401) {
                        window.location.reload();
                        return;
                    }

                    const data = await res.json();
                    renderSnapshot(data);
                }

                function startPolling() {
                    if (poller) clearInterval(poller);
                    const REFRESH_INTERVAL = 5000;
                    poller = setInterval(() => {
                        loadSnapshot().catch(() => {
                        });
                    }, REFRESH_INTERVAL);
                }

                function startRealtime() {
                    if (!window.EventSource) {
                        startPolling();
                        return;
                    }

                    try {
                        stream = new EventSource(`${window.location.pathname}?action=stream`);
                        stream.addEventListener('traffic', ev => {
                            const data = JSON.parse(ev.data);
                            renderSnapshot(data);
                        });
                        stream.onerror = () => {
                            if (stream) {
                                stream.close();
                                stream = null;
                            }
                            startPolling();
                        };
                    } catch (_) {
                        startPolling();
                    }
                }

                bindAllRows();
                startRealtime();
            })();
        </script>
    </body>
</html>
