<?php
// api/stripe_webhook.php
// Syncs plans, payments, credits with Stripe webhooks (2025 API safe).
// Mid-cycle changes NEVER reset credits_used. Reset only on true cycle rollover.

require_once __DIR__ . '/../config/stripe.php';         // stripe_request($method, $path, $params = [])
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../env.php';

// (Optional) pretty emails — comment out if you don't use them
require_once __DIR__ . '/../config/mailer.php';          // sendEmailSMTP($to, $subject, $html, $text)
require_once __DIR__ . '/../config/email_templates.php'; // tmpl_*(), manage_billing_url()

loadEnv(__DIR__ . '/../.env');

/* ------------------------- Read payload ------------------------- */
$raw   = @file_get_contents('php://input');
$event = json_decode($raw, true);

if (!$event || empty($event['type'])) {
  http_response_code(400);
  echo 'Invalid payload';
  exit;
}

$type = $event['type'];

/* ------------------------- Helpers ------------------------- */
function db(): PDO {
  static $pdo = null;
  if ($pdo === null) $pdo = (new Database())->getConnection();
  return $pdo;
}

/** Support both naming conventions in .env */
function plan_id_from_price(string $priceId): ?int {
  $basic1 = $_ENV['ZICBOT_BASIC_30']        ?? '';
  $pro1   = $_ENV['ZICBOT_PROFESSIONAL_60'] ?? '';
  $ent1   = $_ENV['ZICBOT_ENTERPRISE_100']  ?? '';

  // alt naming
  $pro2   = $_ENV['ZICBOT_GROWTH_60'] ?? '';
  $ent2   = $_ENV['ZICBOT_BRAND_100'] ?? '';

  $map = [];
  if ($basic1) $map[$basic1] = 1;
  if ($pro1)   $map[$pro1]   = 2;
  if ($ent1)   $map[$ent1]   = 3;
  if ($pro2)   $map[$pro2]   = 2;
  if ($ent2)   $map[$ent2]   = 3;

  return $map[$priceId] ?? null;
}

