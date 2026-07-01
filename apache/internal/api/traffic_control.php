<?php
// apache-internal-svc.apache-internal.svc.cluster.local/api/traffic_control.php
const MIN_LIMIT_PCT = 1;
const MAX_LIMIT_PCT = 95;
const MIN_DURATION = 2;
const MAX_DURATION = 60 * 24;

header("Content-Type: application/json; charset=utf-8");

// Parameters forwarded from our dashboard
$ip = trim((string)($_POST["ip"] ?? ""));
$limitPct = (int)($_POST["limit_pct"] ?? 0);
$durationMinutes = (int)($_POST["duration_minutes"] ?? 0);

// Input data validation
// PHP embeds a filtering function which allows for validation across a pre-defined
// set of available matchers
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Invalid IPv4 address"]);
    exit();
}

if ($limitPct < MIN_LIMIT_PCT || $limitPct > MAX_LIMIT_PCT) {
    http_response_code(401); // Unauthorized
    echo json_encode([
        "ok" => false,
        "message" => "Limit percentage must be between 1 and 95",
    ]);
    exit();
}

if ($durationMinutes < MIN_DURATION || $durationMinutes > MAX_DURATION) {
    http_response_code(401);
    echo json_encode([
        "ok" => false,
        "message" => "Duration must be between 2 minutes and 24 hours",
    ]);
    exit();
}

// JSON payload build for Python script
$fastApiUrl =
    "http://open5gs-upf-http-svc.srs72.svc.cluster.local:8000/throttle";
$payload = json_encode([
    "ip" => $ip,
    "reduction_percent" => $limitPct,
    "duration_minutes" => $durationMinutes,
]);

$ch = curl_init($fastApiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25, // the timeout must be higher than 15 to allow for the measurement cycle
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Content-Length: " . strlen($payload),
    ],
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

// Case of UPF unresponsiveness
if ($response === false) {
    http_response_code(502); // Bad Gateway
    echo json_encode([
        "ok" => false,
        "message" => "Error contacting UPF API: " . $error,
    ]);
    exit();
}

$decoded = json_decode($response, true);

// Final crafted response for our dashboard
if ($httpCode >= 200 && $httpCode < 300) {
    http_response_code($httpCode);

    // Low traffic case
    $isSkipped = isset($decoded["status"]) && $decoded["status"] === "skipped";

    echo json_encode([
        "ok" => !$isSkipped,
        "message" =>
            $decoded["message"] ?? "Traffic control operation processed",
        "details" => $decoded,
    ]);
} else {
    http_response_code($httpCode ?: 500);
    echo json_encode([
        "ok" => false,
        "message" => $decoded["detail"] ?? "UPF rejected the request",
        "upf_response" => $decoded,
    ]);
}
