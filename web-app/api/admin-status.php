<?php
require_once __DIR__ . '/config.php';

jsonResponse([
    'success' => true,
    'loggedIn' => !empty($_SESSION['is_admin']),
    'admin' => !empty($_SESSION['is_admin'])
        ? [
            'adminId' => (int)($_SESSION['admin_id'] ?? 0),
            'username' => ($_SESSION['admin_username'] ?? 'admin')
        ]
        : null
]);
