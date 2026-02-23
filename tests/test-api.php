<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$expectedToken = (string) getenv('APCU_STATS_TEST_TOKEN');
$receivedToken = (string) ($_GET['token'] ?? '');

if ($expectedToken === '' || $receivedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

if (!extension_loaded('apcu') || !function_exists('apcu_cache_info')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'APCu extension is not available']);
    exit;
}

if (function_exists('apcu_enabled') && !apcu_enabled()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'APCu is disabled']);
    exit;
}

$action = (string) ($_GET['action'] ?? '');

if ($action === 'clear') {
    echo json_encode(['ok' => apcu_clear_cache()]);
    exit;
}

if ($action === 'seed') {
    apcu_clear_cache();
    $ok = true;
    $ok = $ok && apcu_store('app:user:1', ['id' => 1, 'name' => 'Ada'], 600);
    $ok = $ok && apcu_store('app:user:2', ['id' => 2, 'name' => 'Linus'], 600);
    $ok = $ok && apcu_store('session:alpha', 'session-data', 1200);
    $ok = $ok && apcu_store('counter:global', 42);

    echo json_encode(['ok' => $ok]);
    exit;
}

if ($action === 'exists') {
    $key = (string) ($_GET['key'] ?? '');
    if ($key === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing key']);
        exit;
    }

    echo json_encode(['ok' => true, 'exists' => apcu_exists($key)]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown action']);
