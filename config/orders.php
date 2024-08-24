<?php
// Path: config/orders.php
require_once __DIR__ . '/database.php';

class OrdersTable {
    private PDO $db;

    private array $orderTypes  = ['Delivery','Dine-in','Takeaway'];
    private array $orderStatus = ['Pending','Preparing','Ready','Served','Delivered','Handover','Cancelled'];
    private array $paymentStatus = ['Unpaid','Paid','Refunded'];

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    public function createTable(): void {
        $sql = "
        CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            address TEXT NOT NULL,
            phone VARCHAR(20) NOT NULL,
            table_number VARCHAR(10) NULL,
            order_type ENUM('Delivery','Dine-in','Takeaway') NOT NULL,
            order_details TEXT NULL,
            note TEXT NULL,
            order_status ENUM('Pending','Preparing','Ready','Served','Delivered','Handover','Cancelled') DEFAULT 'Pending',
            payment_status ENUM('Unpaid','Paid','Refunded') DEFAULT 'Unpaid',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_orders_user (user_id),
            INDEX idx_orders_status (order_status),
            INDEX idx_orders_created (created_at),
            INDEX idx_orders_payment (payment_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $this->db->exec($sql);
    }

    public function create(array $data): int {
        if (!in_array($data['order_type'], $this->orderTypes, true)) {
            throw new InvalidArgumentException('Invalid order_type');
        }
        if (!in_array($data['order_status'] ?? 'Pending', $this->orderStatus, true)) {
            throw new InvalidArgumentException('Invalid order_status');
        }
        if (!in_array($data['payment_status'] ?? 'Unpaid', $this->paymentStatus, true)) {
            throw new InvalidArgumentException('Invalid payment_status');
        }

        $sql = "INSERT INTO orders
            (user_id, name, address, phone, table_number, order_type, order_details, note, order_status, payment_status)
            VALUES
            (:user_id, :name, :address, :phone, :table_number, :order_type, :order_details, :note, :order_status, :payment_status)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id'        => (int)$data['user_id'],
            ':name'           => $data['name'],
            ':address'        => $data['address'],
            ':phone'          => $data['phone'],
            ':table_number'   => $data['table_number'] ?? null,
            ':order_type'     => $data['order_type'],
            ':order_details'  => $data['order_details'] ?? null,
            ':note'           => $data['note'] ?? null,
            ':order_status'   => $data['order_status'] ?? 'Pending',
            ':payment_status' => $data['payment_status'] ?? 'Unpaid',
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function seedDummyForUser(int $userId): void {
        $this->createTable(); // ensure table exists

        
    }
}
