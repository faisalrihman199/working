<?php
// Database configuration
require_once __DIR__ . '/../env.php';

// Load .env file
loadEnv(__DIR__ . '/../.env');

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $charset;

    public function __construct() {
        $this->host     = $_ENV['DB_HOST']    ?? 'localhost';
        $this->db_name  = $_ENV['DB_NAME']    ?? '';
        $this->username = $_ENV['DB_USER']    ?? 'root';
        $this->password = $_ENV['DB_PASS']    ?? '';
        $this->charset  = $_ENV['DB_CHARSET'] ?? 'utf8mb4';
    }

    public function getConnection() {
        $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset={$this->charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            return new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            error_log("DB connection error: " . $e->getMessage());
            die("Database connection failed.");
        }
    }
}
// Initialize database tables
function initializeDatabase() {
    $database = new Database();
    $db = $database->getConnection();
    
    try {
        // Users table
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            restaurant_name VARCHAR(100) NOT NULL,
            country VARCHAR(50) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            membership_plan VARCHAR(50) DEFAULT 'free',
            membership_expires DATE DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            is_admin TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Credits table
        $db->exec("CREATE TABLE IF NOT EXISTS user_credits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            credits_limit INT DEFAULT 100,
            credits_used INT DEFAULT 0,
            last_reset DATE DEFAULT CURRENT_DATE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        // Membership plans table
        $db->exec("CREATE TABLE IF NOT EXISTS membership_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            credits_limit INT NOT NULL,
            duration_days INT NOT NULL,
            features TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Insert default membership plans
        $db->exec("INSERT IGNORE INTO membership_plans (id, name, price, credits_limit, duration_days, features) VALUES
            (1, 'Basic', 29.99, 500, 30, 'Basic order management, Email support'),
            (2, 'Professional', 59.99, 1500, 30, 'Advanced analytics, Priority support, Custom reports'),
            (3, 'Enterprise', 99.99, 5000, 30, 'Unlimited features, 24/7 support, Custom integrations')
        ");

        // Create orders table for each user (this will be done dynamically)
        echo "Database initialized successfully!";
    } catch(PDOException $e) {
        echo "Error: " . $e->getMessage();
    }
}

// Create orders table for a specific user
function createUserOrdersTable($userId) {
    $database = new Database();
    $db = $database->getConnection();
    
    $tableName = "orders_user_" . $userId;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS $tableName (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            address TEXT NOT NULL,
            phone VARCHAR(20) NOT NULL,
            table_number VARCHAR(10),
            order_type ENUM('Delivery', 'Dine-in', 'Takeaway') NOT NULL,
            order_details TEXT,
            order_status ENUM('Pending', 'Preparing', 'Ready', 'Served', 'Delivered', 'Handover') DEFAULT 'Pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        // Add some sample orders for testing
        $sampleCheck = $db->prepare("SELECT COUNT(*) as count FROM $tableName");
        $sampleCheck->execute();
        $count = $sampleCheck->fetch(PDO::FETCH_ASSOC);
        
        if ($count['count'] == 0) {
            $sampleData = $db->prepare("INSERT INTO $tableName (name, address, phone, table_number, order_type, order_details, order_status) VALUES
                ('John Doe', '123 Main Street, City', '+1234567890', 'A1', 'Delivery', 'Pizza Margherita, Coke', 'Pending'),
                ('Jane Smith', '456 Oak Avenue, Town', '+0987654321', 'B2', 'Dine-in', 'Burger with Fries', 'Preparing'),
                ('Bob Johnson', '789 Pine Road, Village', '+1122334455', '', 'Takeaway', 'Pasta Carbonara', 'Ready')");
            $sampleData->execute();
        }
    } catch(PDOException $e) {
        error_log("Error creating orders table: " . $e->getMessage());
    }
}

// Initialize on first load
if (!file_exists('install.lock')) {
    initializeDatabase();
    file_put_contents('install.lock', 'installed');
}
?>