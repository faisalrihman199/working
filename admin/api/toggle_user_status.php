<?php
header('Content-Type: application/json');
require_once '../../includes/auth.php';
require_once '../../config/database.php';

$auth = new Auth();

if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

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