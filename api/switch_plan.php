<?php
// api/switch_plan.php
session_start();
require_once __DIR__ . '/../config/stripe.php'; // stripe_request()
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../env.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'message' => 'Not logged in']);
  exit;
}

loadEnv(__DIR__ . '/../.env');

$userId  = (int)$_SESSION['user_id'];
$priceId = $_POST['price_id'] ?? '';

if (!$priceId) {
  echo json_encode(['success' => false, 'message' => 'Missing price_id']);
  exit;
}

function map_price_to_db_plan(string $priceId): ?int {
  $basic = $_ENV['ZICBOT_BASIC_30']        ?? '';
  $pro   = $_ENV['ZICBOT_PROFESSIONAL_60'] ?? '';
  $ent   = $_ENV['ZICBOT_ENTERPRISE_100']  ?? '';
  $map = [
    $basic => 1,
    $pro   => 2,
    $ent   => 3,
  ];
  return $map[$priceId] ?? null;
}

try {
  $db = (new Database())->getConnection();

  $st = $db->prepare("SELECT stripe_subscription_id, stripe_customer_id FROM users WHERE id = :id LIMIT 1");
  $st->execute([':id' => $userId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  $subId  = $row['stripe_subscription_id'] ?? null;
  $custId = $row['stripe_customer_id'] ?? null;

  if (!$subId || !$custId) {
    echo json_encode(['success' => false, 'message' => 'No active subscription. Start a subscription first.']);
    exit;
  }

  $sub = stripe_request('GET', "/v1/subscriptions/{$subId}");
  if (empty($sub['items']['data'][0]['id'])) {
    throw new Exception('Could not read current subscription items.');
  }

  $itemId         = $sub['items']['data'][0]['id'];
  $currentPriceId = $sub['items']['data'][0]['price']['id'] ?? '';

  if ($currentPriceId === $priceId) {
    echo json_encode(['success' => true, 'unchanged' => true]);
    exit;
  }

  $planDbId = map_price_to_db_plan($priceId);

  // First: metadata-only call
  $mdParams = [
    'metadata[user_id]'       => $userId,
    'metadata[plan_price_id]' => $priceId,
  ];
  if ($planDbId !== null) {
    $mdParams['metadata[plan_db_id]'] = $planDbId;
  }
  try { stripe_request('POST', "/v1/subscriptions/{$subId}", $mdParams); } catch (Throwable $ignored) {}

  // Then: price switch with allowed params
  $updateParams = [
    'items[0][id]'    => $itemId,
    'items[0][price]' => $priceId,
    'proration_behavior'   => 'create_prorations',
    'billing_cycle_anchor' => 'now',
    'payment_behavior'     => 'pending_if_incomplete',
  ];

  $updated = stripe_request('POST', "/v1/subscriptions/{$subId}", $updateParams);

  if ($planDbId !== null) {
    $up = $db->prepare("UPDATE users SET membership_plan = :p WHERE id = :id");
    $up->execute([':p' => $planDbId, ':id' => $userId]);
  }

  echo json_encode(['success' => true, 'subscription_id' => $updated['id'] ?? $subId]);

} catch (Throwable $e) {
  echo json_encode(['success' => false, 'message' => 'Stripe error: ' . $e->getMessage()] );
}
