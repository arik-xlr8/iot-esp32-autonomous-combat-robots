<?php
require_once __DIR__ . '/config.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse([
            'success' => false,
            'message' => 'Only POST method is allowed'
        ], 405);
    }

    $rawBody = file_get_contents('php://input');
    $input = json_decode($rawBody, true);

    if (!is_array($input)) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid JSON body'
        ], 400);
    }

    $username = trim($input['username'] ?? '');

    if ($username === '') {
        jsonResponse([
            'success' => false,
            'message' => 'Username is required'
        ], 400);
    }

    // Türkçe karakterler byte olarak uzun sayılmasın diye mb_strlen varsa onu kullanır.
    $usernameLength = function_exists('mb_strlen')
        ? mb_strlen($username, 'UTF-8')
        : strlen($username);

    if ($usernameLength < 2 || $usernameLength > 50) {
        jsonResponse([
            'success' => false,
            'message' => 'Username must be between 2 and 50 characters'
        ], 400);
    }

    // Basit kullanıcı adı temizliği
    $cleanUsername = preg_replace('/[^a-zA-Z0-9ğüşöçıİĞÜŞÖÇ_\- ]/u', '', $username);
    $cleanUsername = trim($cleanUsername);

    if ($cleanUsername === '') {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid username'
        ], 400);
    }

    $stmt = $pdo->prepare("
        SELECT user_id, username, coin_balance
        FROM users
        WHERE username = ?
        LIMIT 1
    ");
    $stmt->execute([$cleanUsername]);
    $user = $stmt->fetch();

    if (!$user) {
        $stmt = $pdo->prepare("
            INSERT INTO users (username, coin_balance)
            VALUES (?, 100)
        ");
        $stmt->execute([$cleanUsername]);

        $userId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            SELECT user_id, username, coin_balance
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    }

    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['username'] = $user['username'];

    jsonResponse([
        'success' => true,
        'user' => [
            'userId' => (int)$user['user_id'],
            'username' => $user['username'],
            'coinBalance' => (int)$user['coin_balance']
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Login failed',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);

    exit;
}