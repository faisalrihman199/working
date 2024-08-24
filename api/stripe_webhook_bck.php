<?php
// api/stripe_webhook.php
// Syncs plans/payments, keeps credits aligned with plan + billing cycle, and sends branded emails.

require_once __DIR__ . '/../config/stripe.php';          // stripe_request()
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';          // sendEmailSMTP($to, $subject, $html, $text)
require_once __DIR__ . '/../config/email_templates.php'; // tmpl_* + manage_billing_url()
require_once __DIR__ . '/../env.php';

loadEnv(__DIR__ . '/../.env');

/* ---- (Optional) Signature verification placeholder ----
$secret    = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? null;
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? null;
*/

$raw   = @file_get_contents('php://input');
$event = json_decode($raw, true);

if (!$event || empty($event['type'])) {
  http_response_code(400);
  echo 'Invalid payload';
  exit;
}

$type = $event['type'];

/* ---------- helpers ---------- */
function pdo(): PDO {
  static $pdo = null;
  if ($pdo === null) $pdo = (new Database())->getConnection();
  return $pdo;
}

function plan_id_from_price(string $priceId): ?int {
  $basic  = $_ENV['ZICBOT_BASIC_30']        ?? '';
  $pro    = $_ENV['ZICBOT_GROWTH_60']       ?? '';
  $ent    = $_ENV['ZICBOT_BRAND_100']       ?? '';
  $map = [];
  if ($basic) $map[$basic] = 1;
  if ($pro)   $map[$pro]   = 2;
  if ($ent)   $map[$ent]   = 3;
  return $map[$priceId] ?? null;
}

// Find user by id or by customer id (fallback)
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

