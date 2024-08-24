<?php
// Path: config/user_credits.php
require_once __DIR__ . '/database.php';

class UserCreditsTable {
    private PDO $db;
    private int $defaultLimit;
    private int $periodDays;

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->defaultLimit = (int)($_ENV['CREDIT_LIMIT'] ?? 100);
        $this->periodDays   = (int)($_ENV['CREDIT_PERIOD_DAYS'] ?? 30);
    }

    public function createTable(): void {
        $sql = "
        CREATE TABLE IF NOT EXISTS user_credits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            credits_limit INT NOT NULL,
            credits_used INT NOT NULL DEFAULT 0,
            period_days INT NOT NULL DEFAULT 30,
            last_reset DATE NOT NULL DEFAULT (CURRENT_DATE),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_uc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uq_uc_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $this->db->exec($sql);
        // Helpful indexes (MySQL ignores IF NOT EXISTS on indexes <8.0.13, safe to attempt)
        try { $this->db->exec("CREATE INDEX idx_uc_last_reset ON user_credits(last_reset)"); } catch (\Throwable $e) {}
    }

    /**
     * Ensure a credits row exists for a user; seed from env.
     */
    public function ensureForUser(int $userId): void {
        $stmt = $this->db->prepare("SELECT user_id FROM user_credits WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        if ($stmt->fetch()) return;

        $ins = $this->db->prepare("
            INSERT INTO user_credits (user_id, credits_limit, credits_used, period_days, last_reset)
            VALUES (:uid, :limit, 0, :period_days, CURRENT_DATE)
        ");
        $ins->execute([
            ':uid' => $userId,
            ':limit' => $this->defaultLimit,
            ':period_days' => $this->periodDays,
        ]);
    }

    /**
     * Get the current credits row for a user.
     */
    public function get(int $userId): ?array {
        $this->resetIfDue($userId);
        $stmt = $this->db->prepare("SELECT * FROM user_credits WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Consume credits safely; returns ['ok'=>bool, 'remaining'=>int, 'message'=>string]
     */
    public function consume(int $userId, int $amount): array {
        if ($amount <= 0) {
            return ['ok' => false, 'remaining' => 0, 'message' => 'Invalid amount'];
        }

        $this->ensureForUser($userId);
        $this->resetIfDue($userId);

        // Lock row for update to avoid race conditions
        $this->db->beginTransaction();
        try {
            $sel = $this->db->prepare("SELECT credits_limit, credits_used FROM user_credits WHERE user_id = :uid FOR UPDATE");
            $sel->execute([':uid' => $userId]);
            $row = $sel->fetch();
            if (!$row) { $this->db->rollBack(); return ['ok'=>false,'remaining'=>0,'message'=>'No credits row']; }

            $limit = (int)$row['credits_limit'];
            $used  = (int)$row['credits_used'];
            $remaining = max(0, $limit - $used);

            if ($amount > $remaining) {
                $this->db->rollBack();
                return ['ok'=>false,'remaining'=>$remaining,'message'=>'Not enough credits'];
            }

            $newUsed = $used + $amount;
            $upd = $this->db->prepare("UPDATE user_credits SET credits_used = :used WHERE user_id = :uid");
            $upd->execute([':used' => $newUsed, ':uid' => $userId]);

            $this->db->commit();
            return ['ok'=>true,'remaining'=>$limit - $newUsed,'message'=>'OK'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok'=>false,'remaining'=>0,'message'=>'Error processing credits'];
        }
    }

    /**
     * Reset period if the window has elapsed.
     * If today >= last_reset + period_days → reset credits_used to 0 and bump last_reset to today.
     */
    public function resetIfDue(int $userId): void {
        $stmt = $this->db->prepare("SELECT period_days, last_reset FROM user_credits WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        if (!$row) return;

        $periodDays = (int)$row['period_days'];
        $lastReset  = $row['last_reset']; // Y-m-d
        $dueDate    = (new DateTime($lastReset))->modify("+{$periodDays} days")->format('Y-m-d');
        $today      = (new DateTime('today'))->format('Y-m-d');

        if ($today >= $dueDate) {
            $upd = $this->db->prepare("UPDATE user_credits SET credits_used = 0, last_reset = CURRENT_DATE WHERE user_id = :uid");
            $upd->execute([':uid' => $userId]);
        }
    }

    /**
     * Change the limit (defaults to env if not provided).
     */
    public function setLimit(int $userId, ?int $newLimit = null): bool {
        $limit = $newLimit ?? $this->defaultLimit;
        $stmt = $this->db->prepare("UPDATE user_credits SET credits_limit = :lim WHERE user_id = :uid");
        return $stmt->execute([':lim' => $limit, ':uid' => $userId]);
    }

    /**
     * Change/reset the period (defaults to env if not provided).
     */
    public function setPeriod(int $userId, ?int $newDays = null): bool {
        $days = $newDays ?? $this->periodDays;
        $stmt = $this->db->prepare("UPDATE user_credits SET period_days = :d WHERE user_id = :uid");
        return $stmt->execute([':d' => $days, ':uid' => $userId]);
    }
}