/** Get limits (credits_limit + period_days) for a plan from membership_plans */
function plan_limits(PDO $db, int $planId): ?array {
  $st = $db->prepare("
    SELECT 
      COALESCE(credits_limit, 0) AS credits_limit,
      COALESCE(duration_days, 30) AS period_days
    FROM membership_plans
    WHERE id = :id
    LIMIT 1
  ");
  $st->execute([':id' => $planId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  return [
    'credits_limit' => (int)$row['credits_limit'],
    'period_days'   => (int)$row['period_days'],
  ];
}

/** Ensure user_credits table exists (with period_days column!) */
function ensure_user_credits_table(PDO $db): void {
  $db->exec("CREATE TABLE IF NOT EXISTS user_credits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    credits_limit INT NOT NULL,
    credits_used INT NOT NULL DEFAULT 0,
    period_days INT NOT NULL DEFAULT 30,
    last_reset DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** New cycle reset — zero used, set new limit and last_reset */
function reset_credits_for_new_cycle(PDO $db, int $userId, int $planId): void {
  $limits = plan_limits($db, $planId);
  if (!$limits) return;

  ensure_user_credits_table($db);
  $now = date('Y-m-d H:i:s');

  $st = $db->prepare("
    INSERT INTO user_credits (user_id, credits_limit, credits_used, period_days, last_reset)
    VALUES (:uid, :limit, 0, :days, :now)
    ON DUPLICATE KEY UPDATE
      credits_limit = VALUES(credits_limit),
      credits_used  = 0,
      period_days   = VALUES(period_days),
      last_reset    = VALUES(last_reset),
      updated_at    = CURRENT_TIMESTAMP
  ");
  $st->execute([
    ':uid'   => $userId,
    ':limit' => $limits['credits_limit'],
    ':days'  => $limits['period_days'],
    ':now'   => $now,
  ]);
}

/** Mid-cycle switch: raise/lower limit only (keep used + last_reset) */
function apply_midcycle_plan_change(PDO $db, int $userId, int $planId): void {
  $limits = plan_limits($db, $planId);
  if (!$limits) return;

  ensure_user_credits_table($db);

  // Initialize row if missing
  $st = $db->prepare("SELECT user_id FROM user_credits WHERE user_id = :uid LIMIT 1");
  $st->execute([':uid' => $userId]);
  if (!$st->fetch(PDO::FETCH_ASSOC)) {
    $now = date('Y-m-d H:i:s');
    $ins = $db->prepare("
      INSERT INTO user_credits (user_id, credits_limit, credits_used, period_days, last_reset)
      VALUES (:uid, :limit, 0, :days, :now)
    ");
    $ins->execute([
      ':uid'   => $userId,
      ':limit' => $limits['credits_limit'],
      ':days'  => $limits['period_days'],
      ':now'   => $now,
    ]);
    return;
  }

  $upd = $db->prepare("
    UPDATE user_credits
       SET credits_limit = :limit,
           period_days   = :days
     WHERE user_id = :uid
     LIMIT 1
  ");
  $upd->execute([
    ':limit' => $limits['credits_limit'],
    ':days'  => $limits['period_days'],
    ':uid'   => $userId,
  ]);

  // Optional: clamp on downgrade
  // $db->prepare("UPDATE user_credits SET credits_used = LEAST(credits_used, credits_limit) WHERE user_id = :uid")->execute([':uid'=>$userId]);
}

/** Find user by id or by customer id */
function find_user(PDO $db, ?int $userId = null, ?string $customerId = null): ?array {
  if ($userId) {
    $st = $db->prepare("SELECT id, name, email, membership_plan, stripe_customer_id, stripe_subscription_id FROM users WHERE id = :id LIMIT 1");
    $st->execute([':id' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
  }
  if ($customerId) {
    $st = $db->prepare("SELECT id, name, email, membership_plan, stripe_customer_id, stripe_subscription_id FROM users WHERE stripe_customer_id = :c LIMIT 1");
    $st->execute([':c' => $customerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
  }
  return null;
}

/** Plan name (for emails) */
function plan_name_from_id(PDO $db, ?int $planId): ?string {
  if (!$planId) return null;
  $st = $db->prepare("SELECT name FROM membership_plans WHERE id = :id LIMIT 1");
  $st->execute([':id' => $planId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row['name'] ?? null;
}

/** Update user plan + stripe ids */
function apply_user_plan(PDO $db, int $userId, ?int $planId, ?string $customerId, ?string $subscriptionId): void {
  if ($planId !== null) {
    $st = $db->prepare("UPDATE users SET membership_plan = :p WHERE id = :id");
    $st->execute([':p' => $planId, ':id' => $userId]);
  }
  if ($customerId) {
    $st = $db->prepare("
      UPDATE users 
         SET stripe_customer_id = :c
       WHERE id = :id AND (stripe_customer_id IS NULL OR stripe_customer_id = '')
    ");
    $st->execute([':c' => $customerId, ':id' => $userId]);
  }
  if ($subscriptionId) {
    $st = $db->prepare("UPDATE users SET stripe_subscription_id = :s WHERE id = :id");
    $st->execute([':s' => $subscriptionId, ':id' => $userId]);
  }
}

/** Idempotency */
function has_event(PDO $db, string $id): bool {
  $db->exec("CREATE TABLE IF NOT EXISTS stripe_events (
    id VARCHAR(200) PRIMARY KEY,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $st = $db->prepare("SELECT 1 FROM stripe_events WHERE id = :id");
  $st->execute([':id'=>$id]);
  return (bool)$st->fetchColumn();
}
function mark_event(PDO $db, string $id): void {
  $st = $db->prepare("INSERT IGNORE INTO stripe_events (id) VALUES (:id)");
  $st->execute([':id'=>$id]);
}

/** Guard rails for payments FK + orphan logging */
function user_exists(PDO $db, int $userId): bool {
  $st = $db->prepare("SELECT 1 FROM users WHERE id = :id");
  $st->execute([':id' => $userId]);
  return (bool)$st->fetchColumn();
}
function log_orphan_payment(PDO $db, array $payload, ?string $reason = null): void {
  $db->exec("CREATE TABLE IF NOT EXISTS payments_orphaned (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    reason VARCHAR(255) NULL,
    payload JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $st = $db->prepare("INSERT INTO payments_orphaned (reason, payload) VALUES (:r, :p)");
  $st->execute([':r' => $reason, ':p' => json_encode($payload)]);
}

/** Payment rows (only when we have a real invoice_id) + valid user_id */
function record_payment(PDO $db, array $data): void {
  $uid = $data['user_id'] ?? null;
  if (!$uid || !user_exists($db, (int)$uid)) {
    log_orphan_payment($db, $data, 'missing-or-invalid-user_id');
    return; // prevent FK blow-up
  }

  $db->exec("CREATE TABLE IF NOT EXISTS payments (
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
    UNIQUE KEY uniq_pi (payment_intent_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  if (empty($data['invoice_id'])) return;

  $st = $db->prepare("INSERT INTO payments 
    (user_id, stripe_session_id, stripe_subscription_id, stripe_customer_id, invoice_id, payment_intent_id, plan_id, plan_price_id, amount, currency, status)
    VALUES
    (:user_id, :sess, :sub, :cust, :inv, :pi, :plan, :price, :amount, :currency, :status)
    ON DUPLICATE KEY UPDATE
      amount=VALUES(amount),
      currency=VALUES(currency),
      status=VALUES(status),
      plan_id=VALUES(plan_id),
      plan_price_id=VALUES(plan_price_id)");
  $st->execute([
      ':user_id'  => (int)$uid,
      ':sess'     => $data['stripe_session_id'] ?? null,
      ':sub'      => $data['stripe_subscription_id'] ?? null,
      ':cust'     => $data['stripe_customer_id'] ?? null,
      ':inv'      => $data['invoice_id'] ?? null,
      ':pi'       => $data['payment_intent_id'] ?? null,
      ':plan'     => $data['plan_id'] ?? null,
      ':price'    => $data['plan_price_id'] ?? null,
      ':amount'   => $data['amount'] ?? null,
      ':currency' => $data['currency'] ?? null,
      ':status'   => $data['status'] ?? null,
  ]);
}

/** (Optional) Emails */
function notify_user(array $user, array $tpl): void {
  try {
    if (!function_exists('sendEmailSMTP')) return;
    $to = trim(strtolower($user['email'] ?? ''));
    if ($to && !empty($tpl['subject'])) {
      @sendEmailSMTP($to, $tpl['subject'], $tpl['html'] ?? '', $tpl['text'] ?? '');
    }
  } catch (\Throwable $e) { /* swallow in webhook */ }
}
function fmt_amount(?int $amountCents, ?string $currency): string {
  if ($amountCents === null) return '—';
  $num = number_format($amountCents / 100, 2, '.', ',');
  $cur = strtoupper($currency ?? 'USD');
  return "{$num} {$cur}";
}

/** --------- Cycle detection helpers ---------- */
function invoice_period_start_ts(array $invoice): ?int {
  $lines = $invoice['lines']['data'] ?? [];
  foreach ($lines as $ln) {
    $isSubLine = (($ln['type'] ?? '') === 'subscription') || !empty($ln['price']['recurring']);
    if ($isSubLine && isset($ln['period']['start'])) {
      return (int)$ln['period']['start'];
    }
  }
  if (isset($invoice['period_start'])) return (int)$invoice['period_start'];
  return null;
}
function should_reset_for_cycle(PDO $db, int $userId, ?int $stripePeriodStart): bool {
  if (!$stripePeriodStart) return true; // safe default

  $st = $db->prepare("SELECT last_reset, period_days FROM user_credits WHERE user_id = :uid LIMIT 1");
  $st->execute([':uid' => $userId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row || empty($row['last_reset'])) return true;

  $lastReset   = strtotime($row['last_reset']);
  $periodDays  = max(1, (int)($row['period_days'] ?? 30));
  $expectedNext = $lastReset + $periodDays * 86400;

  // 12h tolerance
  return $stripePeriodStart >= ($expectedNext - 12 * 3600);
}
function apply_invoice_credit_logic(PDO $pdo, ?int $userId, ?int $planId, ?string $billingReason, ?int $periodStartTs): void {
  if (!$userId || $planId === null) return;

  if ($billingReason === 'subscription_create') {
    reset_credits_for_new_cycle($pdo, $userId, $planId);
  } elseif ($billingReason === 'subscription_cycle') {
    if (should_reset_for_cycle($pdo, $userId, $periodStartTs)) {
      reset_credits_for_new_cycle($pdo, $userId, $planId);
    } else {
      apply_midcycle_plan_change($pdo, $userId, $planId);
    }
  } else {
    apply_midcycle_plan_change($pdo, $userId, $planId);
  }
}

/** Bootstrap credits at checkout completion (show plan limits immediately) */
function bootstrap_credits_on_checkout(PDO $db, int $userId, int $planId): void {
  $limits = plan_limits($db, $planId);
  if (!$limits) return;

  ensure_user_credits_table($db);

  // Check existing credits row
  $st = $db->prepare("SELECT user_id, credits_limit, credits_used FROM user_credits WHERE user_id = :uid LIMIT 1");
  $st->execute([':uid' => $userId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  $now = date('Y-m-d H:i:s');

  if (!$row) {
    // No row yet → initialize to plan limits now
    $ins = $db->prepare("
      INSERT INTO user_credits (user_id, credits_limit, credits_used, period_days, last_reset)
      VALUES (:uid, :limit, 0, :days, :now)
    ");
    $ins->execute([
      ':uid'   => $userId,
      ':limit' => $limits['credits_limit'],
      ':days'  => $limits['period_days'],
      ':now'   => $now,
    ]);
    return;
  }

  // Row exists. If trial left it at 100 and no usage yet, align it to plan right away.
  if ((int)$row['credits_used'] === 0) {
    $upd = $db->prepare("
      UPDATE user_credits
         SET credits_limit = :limit,
             period_days   = :days,
             last_reset    = :now
       WHERE user_id = :uid
       LIMIT 1
    ");
    $upd->execute([
      ':limit' => $limits['credits_limit'],
      ':days'  => $limits['period_days'],
      ':now'   => $now,
      ':uid'   => $userId,
    ]);
  }
}

/* ------------------------- Process ------------------------- */
$pdo = db();
if (!empty($event['id']) && has_event($pdo, $event['id'])) {
  http_response_code(200);
  echo 'already processed';
  exit;
}

try {
  switch ($type) {

    case 'checkout.session.completed': {
      $session = $event['data']['object'];

      $userId  = isset($session['metadata']['user_id']) ? (int)$session['metadata']['user_id'] : null;
      $priceId = $session['metadata']['plan_price_id'] ?? null;
      $planId  = $priceId ? plan_id_from_price($priceId) : null;

      $subId = $session['subscription'] ?? null;
      $custId= $session['customer'] ?? null;

      if ($userId) {
        apply_user_plan($pdo, $userId, $planId, $custId, $subId);
        $pdo->prepare("UPDATE users SET subscription_status = 'active' WHERE id = :id")
            ->execute([':id' => $userId]);

        // Make plan credits visible immediately on first subscription
        if ($planId !== null) {
          bootstrap_credits_on_checkout($pdo, $userId, $planId);
        }

        if (function_exists('tmpl_subscription_started')) {
          $user     = find_user($pdo, $userId, $custId);
          $planName = plan_name_from_id($pdo, $planId) ?? 'your plan';
          if ($user) {
            $tpl = tmpl_subscription_started($user['name'] ?? '', $planName, function_exists('manage_billing_url') ? manage_billing_url() : '#');
            notify_user($user, $tpl);
          }
        }
      }
      break;
    }

    case 'invoice_payment.paid': {
      // 2025-style event: object = invoice_payment (not full invoice)
      $ip = $event['data']['object'];

      $invoiceId     = $ip['invoice'] ?? null;
      $amountPaid    = $ip['amount_paid'] ?? null;
      $currency      = $ip['currency'] ?? null;
      $paymentIntent = $ip['payment']['payment_intent'] ?? null;

      $subscriptionId = null;
      $customerId     = null;
      $billingReason  = null;
      $periodStartTs  = null;
      $priceId        = null;
      $planId         = null;
      $userId         = null;

      if ($invoiceId) {
        // Expand to read sub line + customer
        $invoice = stripe_request('GET', "/v1/invoices/{$invoiceId}?expand[]=lines.data.price");
        $subscriptionId = $invoice['subscription'] ?? null;
        $customerId     = $invoice['customer'] ?? null;
        $billingReason  = $invoice['billing_reason'] ?? null;
        $periodStartTs  = invoice_period_start_ts($invoice);

        foreach (($invoice['lines']['data'] ?? []) as $ln) {
          $isSubLine = (($ln['type'] ?? '') === 'subscription') || !empty($ln['price']['recurring']);
          if ($isSubLine && !empty($ln['price']['id'])) { $priceId = $ln['price']['id']; break; }
        }

        // Last-ditch fallback: email map
        if (!$customerId && !empty($invoice['customer_email'])) {
          $st = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = :e LIMIT 1");
          $st->execute([':e' => strtolower($invoice['customer_email'])]);
          $row = $st->fetch(PDO::FETCH_ASSOC);
          if ($row) $userId = (int)$row['id'];
        }
      }

      // 1) subscription metadata
      if ($subscriptionId && !$userId) {
        $sub = stripe_request('GET', "/v1/subscriptions/{$subscriptionId}");
        $md  = $sub['metadata'] ?? [];
        if (isset($md['user_id'])) $userId = (int)$md['user_id'];
        if (!$priceId && !empty($sub['items']['data'][0]['price']['id'])) {
          $priceId = $sub['items']['data'][0]['price']['id'];
        }
      }

      // 2) by customer id
      if (!$userId && $customerId) {
        $u = find_user($pdo, null, $customerId);
        if ($u) $userId = (int)$u['id'];
      }

      // 3) PI metadata or PI.customer
      if (!$userId && $paymentIntent) {
        $pi = stripe_request('GET', "/v1/payment_intents/{$paymentIntent}");
        $pimd = $pi['metadata'] ?? [];
        if (isset($pimd['user_id'])) {
          $userId = (int)$pimd['user_id'];
        } elseif (!empty($pi['customer'])) {
          $u = find_user($pdo, null, $pi['customer']);
          if ($u) $userId = (int)$u['id'];
        }
      }

      if ($priceId) $planId = plan_id_from_price($priceId);

      if ($userId) {
        apply_user_plan($pdo, $userId, $planId, $customerId, $subscriptionId);
        $pdo->prepare("UPDATE users SET subscription_status = 'active' WHERE id = :id")
            ->execute([':id'=>$userId]);
        apply_invoice_credit_logic($pdo, $userId, $planId, $billingReason, $periodStartTs);
      }

      record_payment($pdo, [
        'user_id'                => $userId, // guarded inside
        'stripe_subscription_id' => $subscriptionId,
        'stripe_customer_id'     => $customerId,
        'invoice_id'             => $invoiceId,
        'payment_intent_id'      => $paymentIntent,
        'plan_id'                => $planId,
        'plan_price_id'          => $priceId,
        'amount'                 => $amountPaid,
        'currency'               => $currency,
        'status'                 => 'paid',
      ]);

      if ($userId && function_exists('tmpl_payment_received')) {
        $user = find_user($pdo, $userId, $customerId);
        if ($user && $invoiceId) {
          $amountStr = fmt_amount($amountPaid, $currency);
          $tpl = tmpl_payment_received($user['name'] ?? '', $amountStr, $invoiceId, function_exists('manage_billing_url') ? manage_billing_url() : '#');
          notify_user($user, $tpl);
        }
      }
      break;
    }

    case 'invoice.paid':
    case 'invoice.payment_succeeded': {
      // Modern invoice object (already includes lines)
      $invoice = $event['data']['object'];
      $invoiceId      = $invoice['id'] ?? null;
      $subscriptionId = $invoice['subscription'] ?? null;
      $customerId     = $invoice['customer'] ?? null;
      $amountPaid     = $invoice['amount_paid'] ?? null;
      $currency       = $invoice['currency'] ?? null;
      $paymentIntent  = $invoice['payment_intent'] ?? null;
      $billingReason  = $invoice['billing_reason'] ?? null;
      $periodStartTs  = invoice_period_start_ts($invoice);

      $userId = null; $planId = null; $priceId = null;

      if ($subscriptionId) {
        $sub = stripe_request('GET', "/v1/subscriptions/{$subscriptionId}");
        $md  = $sub['metadata'] ?? [];
        $userId  = isset($md['user_id']) ? (int)$md['user_id'] : null;
        $priceId = $md['plan_price_id'] ?? ($sub['items']['data'][0]['price']['id'] ?? null);
      }

      if (!$userId && $customerId) {
        $u = find_user($pdo, null, $customerId);
        if ($u) $userId = (int)$u['id'];
      }
      if (!$userId && $paymentIntent) {
        $pi = stripe_request('GET', "/v1/payment_intents/{$paymentIntent}");
        $pimd = $pi['metadata'] ?? [];
        if (isset($pimd['user_id'])) {
          $userId = (int)$pimd['user_id'];
        } elseif (!empty($pi['customer'])) {
          $u = find_user($pdo, null, $pi['customer']);
          if ($u) $userId = (int)$u['id'];
        }
      }

      if ($priceId) $planId = plan_id_from_price($priceId);

      if ($userId) {
        apply_user_plan($pdo, $userId, $planId, $customerId, $subscriptionId);
        $pdo->prepare("UPDATE users SET subscription_status = 'active' WHERE id = :id")
            ->execute([':id'=>$userId]);
        apply_invoice_credit_logic($pdo, $userId, $planId, $billingReason, $periodStartTs);
      }

      record_payment($pdo, [
        'user_id'                => $userId,
        'stripe_subscription_id' => $subscriptionId,
        'stripe_customer_id'     => $customerId,
        'invoice_id'             => $invoiceId,
        'payment_intent_id'      => $paymentIntent,
        'plan_id'                => $planId,
        'plan_price_id'          => $priceId,
        'amount'                 => $amountPaid,
        'currency'               => $currency,
        'status'                 => 'paid',
      ]);

      if ($userId && function_exists('tmpl_payment_received')) {
        $user = find_user($pdo, $userId, $customerId);
        if ($user && $invoiceId) {
          $amountStr = fmt_amount($amountPaid, $currency);
          $tpl = tmpl_payment_received($user['name'] ?? '', $amountStr, $invoiceId, function_exists('manage_billing_url') ? manage_billing_url() : '#');
          notify_user($user, $tpl);
        }
      }
      break;
    }

    case 'customer.subscription.updated': {
      // Plan switches, cancel-at-period-end, etc. — mid-cycle only (no resets)
      $sub  = $event['data']['object'];
      $subscriptionId    = $sub['id'] ?? null;
      $customerId        = $sub['customer'] ?? null;
      $status            = $sub['status'] ?? null;
      $cancelAtPeriodEnd = !empty($sub['cancel_at_period_end']);
      $endedAt           = $sub['ended_at'] ?? null;
      $canceledAt        = $sub['canceled_at'] ?? null;
      $md                = $sub['metadata'] ?? [];
      $userId            = isset($md['user_id']) ? (int)$md['user_id'] : null;

      $priceId = $md['plan_price_id'] ?? ($sub['items']['data'][0]['price']['id'] ?? null);
      $planId  = $priceId ? plan_id_from_price($priceId) : null;

      $userBefore = find_user($pdo, $userId, $customerId);
      $prevPlanId = $userBefore['membership_plan'] ?? null;

      if ($userId) {
        $isCancelledNow = $status === 'canceled' || !empty($endedAt) || $status === 'incomplete_expired' || !empty($canceledAt);

        if ($isCancelledNow) {
          $pdo->prepare("
            UPDATE users
               SET membership_plan = NULL,
                   subscription_status = 'cancelled',
                   stripe_subscription_id = NULL
             WHERE id = :id
          ")->execute([':id' => $userId]);
        } else {
          if ($planId !== null) {
            $pdo->prepare("UPDATE users SET membership_plan = :p WHERE id = :id")
                ->execute([':p' => $planId, ':id' => $userId]);
          }
          if (!empty($status)) {
            $pdo->prepare("UPDATE users SET subscription_status = :st WHERE id = :id")
                ->execute([':st' => $status, ':id' => $userId]);
          }
          $pdo->prepare("
            UPDATE users
               SET stripe_customer_id    = COALESCE(NULLIF(stripe_customer_id,''), :c),
                   stripe_subscription_id = COALESCE(:s, stripe_subscription_id)
             WHERE id = :id
          ")->execute([':c'=>$customerId, ':s'=>$subscriptionId, ':id'=>$userId]);

          if ($planId !== null) {
            apply_midcycle_plan_change($pdo, $userId, $planId);
          }
        }
      }

      $user = $userBefore ?: find_user($pdo, $userId, $customerId);
      if ($user) {
        if ($cancelAtPeriodEnd && empty($endedAt) && function_exists('tmpl_cancellation_scheduled')) {
          $tpl = tmpl_cancellation_scheduled($user['name'] ?? '', function_exists('manage_billing_url') ? manage_billing_url() : '#');
          notify_user($user, $tpl);
        }
        if ($planId && $prevPlanId && (int)$planId !== (int)$prevPlanId && function_exists('tmpl_subscription_updated')) {
          $newName = plan_name_from_id($pdo, $planId) ?? 'new plan';
          $oldName = plan_name_from_id($pdo, (int)$prevPlanId) ?? 'previous plan';
          $tpl = tmpl_subscription_updated($user['name'] ?? '', $oldName, $newName, function_exists('manage_billing_url') ? manage_billing_url() : '#');
          notify_user($user, $tpl);
        }
      }
      break;
    }

    case 'customer.subscription.deleted': {
      $sub        = $event['data']['object'];
      $md         = $sub['metadata'] ?? [];
      $userId     = isset($md['user_id']) ? (int)$md['user_id'] : null;
      $customerId = $sub['customer'] ?? null;

      if ($userId) {
        $pdo->prepare("
          UPDATE users
             SET membership_plan = NULL,
                 subscription_status = 'cancelled',
                 stripe_subscription_id = NULL
           WHERE id = :id
        ")->execute([':id' => $userId]);
      }

      if (function_exists('tmpl_subscription_cancelled')) {
        $user = find_user($pdo, $userId, $customerId);
        if ($user) {
          $tpl = tmpl_subscription_cancelled($user['name'] ?? '', function_exists('manage_billing_url') ? manage_billing_url() : '#');
          notify_user($user, $tpl);
        }
      }
      break;
    }

    default:
      // ignore others quietly
      break;
  }

  if (!empty($event['id'])) mark_event($pdo, $event['id']);
  http_response_code(200);
  echo 'ok';
} catch (Throwable $e) {
  http_response_code(500);
  echo 'Webhook error: ' . $e->getMessage();
}
