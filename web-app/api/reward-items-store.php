<?php

function ensureRewardItemsTable($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reward_items (
            item_id INT(11) NOT NULL AUTO_INCREMENT,
            item_key VARCHAR(50) NOT NULL,
            item_name VARCHAR(100) NOT NULL,
            price INT(11) NOT NULL DEFAULT 0,
            stock INT(11) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (item_id),
            UNIQUE KEY uq_reward_items_item_key (item_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $defaults = [
        ['water', 'Su', 300, 3, 1],
        ['cokonat', 'Cokonat', 600, 3, 2],
        ['toblerone', 'Toblerone', 2000, 1, 3]
    ];

    $stmt = $pdo->prepare("
        INSERT INTO reward_items (item_key, item_name, price, stock, active, sort_order)
        VALUES (?, ?, ?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE item_name = VALUES(item_name)
    ");

    foreach ($defaults as $item) {
        $stmt->execute($item);
    }
}

function getRewardItems($pdo, $activeOnly = true) {
    ensureRewardItemsTable($pdo);

    $where = $activeOnly ? "WHERE active = 1" : "";
    $stmt = $pdo->query("
        SELECT item_key, item_name, price, stock, active, sort_order
        FROM reward_items
        {$where}
        ORDER BY sort_order ASC, item_id ASC
    ");

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[$row['item_key']] = [
            'name' => $row['item_name'],
            'price' => (int)$row['price'],
            'stock' => (int)$row['stock'],
            'active' => (bool)$row['active'],
            'sortOrder' => (int)$row['sort_order']
        ];
    }

    return $items;
}
