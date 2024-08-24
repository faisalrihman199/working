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

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$id = isset($input['id']) ? (int)$input['id'] : 0;

if ($id <= 0) {
    echo json_encode(['success'=>false,'message'=>'Invalid id']);
    exit;
}

try {
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("DELETE FROM orders WHERE id=:id AND user_id=:uid");
    $stmt->execute([':id'=>$id, ':uid'=>(int)$_SESSION['user_id']]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Order not found or not yours']);
    }
} catch (PDOException $e) {
    error_log('delete_order error: '.$e->getMessage());
    echo json_encode(['success'=>false,'message'=>'Database error']);
}
