<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

header('Content-Type: application/json; charset=utf-8');

/*
  LOCAL PHP + HOSTINGER DATABASE

  DB_HOST:
  Hostinger hPanel -> Databases -> Remote MySQL kısmındaki MySQL hostname.
  localhost yazma, çünkü PHP localde ama DB Hostinger'da.
*/

$DB_HOST = 'srv1174.hstgr.io'; // örn: srv1234.hstgr.io
$DB_PORT = 3306;
$DB_NAME = 'u563036210_battlebots';
$DB_USER = 'u563036210_battlebotsroot';
$DB_PASS = 'Benkomando123';

try {
    $dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";

    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
        'error' => $e->getMessage()
    ]);

    exit;
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

function requireUser() {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse([
            'success' => false,
            'message' => 'User is not logged in'
        ], 401);
    }

    return (int)$_SESSION['user_id'];
}

function requireAdmin() {
    if (empty($_SESSION['is_admin'])) {
        jsonResponse([
            'success' => false,
            'message' => 'Admin is not logged in'
        ], 401);
    }

    return (int)($_SESSION['admin_id'] ?? 0);
}
