<?php
require_once __DIR__ . '/../env.php';
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
