<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/live-refresh.php';

echo json_encode([
    'success' => true,
    'state' => readLiveRefreshState()
]);