// Plan info from DB (credits + period). Falls back to 30 days if column missing.
function plan_info_from_id(PDO $db, ?int $planId): ?array {
  if (!$planId) return null;
  // Try to fetch credits_limit and (optionally) period_days if you added that column.
  $st = $db->prepare("SELECT name, credits_limit, 
                             CASE WHEN EXISTS(
                               SELECT 1 FROM information_schema.COLUMNS 
                               WHERE TABLE_NAME='membership_plans' AND COLUMN_NAME='period_days'
                             ) THEN period_days ELSE 30 END AS period_days
                      FROM membership_plans WHERE id = :id LIMIT 1");
  $st->execute([':id' => $planId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  return [
    'name'          => $row['name'] ?? null,
    'credits_limit' => isset($row['credits_limit']) ? (int)$row['credits_limit'] : null,
    'period_days'   => isset($row['period_days']) ? (int)$row['period_days'] : 30,
  ];
}

function plan_name_from_id(PDO $db, ?int $planId): ?string {
  $info = plan_info_from_id($db, $planId);
  return $info['name'] ?? null;
}

function fmt_amount(?int $amountCents, ?string $currency): string {
  if ($amountCents === null) return '—';
  $num = number_format($amountCents / 100, 2, '.', ',');
  $cur = strtoupper($currency ?? 'USD');
  return "{$num} {$cur}";
}

// Emails (non-blocking for webhook)
function notify_user(array $user, array $tpl): void {
  try {
    $to = trim(strtolower($user['email'] ?? ''));
    if ($to && !empty($tpl['subject'])) {
      @sendEmailSMTP($to, $tpl['subject'], $tpl['html'] ?? '', $tpl['text'] ?? '');
    }
  } catch (\Throwable $e) { /* ignore */ }
}

// Idempotency store
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

// Payments upsert (invoice is required)
function record_payment(PDO $db, array $data): void {
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
      ':user_id'  => (int)($data['user_id'] ?? 0),
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

// Keep user rows coherent
function apply_user_plan(PDO $db, int $userId, ?int $planId, ?string $customerId, ?string $subscriptionId): void {
  if ($planId !== null) {
    $st = $db->prepare("UPDATE users SET membership_plan = :p WHERE id = :id");
    $st->execute([':p'=>$planId, ':id'=>$userId]);
  }
  if ($customerId) {
    $st = $db->prepare("UPDATE users SET stripe_customer_id = :c WHERE id = :id AND (stripe_customer_id IS NULL OR stripe_customer_id = '')");
    $st->execute([':c'=>$customerId, ':id'=>$userId]);
  }
  if ($subscriptionId) {
    $st = $db->prepare("UPDATE users SET stripe_subscription_id = :s WHERE id = :id");
    $st->execute([':s'=>$subscriptionId, ':id'=>$userId]);
  }
}

/* ---------- credits helpers ---------- */

// Ensure table & upsert the credit window for a plan
function upsert_credits_window(PDO $db, int $userId, int $planId, ?int $periodStartTs = null): void {
  // Make sure table exists with the columns we use.
  $db->exec("CREATE TABLE IF NOT EXISTS user_credits (
    user_id INT PRIMARY KEY,
    last_reset DATETIME NOT NULL,
    period_days INT NOT NULL,
    credits_limit INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $info = plan_info_from_id($db, $planId);
  if (!$info) return;

  $periodDays   = max(1, (int)$info['period_days']);  // default 30 in plan_info_from_id()
  $creditsLimit = $info['credits_limit'] ?? null;
  $resetAt      = $periodStartTs ? date('Y-m-d H:i:s', $periodStartTs) : date('Y-m-d H:i:s');

  // MySQL 5.7-safe upsert
  $st = $db->prepare("
    INSERT INTO user_credits (user_id, last_reset, period_days, credits_limit)
    VALUES (:uid, :last_reset, :period_days, :limit)
    ON DUPLICATE KEY UPDATE
      last_reset   = VALUES(last_reset),
      period_days  = VALUES(period_days),
      credits_limit= VALUES(credits_limit)
  ");
  $st->execute([
    ':uid'        => $userId,
    ':last_reset' => $resetAt,
    ':period_days'=> $periodDays,
    ':limit'      => $creditsLimit,
  ]);
}

// When we detect a plan change, call this to (re)set the window “now”.
function reset_credits_for_new_plan(PDO $db, int $userId, int $planId): void {
  upsert_credits_window($db, $userId, $planId, time());
}

// When an invoice is paid (cycle renewal), reset the window to the invoice period start.
function reset_credits_for_cycle(PDO $db, int $userId, int $planId, ?int $periodStartTs): void {
  if ($periodStartTs) {
    upsert_credits_window($db, $userId, $planId, $periodStartTs);
  } else {
    // Fallback to now if we couldn't find a period start
    upsert_credits_window($db, $userId, $planId, time());
  }
}

/* ---------- process ---------- */
$db      = pdo();
$eventId = $event['id'] ?? null;
if ($eventId && has_event($db, $eventId)) {
  http_response_code(200);
  echo 'already processed';
  exit;
}

try {
  switch ($type) {

    case 'checkout.session.completed': {
      // New subscription created via Checkout
      $session = $event['data']['object'];

      $userId  = isset($session['metadata']['user_id']) ? (int)$session['metadata']['user_id'] : null;
      $priceId = $session['metadata']['plan_price_id'] ?? null; // from subscription_data.metadata
      $planId  = $priceId ? plan_id_from_price($priceId) : null;

      $subId   = $session['subscription'] ?? null;
      $custId  = $session['customer'] ?? null;

      if ($userId) {
        apply_user_plan($db, $userId, $planId, $custId, $subId);
        $st = $db->prepare("UPDATE users SET subscription_status = 'active' WHERE id = :id");
        $st->execute([':id' => $userId]);

        // Start credits window now for the chosen plan
        if ($planId) reset_credits_for_new_plan($db, $userId, $planId);

        // Email
        $user     = find_user($db, $userId, $custId);
        $planName = plan_name_from_id($db, $planId) ?? 'your plan';
        if ($user) {
          $tpl = tmpl_subscription_started($user['name'] ?? '', $planName, manage_billing_url());
          notify_user($user, $tpl);
        }
      }
      break;
    }

    case 'invoice_payment.paid': {
      // Payment recorded (some API versions)
      $ip = $event['data']['object'];
      $invoiceId     = $ip['invoice'] ?? null;
      $amountPaid    = $ip['amount_paid'] ?? null;
      $currency      = $ip['currency'] ?? null;
      $paymentIntent = $ip['payment']['payment_intent'] ?? null;

      // Pull invoice to get period + sub
      $subscriptionId = null;
      $customerId     = null;
      $periodStartTs  = null;
      if ($invoiceId) {
        $invoice = stripe_request('GET', "/v1/invoices/{$invoiceId}");
        $subscriptionId = $invoice['subscription'] ?? null;
        $customerId     = $invoice['customer'] ?? null;

        // Try to grab the first line's period start
        if (!empty($invoice['lines']['data'][0]['period']['start'])) {
          $periodStartTs = (int)$invoice['lines']['data'][0]['period']['start'];
        } elseif (!empty($invoice['period_start'])) {
          $periodStartTs = (int)$invoice['period_start'];
        }
      }

      $userId = null; $planId = null; $priceId = null;
      if ($subscriptionId) {
        $sub = stripe_request('GET', "/v1/subscriptions/{$subscriptionId}");
        $md  = $sub['metadata'] ?? [];
        $userId  = isset($md['user_id']) ? (int)$md['user_id'] : null;
        $priceId = $md['plan_price_id'] ?? ($sub['items']['data'][0]['price']['id'] ?? null);
        $planId  = $priceId ? plan_id_from_price($priceId) : null;
      }

      if ($userId) {
        apply_user_plan($db, $userId, $planId, $customerId, $subscriptionId);
        $st = $db->prepare("UPDATE users SET subscription_status = 'active' WHERE id = :id");
        $st->execute([':id'=>$userId]);

        // Reset credits at cycle start
        if ($planId) reset_credits_for_cycle($db, $userId, $planId, $periodStartTs);
      }

      record_payment($db, [
        'user_id'                => $userId ?? 0,
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

      // Email
      $user = find_user($db, $userId, $customerId);
      if ($user && $invoiceId) {
        $amountStr = fmt_amount($amountPaid, $currency);
        $tpl = tmpl_payment_received($user['name'] ?? '', $amountStr, $invoiceId, manage_billing_url());
        notify_user($user, $tpl);
      }
      break;
    }

    case 'invoice.paid':
    case 'invoice.payment_succeeded': {
      // Invoice object directly
      $invoice = $event['data']['object'];
      $invoiceId      = $invoice['id'] ?? null;
      $subscriptionId = $invoice['subscription'] ?? null;
      $customerId     = $invoice['customer'] ?? null;
      $amountPaid     = $invoice['amount_paid'] ?? null;
      $currency       = $invoice['currency'] ?? null;
      $paymentIntent  = $invoice['payment_intent'] ?? null;

      // Period start (for resetting credits)
      $periodStartTs = null;
      if (!empty($invoice['lines']['data'][0]['period']['start'])) {
        $periodStartTs = (int)$invoice['lines']['data'][0]['period']['start'];
      } elseif (!empty($invoice['period_start'])) {
        $periodStartTs = (int)$invoice['period_start'];
      }

      $userId = null; $planId = null; $priceId = null;
      if ($subscriptionId) {
        $sub = stripe_request('GET', "/v1/subscriptions/{$subscriptionId}");
        $md  = $sub['metadata'] ?? [];
        $userId  = isset($md['user_id']) ? (int)$md['user_id'] : null;
        $priceId = $md['plan_price_id'] ?? ($sub['items']['data'][0]['price']['id'] ?? null);
        $planId  = $priceId ? plan_id_from_price($priceId) : null;
      }

      if ($userId) {
        apply_user_plan($db, $userId, $planId, $customerId, $subscriptionId);
        $st = $db->prepare("UPDATE users SET subscription_status = 'active' WHERE id = :id");
        $st->execute([':id'=>$userId]);

        // Reset credits at cycle start
        if ($planId) reset_credits_for_cycle($db, $userId, $planId, $periodStartTs);
      }

      record_payment($db, [
        'user_id'                => $userId ?? 0,
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

      // Email
      $user = find_user($db, $userId, $customerId);
      if ($user && $invoiceId) {
        $amountStr = fmt_amount($amountPaid, $currency);
        $tpl = tmpl_payment_received($user['name'] ?? '', $amountStr, $invoiceId, manage_billing_url());
        notify_user($user, $tpl);
      }
      break;
    }

    case 'customer.subscription.updated': {
      // Plan switches and cancel-at-period-end
      $sub = $event['data']['object'];

      $subscriptionId    = $sub['id'] ?? null;
      $customerId        = $sub['customer'] ?? null;
      $status            = $sub['status'] ?? null;
      $cancelAtPeriodEnd = !empty($sub['cancel_at_period_end']);
      $endedAt           = $sub['ended_at'] ?? null;
      $md                = $sub['metadata'] ?? [];
      $userId            = isset($md['user_id']) ? (int)$md['user_id'] : null;

      $priceId = $md['plan_price_id'] ?? ($sub['items']['data'][0]['price']['id'] ?? null);
      $planId  = $priceId ? plan_id_from_price($priceId) : null;

      // Before-update snapshot for email diff
      $userBefore = find_user($db, $userId, $customerId);
      $prevPlanId = $userBefore['membership_plan'] ?? null;

      if ($userId) {
        if ($cancelAtPeriodEnd || $status === 'canceled' || !empty($endedAt) || $status === 'incomplete_expired') {
          $st = $db->prepare("UPDATE users SET membership_plan = NULL, stripe_subscription_id = NULL, subscription_status = 'cancelled' WHERE id = :id");
          $st->execute([':id' => $userId]);
          // If you want to zero credits immediately on cancel, uncomment:
          // $db->prepare("UPDATE user_credits SET credits_limit = 0 WHERE user_id = :id")->execute([':id'=>$userId]);
        } else {
          if ($planId !== null) {
            $db->prepare("UPDATE users SET membership_plan = :p WHERE id = :id")->execute([':p'=>$planId, ':id'=>$userId]);
            // Reset credit window NOW on plan switch (so limits match new plan immediately)
            reset_credits_for_new_plan($db, $userId, $planId);
          }
          if (!empty($status)) {
            $db->prepare("UPDATE users SET subscription_status = :st WHERE id = :id")->execute([':st'=>$status, ':id'=>$userId]);
          }
          $db->prepare("UPDATE users 
                          SET stripe_customer_id = COALESCE(NULLIF(stripe_customer_id,''), :c), 
                              stripe_subscription_id = COALESCE(:s, stripe_subscription_id)
                        WHERE id = :id")
             ->execute([':c'=>$customerId, ':s'=>$subscriptionId, ':id'=>$userId]);
        }
      }

      // Emails
      $user = $userBefore ?: find_user($db, $userId, $customerId);
      if ($user) {
        if ($cancelAtPeriodEnd && empty($endedAt)) {
          $tpl = tmpl_cancellation_scheduled($user['name'] ?? '', manage_billing_url());
          notify_user($user, $tpl);
        }
        if ($planId && $prevPlanId && (int)$planId !== (int)$prevPlanId) {
          $newName = plan_name_from_id($db, $planId) ?? 'new plan';
          $oldName = plan_name_from_id($db, (int)$prevPlanId) ?? 'previous plan';
          $tpl = tmpl_subscription_updated($user['name'] ?? '', $oldName, $newName, manage_billing_url());
          notify_user($user, $tpl);
        }
      }
      break;
    }

    case 'customer.subscription.deleted': {
      // Fully cancelled/ended subscription
      $sub        = $event['data']['object'];
      $md         = $sub['metadata'] ?? [];
      $userId     = isset($md['user_id']) ? (int)$md['user_id'] : null;
      $customerId = $sub['customer'] ?? null;

      if ($userId) {
        $db->prepare("UPDATE users 
                         SET membership_plan = NULL,
                             subscription_status = 'cancelled',
                             stripe_subscription_id = NULL
                       WHERE id = :id")->execute([':id'=>$userId]);
        // If you want to immediately zero credits on full cancel, uncomment:
        // $db->prepare("UPDATE user_credits SET credits_limit = 0 WHERE user_id = :id")->execute([':id'=>$userId]);
      }

      $user = find_user($db, $userId, $customerId);
      if ($user) {
        $tpl = tmpl_subscription_cancelled($user['name'] ?? '', manage_billing_url());
        notify_user($user, $tpl);
      }
      break;
    }

    default:
      // ignore others
      break;
  }

  if (!empty($event['id'])) mark_event($db, $event['id']);
  http_response_code(200);
  echo 'ok';
} catch (Throwable $e) {
  http_response_code(500);
  echo 'Webhook error: ' . $e->getMessage();
}
