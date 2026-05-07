<?php
require_once __DIR__ . '/config.php';

try {
    requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            jsonResponse([
                'success' => false,
                'message' => 'Invalid JSON body'
            ], 400);
        }

        $orderId = (int)($input['orderId'] ?? 0);
        $action = $input['action'] ?? '';

        if ($orderId <= 0 || !in_array($action, ['deliver', 'cancel'], true)) {
            jsonResponse([
                'success' => false,
                'message' => 'Invalid reward action'
            ], 400);
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT *
            FROM reward_orders
            WHERE order_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Reward order not found'], 404);
        }

        if ($order['status'] !== 'pending') {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Only pending orders can be changed'], 400);
        }

        if ($action === 'deliver') {
            $stmt = $pdo->prepare("UPDATE reward_orders SET status = 'delivered' WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $message = 'Reward confirmed';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET coin_balance = coin_balance + ? WHERE user_id = ?");
            $stmt->execute([(int)$order['price'], (int)$order['user_id']]);

            $stmt = $pdo->prepare("UPDATE reward_items SET stock = stock + 1 WHERE item_key = ?");
            $stmt->execute([$order['item_key']]);

            $stmt = $pdo->prepare("UPDATE reward_orders SET status = 'cancelled' WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $message = 'Reward cancelled, stock restored, and coins refunded';
        }

        $pdo->commit();

        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse([
            'success' => false,
            'message' => 'Only GET and POST methods are allowed'
        ], 405);
    }

    $stmt = $pdo->query("
        SELECT ro.*, u.username
        FROM reward_orders ro
        INNER JOIN users u ON u.user_id = ro.user_id
        WHERE ro.status = 'pending'
        ORDER BY ro.created_at DESC
        LIMIT 30
    ");
    $orders = $stmt->fetchAll();

    jsonResponse([
        'success' => true,
        'message' => $message ?? null,
        'orders' => array_map(function ($order) {
            return [
                'orderId' => (int)$order['order_id'],
                'username' => $order['username'],
                'itemName' => $order['item_name'],
                'price' => (int)$order['price'],
                'status' => $order['status'],
                'createdAt' => $order['created_at']
            ];
        }, $orders)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load rewards',
        'error' => $e->getMessage()
    ]);
    exit;
}
