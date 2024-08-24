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

if (empty($userId)) {
    echo json_encode(['success' => false, 'message' => 'Missing user ID']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

try {
    // Reset credits used to 0 and update last reset date
    $query = "UPDATE user_credits SET credits_used = 0, last_reset = CURRENT_DATE WHERE user_id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Credits reset successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to reset credits']);
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>