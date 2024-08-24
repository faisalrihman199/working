<?php
// api/orders_since.php
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
$since  = isset($_GET['since']) ? (int)$_GET['since'] : 0;

try {
    $db = (new Database())->getConnection();
    $sql = "
        SELECT id, name,order_details, address, phone, table_number, order_type, order_status, created_at
        FROM orders
        WHERE user_id = :uid AND id > :since
        ORDER BY id ASC
        LIMIT 200
    ";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':uid',   $userId, PDO::PARAM_INT);
    $stmt->bindValue(':since', $since,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'orders' => $rows, 'max_id' => $rows ? (int)end($rows)['id'] : $since]);
} catch (PDOException $e) {
    error_log('orders_since error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
