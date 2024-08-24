<?php
// Path: api/membership_plans.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Enable error reporting (for debugging, remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Only allow GET requests
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Only GET method is allowed'
        ]);
        exit;
    }

    // Include the database connection
    require_once __DIR__ . '/../config/database.php';
    $database = new Database();
    $db = $database->getConnection();

    // Get query parameters
    $userId = $_GET['user_id'] ?? null;

    if (!$userId || !is_numeric($userId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid or missing user_id'
        ]);
        exit;
    }

    // Fetch user and membership plan details
    $stmt = $db->prepare("
        SELECT
            u.id AS user_id,
            u.name AS user_name,
            u.email AS user_email,
            mp.id AS plan_id,
            mp.name AS plan_name,
            mp.price,
            mp.credits_limit,
            mp.duration_days,
            mp.features,
            mp.is_active,
            mp.created_at
        FROM users u
        LEFT JOIN membership_plans mp ON u.membership_plan = mp.id
        WHERE u.id = :user_id AND u.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        echo json_encode([
            'success' => false,
            'error' => 'User not found or inactive'
        ]);
        exit;
    }

    // Prepare the response
    $response = [
        'success' => true,
        'data' => [
            'user_id' => (int)$result['user_id'],
            'user_name' => $result['user_name'],
            'user_email' => $result['user_email'],
            'current_plan' => null
        ]
    ];

    if ($result['plan_id']) {
        $response['data']['current_plan'] = [
            'id' => (int)$result['plan_id'],
            'name' => $result['plan_name'],
            'price' => (float)$result['price'],
            'credits_limit' => (int)$result['credits_limit'],
            'duration_days' => (int)$result['duration_days'],
            'features' => $result['features'],
            'features_array' => $result['features'] ? explode(', ', $result['features']) : [],
            'is_active' => (int)$result['is_active'],
            'created_at' => $result['created_at'],
            'price_per_credit' => $result['credits_limit'] > 0
                                  ? round((float)$result['price'] / (int)$result['credits_limit'], 4)
                                  : 0
        ];
    }

    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
