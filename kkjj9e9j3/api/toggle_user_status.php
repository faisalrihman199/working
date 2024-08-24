<?php
header('Content-Type: application/json');
session_start();

// Check admin authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

require_once '../../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
$userId = $input['user_id'] ?? '';
$status = $input['status'] ?? '';

if (empty($userId) || $status === '') {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

try {
    $query = "UPDATE users SET is_active = :status WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':user_id', $userId);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'User status updated']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update status']);
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
</invoke>