<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reward-items-store.php';

function getRewardSummary($pdo, $rewardItems) {
    return array_map(function ($key, $item) {
        return [
            'key' => $key,
            'name' => $item['name'],
            'price' => $item['price'],
            'stock' => $item['stock'],
            'remaining' => max(0, $item['stock'])
        ];
    }, array_keys($rewardItems), $rewardItems);
}

try {
    $userId = requireUser();
    $rewardItems = getRewardItems($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        jsonResponse([
            'success' => true,
            'items' => getRewardSummary($pdo, $rewardItems)
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse([
            'success' => false,
            'message' => 'Only GET and POST methods are allowed'
        ], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid JSON body'
        ], 400);
    }

    $itemKey = $input['itemKey'] ?? '';

    if (!isset($rewardItems[$itemKey])) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid reward item'
        ], 400);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT item_key, item_name, price, stock
        FROM reward_items
        WHERE item_key = ? AND active = 1
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$itemKey]);
    $lockedItem = $stmt->fetch();

    if (!$lockedItem) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Reward item not found'], 404);
    }

    $item = [
        'name' => $lockedItem['item_name'],
        'price' => (int)$lockedItem['price'],
        'stock' => (int)$lockedItem['stock']
    ];

    $stmt = $pdo->prepare("
        SELECT user_id, username, coin_balance
        FROM users
        WHERE user_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'User not found'], 404);
    }

    if ($item['stock'] <= 0) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'This reward is sold out'], 400);
    }

    if ((int)$user['coin_balance'] < $item['price']) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Not enough coins'], 400);
    }

    $stmt = $pdo->prepare("UPDATE users SET coin_balance = coin_balance - ? WHERE user_id = ?");
    $stmt->execute([$item['price'], $userId]);

    $stmt = $pdo->prepare("UPDATE reward_items SET stock = stock - 1 WHERE item_key = ? AND stock > 0");
    $stmt->execute([$itemKey]);

    $stmt = $pdo->prepare("
        INSERT INTO reward_orders (user_id, item_key, item_name, price, status)
        VALUES (?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([$userId, $itemKey, $item['name'], $item['price']]);

    $stmt = $pdo->prepare("
        SELECT user_id, username, coin_balance
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $updatedUser = $stmt->fetch();

    $pdo->commit();

    $rewardItems = getRewardItems($pdo);

    jsonResponse([
        'success' => true,
        'message' => $item['name'] . ' purchased',
        'user' => [
            'userId' => (int)$updatedUser['user_id'],
            'username' => $updatedUser['username'],
            'coinBalance' => (int)$updatedUser['coin_balance']
        ],
        'items' => getRewardSummary($pdo, $rewardItems)
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Reward action failed',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
}
