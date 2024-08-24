<?php
// Path: api/credits.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Include the user credits class
    require_once __DIR__ . '/../config/user_credits.php';

    $method = $_SERVER['REQUEST_METHOD'];
    $userCredits = new UserCreditsTable();

    switch($method) {
        case 'GET':
            // Get user_id from query parameter
            $userId = $_GET['user_id'] ?? null;

            if (!$userId || !is_numeric($userId)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Valid user_id parameter is required',
                    'success' => false
                ]);
                exit;
            }

            // Ensure user has a credits record (creates if doesn't exist)
            $userCredits->ensureForUser((int)$userId);

            // Get credits data
            $credits = $userCredits->get((int)$userId);

            if ($credits) {
                // Convert array keys to match what Flutter expects
                $response = [
                    'id' => $credits['id'],
                    'user_id' => $credits['user_id'],
                    'credits_limit' => (int)$credits['credits_limit'],
                    'credits_used' => (int)$credits['credits_used'],
                    'period_days' => (int)$credits['period_days'],
                    'last_reset' => $credits['last_reset'],
                    'created_at' => $credits['created_at'],
                    'updated_at' => $credits['updated_at'],
                    'success' => true
                ];

                echo json_encode($response);
            } else {
                http_response_code(404);
                echo json_encode([
                    'error' => 'Credits data not found for user',
                    'success' => false
                ]);
            }
            break;

        case 'POST':
            // Handle credit consumption
            $input = json_decode(file_get_contents('php://input'), true);
            $userId = $input['user_id'] ?? null;
            $amount = $input['amount'] ?? null;

            if (!$userId || !is_numeric($userId) || !$amount || !is_numeric($amount)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Valid user_id and amount are required',
                    'success' => false
                ]);
                exit;
            }

            // Consume credits
            $result = $userCredits->consume((int)$userId, (int)$amount);

            if ($result['ok']) {
                echo json_encode([
                    'success' => true,
                    'message' => $result['message'],
                    'remaining' => $result['remaining']
                ]);
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $result['message'],
                    'remaining' => $result['remaining']
                ]);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode([
                'error' => 'Method not allowed',
                'success' => false
            ]);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error: ' . $e->getMessage(),
        'success' => false,
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}
?>
