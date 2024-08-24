<?php
// api/update_order_status.php (or your current filename)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Correct paths
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Read JSON body
$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$orderId = isset($input['order_id']) ? (int)$input['order_id'] : 0;
$status  = isset($input['status']) ? trim($input['status']) : '';

if ($orderId <= 0 || $status === '') {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

// Allowed statuses (align with orders table enum)
$allowed = ['Pending','Preparing','Ready','Served','Delivered','Handover','Cancelled'];
if (!in_array($status, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
}

try {
    $db     = (new Database())->getConnection();
    $userId = (int)$_SESSION['user_id'];

    // Update only if this order belongs to the logged-in user
    $sql = "UPDATE orders
            SET order_status = :status
            WHERE id = :id AND user_id = :uid";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->bindValue(':id',     $orderId, PDO::PARAM_INT);
    $stmt->bindValue(':uid',    $userId,  PDO::PARAM_INT);

    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Order status updated']);
    } else {
        // Either not found, not owned by user, or same status already set
        echo json_encode(['success' => false, 'message' => 'No matching order to update']);
    }
} catch (PDOException $e) {
    error_log('Update order error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
