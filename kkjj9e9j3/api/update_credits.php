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
$credits = $input['credits'] ?? '';

if (empty($userId) || $credits === '') {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

try {
    // Check if user credits record exists
    $checkQuery = "SELECT id FROM user_credits WHERE user_id = :user_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':user_id', $userId);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() > 0) {
        // Update existing record
        $query = "UPDATE user_credits SET credits_limit = :credits WHERE user_id = :user_id";
    } else {
        // Create new record
        $query = "INSERT INTO user_credits (user_id, credits_limit) VALUES (:user_id, :credits)";
    }
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':credits', $credits);
    $stmt->bindParam(':user_id', $userId);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Credits updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update credits']);
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
</invoke>