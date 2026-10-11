<?php
require_once '../../config/config.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

if ($action === 'sync') {
    $cart = $data['cart'] ?? [];
    // Store minimal cart info in session
    $_SESSION['cart'] = [];
    foreach ($cart as $id => $item) {
        $_SESSION['cart'][$id] = [
            'quantity' => (int)($item['qty'] ?? 1),
            'emoji' => $item['emoji'] ?? '🛒'
        ];
    }
    echo json_encode(['status' => 'ok', 'count' => count($cart)]);
    exit;
}

echo json_encode(['status' => 'error']);
