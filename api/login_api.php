<?php
// api/login_api.php

// --- CORS & JSON headers (adjust origin in production) ---
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');                 // change to https://yourapp.com in prod
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight quickly
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

$respond = function (int $code, array $payload) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

// --- Parse incoming JSON or form-data ---
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST; // fallback for form-data
}

$email    = isset($data['email']) ? trim((string)$data['email']) : '';
$password = isset($data['password']) ? (string)$data['password'] : '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

if ($email === '' || $password === '') {
    $respond(400, ['success' => false, 'message' => 'Email and password are required']);
}

try {
    $auth   = new Auth();
    $result = $auth->login($email, $password);

    if (!empty($result['success'])) {
        // ✅ Fetch user info directly from DB (no $_SESSION needed)
        $db   = (new Database())->getConnection();
        $stmt = $db->prepare('SELECT id, name, restaurant_name, is_admin, email FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $respond(500, ['success' => false, 'message' => 'User not found after login']);
        }

        $respond(200, [
            'success'   => true,
            'message'   => 'Login successful',
            'user'      => [
                'id'              => (int)$user['id'],
                'name'            => $user['name'],
                'restaurant_name' => $user['restaurant_name'],
                'is_admin'        => (int)$user['is_admin'],
                'email'           => $user['email'],
            ],
            'issued_at' => time()
        ]);
    } else {
        $respond(401, [
            'success' => false,
            'message' => $result['message'] ?? 'Invalid email or password'
        ]);
    }
} catch (Throwable $e) {
    error_log('login_api error: ' . $e->getMessage());
    $respond(500, ['success' => false, 'message' => 'Server error']);
}
