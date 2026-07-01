<?php
require __DIR__ . '/src/bootstrap.php';

use NHMP\Auth;
use NHMP\DashboardService;

$action = $_GET['action'] ?? 'dashboard';

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $ok = Auth::login((string)($body['email'] ?? ''), (string)($body['password'] ?? ''));
    echo json_encode(['ok' => $ok]);
    exit;
}

if ($action === 'logout') {
    Auth::logout();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'dashboard') {
    header('Content-Type: application/json; charset=utf-8');
    $user = Auth::requireUser();
    echo json_encode(DashboardService::payloadForUser($user['id']));
    exit;
}

if ($action === 'stream') {
    $user = Auth::requireUser();
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    @ob_end_flush();
    @ob_implicit_flush(true);
    for ($i = 0; $i < 12; $i++) {
        $payload = DashboardService::payloadForUser($user['id']);
        echo "event: dashboard\n";
        echo 'data: ' . json_encode($payload) . "\n\n";
        flush();
        sleep(5);
    }
    exit;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'not_found']);
