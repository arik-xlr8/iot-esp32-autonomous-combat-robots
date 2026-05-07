<?php
require_once __DIR__ . '/config.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse([
            'success' => false,
            'message' => 'Only POST method is allowed'
        ], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid JSON body'
        ], 400);
    }

    $username = trim($input['username'] ?? '');
    $password = (string)($input['password'] ?? '');

    if ($username === '' || $password === '') {
        jsonResponse([
            'success' => false,
            'message' => 'Username and password are required'
        ], 400);
    }

    $stmt = $pdo->prepare("
        SELECT admin_id, username, password_hash
        FROM admins
        WHERE username = ?
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid admin credentials'
        ], 401);
    }

    $_SESSION['is_admin'] = true;
    $_SESSION['admin_id'] = (int)$admin['admin_id'];
    $_SESSION['admin_username'] = $admin['username'];

    jsonResponse([
        'success' => true,
        'admin' => [
            'adminId' => (int)$admin['admin_id'],
            'username' => $admin['username']
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Admin login failed',
        'error' => $e->getMessage()
    ]);
    exit;
}
