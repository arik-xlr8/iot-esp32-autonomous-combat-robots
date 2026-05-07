<?php
require_once __DIR__ . '/config.php';

unset($_SESSION['is_admin'], $_SESSION['admin_id'], $_SESSION['admin_username']);

jsonResponse([
    'success' => true
]);
