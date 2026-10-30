<?php
require_once __DIR__ . '/../includes/functions.php';
require_login(true);

$data = notifications_data($pdo);

json_out([
    'count' => $data['count'],
    'low' => array_map(fn($i) => [
        'id' => (int)$i['id'],
        'name' => $i['name'],
        'unit' => $i['unit'],
        'quantity_text' => qty($i['quantity']),
        'min_text' => qty($i['min_quantity']),
    ], $data['low']),
    'expiry' => array_map(fn($i) => [
        'id' => (int)$i['id'],
        'name' => $i['name'],
        'expiry_text' => expiry_text((int)$i['days_left']) . ' (' . thai_date($i['expiry_date']) . ')',
    ], $data['expiry']),
]);
