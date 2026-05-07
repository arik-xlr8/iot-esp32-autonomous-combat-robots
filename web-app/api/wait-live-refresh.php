<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/live-refresh.php';

$lastEventId = (int)($_GET['lastEventId'] ?? 0);
$timeoutSeconds = 25;
$sleepMicroseconds = 250000;
$startedAt = time();

while (time() - $startedAt < $timeoutSeconds) {
    $state = readLiveRefreshState();

    if ((int)$state['eventId'] > $lastEventId) {
        echo json_encode([
            'success' => true,
            'changed' => true,
            'state' => $state
        ]);
        exit;
    }

    usleep($sleepMicroseconds);
}

echo json_encode([
    'success' => true,
    'changed' => false,
    'state' => readLiveRefreshState()
]);
