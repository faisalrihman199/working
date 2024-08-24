<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Not authenticated']);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit; }

try {
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("SELECT id, user_id, name, address, phone, table_number, order_type, order_status, order_details, note, payment_status, created_at
                          FROM orders WHERE id = :id AND user_id = :uid");
    $stmt->execute([':id'=>$id, ':uid'=>(int)$_SESSION['user_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($order) echo json_encode(['success'=>true,'order'=>$order]);
    else echo json_encode(['success'=>false,'message'=>'Order not found']);
} catch (PDOException $e) {
    error_log('get_order error: '.$e->getMessage());
    echo json_encode(['success'=>false,'message'=>'Database error']);
}
