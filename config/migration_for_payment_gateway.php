<?php
// File: config/migration_for_payment_gateway.php
require_once __DIR__ . '/database.php';

try {
    $db = (new Database())->getConnection();
    echo "Starting migration...\n";

    // 1) Extend users table for Stripe identifiers
    $db->exec("
        ALTER TABLE users
        ADD COLUMN IF NOT EXISTS stripe_customer_id VARCHAR(191) NULL,
        ADD COLUMN IF NOT EXISTS stripe_subscription_id VARCHAR(191) NULL,
        ADD COLUMN IF NOT EXISTS subscription_status VARCHAR(191) NULL
    ");
    echo "✔ users table altered.\n";
} catch (PDOException $e) {
    echo "⚠ users table skipped: " . $e->getMessage() . "\n";
}

try {
    // 2) Create payments table (main log for Stripe invoices & intents)
    $db->exec("
        CREATE TABLE IF NOT EXISTS payments (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            stripe_session_id VARCHAR(191) NULL,
            stripe_subscription_id VARCHAR(191) NULL,
            stripe_customer_id VARCHAR(191) NULL,
            invoice_id VARCHAR(191) NULL,
            payment_intent_id VARCHAR(191) NULL,
            plan_id INT NULL,
            plan_price_id VARCHAR(191) NULL,
            amount BIGINT NULL,
            currency VARCHAR(10) NULL,
            status VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_invoice (invoice_id),
            UNIQUE KEY uniq_pi (payment_intent_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✔ payments table ready.\n";
} catch (PDOException $e) {
    echo "⚠ payments table error: " . $e->getMessage() . "\n";
}

echo "Migration completed successfully!\n";
