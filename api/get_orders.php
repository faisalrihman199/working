<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Correct paths
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$db = (new Database())->getConnection();
$userId = (int)$_SESSION['user_id'];

// Optional filters (?status=Pending&type=Dine-in&date=2025-09-01)
$status = $_GET['status'] ?? '';
$type   = $_GET['type'] ?? '';
$date   = $_GET['date'] ?? '';

$where  = ["user_id = :uid"];
$params = [':uid' => $userId];

if ($status !== '') { $where[] = "order_status = :status"; $params[':status'] = $status; }
if ($type   !== '') { $where[] = "order_type   = :type";   $params[':type']   = $type; }
if ($date   !== '') { $where[] = "DATE(created_at) = :dt"; $params[':dt']     = $date; }

$sql = "
    SELECT id, name, address, phone, table_number, order_type, order_status, order_details, note, payment_status, created_at
    FROM orders
    WHERE " . implode(' AND ', $where) . "
    ORDER BY created_at DESC
    LIMIT 200
";

try {
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, $k === ':uid' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'orders' => $orders]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
