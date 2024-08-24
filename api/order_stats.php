<?php
// api/order_stats.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

try {
    $db = (new Database())->getConnection();
    $sql = "
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN order_status IN ('Pending','Preparing') THEN 1 ELSE 0 END) AS pending_orders,
            SUM(CASE WHEN order_status IN ('Served','Delivered','Handover') THEN 1 ELSE 0 END) AS completed_orders,
            SUM(CASE WHEN created_at >= CURDATE() AND created_at < (CURDATE() + INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS today_orders
        FROM orders
        WHERE user_id = :uid
    ";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_orders'=>0,'pending_orders'=>0,'completed_orders'=>0,'today_orders'=>0
    ];
    echo json_encode(['success'=>true,'stats'=>$stats]);
} catch (PDOException $e) {
    error_log('order_stats error: ' . $e->getMessage());
    echo json_encode(['success'=>false,'message'=>'Database error']);
}
