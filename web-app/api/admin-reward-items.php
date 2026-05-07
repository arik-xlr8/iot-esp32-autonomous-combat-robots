<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reward-items-store.php';

function getAdminRewardItems($pdo) {
    ensureRewardItemsTable($pdo);

    $stmt = $pdo->query("
        SELECT item_id, item_key, item_name, price, stock, active, sort_order
        FROM reward_items
        ORDER BY sort_order ASC, item_id ASC
    ");

    return array_map(function ($item) {
        return [
            'itemId' => (int)$item['item_id'],
            'key' => $item['item_key'],
            'name' => $item['item_name'],
            'price' => (int)$item['price'],
            'stock' => (int)$item['stock'],
            'active' => (bool)$item['active'],
            'sortOrder' => (int)$item['sort_order']
        ];
    }, $stmt->fetchAll());
}

try {
    requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        jsonResponse([
            'success' => true,
            'items' => getAdminRewardItems($pdo)
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse([
            'success' => false,
            'message' => 'Only GET and POST methods are allowed'
        ], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input) || !isset($input['items']) || !is_array($input['items'])) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid reward items payload'
        ], 400);
    }

    $pdo->beginTransaction();

    foreach ($input['items'] as $item) {
        $itemKey = $item['key'] ?? '';
        $price = (int)($item['price'] ?? 0);
        $stock = (int)($item['stock'] ?? 0);

        if (!in_array($itemKey, ['water', 'cokonat', 'toblerone'], true)) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Invalid item key'], 400);
        }

        if ($price < 0 || $stock < 0) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Price and stock must be zero or greater'], 400);
        }

        $stmt = $pdo->prepare("
            UPDATE reward_items
            SET price = ?, stock = ?
            WHERE item_key = ?
        ");
        $stmt->execute([$price, $stock, $itemKey]);
    }

    $pdo->commit();

    jsonResponse([
        'success' => true,
        'message' => 'Reward items updated',
        'items' => getAdminRewardItems($pdo)
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update reward items',
        'error' => $e->getMessage()
    ]);
    exit;
}
