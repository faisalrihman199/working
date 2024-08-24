<?php
require_once __DIR__ . '/database.php';

class MembershipPlansTable {
    private PDO $db;

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    public function createTable(): void {
        $sql = "
        CREATE TABLE IF NOT EXISTS membership_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            credits_limit INT NOT NULL,
            duration_days INT NOT NULL,
            features TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $this->db->exec($sql);

        // Insert defaults
        $this->db->exec("INSERT IGNORE INTO membership_plans (id, name, price, credits_limit, duration_days, features) VALUES
            (1, 'Basic', 100.00, 15000, 30, 'AI Training, Basic order management, Instant order notifications, Email support'),
            (2, 'Growth', 150.00, 30000, 30, 'All in Basic Package, Payment gateway integration, Fast support'),
            (3, 'Brand', 200.00, 60000, 30, 'All in Growth Package, Multi-Device Support, Custom chat interface branding, Priority support')
        ");
    }
}
