<?php
// ✅ Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ✅ Always resolve paths relative to this file
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/user_credits.php';
require_once __DIR__ . '/../config/orders.php';

class Auth
{
    private $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    public function register($name, $restaurant_name, $country, $phone, $email, $password)
    {
        try {
            // Check if email already exists
            $query = "SELECT id FROM users WHERE email = :email";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(":email", $email);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                return ["success" => false, "message" => "Email already exists"];
            }

            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Insert user
            $query = "INSERT INTO users (name, restaurant_name, country, phone, email, password) 
                      VALUES (:name, :restaurant_name, :country, :phone, :email, :password)";
            $stmt = $this->db->prepare($query);

            $stmt->bindParam(":name", $name);
            $stmt->bindParam(":restaurant_name", $restaurant_name);
            $stmt->bindParam(":country", $country);
            $stmt->bindParam(":phone", $phone);
            $stmt->bindParam(":email", $email);
            $stmt->bindParam(":password", $hashed_password);

            if ($stmt->execute()) {
                $userId = (int)$this->db->lastInsertId();

                // 🔹 Create user credits
                $credits = new UserCreditsTable();
                $credits->ensureForUser($userId);

                // 🔹 Insert dummy orders for testing
                $orders = new OrdersTable();
                $orders->seedDummyForUser($userId);

                return ["success" => true, "message" => "Registration successful"];
            }

            return ["success" => false, "message" => "Registration failed"];
        } catch (PDOException $e) {
            error_log("Register error: " . $e->getMessage());
            return ["success" => false, "message" => "Database error"];
        }
    }

    public function login($email, $password)
    {
        try {
            // 1) Find the user by email
            $sql = "SELECT id, name, restaurant_name, email, password, is_admin, is_active
                FROM users
                WHERE email = :email
                LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':email', trim($email), PDO::PARAM_STR);
            $stmt->execute();

            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                return ["success" => false, "message" => "Invalid email or password"];
            }

            // 2) Verify password
            if (!password_verify($password, $user['password'])) {
                return ["success" => false, "message" => "Invalid email or password"];
            }

            // 3) Check active status
            if ((int)$user['is_active'] !== 1) {
                return [
                    "success" => false,
                    "message" => "Your account is inactive. Please contact support.",
                    "status"  => (int)$user['is_active']
                ];
            }

            // 4) Success — set session
            session_regenerate_id(true); // prevent session fixation
            $_SESSION['user_id']         = (int)$user['id'];
            $_SESSION['user_name']       = $user['name'];
            $_SESSION['restaurant_name'] = $user['restaurant_name'];
            $_SESSION['is_admin']        = (int)$user['is_admin'];

            // Optionally flag admin session (if you use it elsewhere)
            if ((int)$user['is_admin'] === 1) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username']  = $user['name'];
            }

            return [
                "success" => true,
                "message" => "Login successful",
                "status"  => (int)$user['is_active']
            ];
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            return ["success" => false, "message" => "Database error"];
        }
    }


    public function logout()
    {
        session_destroy();
        return true;
    }

    public function isLoggedIn()
    {
        return isset($_SESSION['user_id']);
    }

    public function isAdmin()
    {
        return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
    }
}
