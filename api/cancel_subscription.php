<?php
// api/cancel_subscription.php
session_start();
require_once __DIR__ . '/../config/stripe.php'; // stripe_request()
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'message' => 'Not logged in']);
  exit;
}

$userId = (int)$_SESSION['user_id'];

try {
  $db = (new Database())->getConnection();

  $st = $db->prepare("SELECT stripe_subscription_id FROM users WHERE id = :id LIMIT 1");
  $st->execute([':id' => $userId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  $subId = $row['stripe_subscription_id'] ?? null;
  if (!$subId) {
    echo json_encode(['success' => false, 'message' => 'No active subscription found.']);
    exit;
  }

  $updated = stripe_request('POST', "/v1/subscriptions/{$subId}", [
    'cancel_at_period_end' => 'true'
  ]);

  $up = $db->prepare("UPDATE users SET subscription_status = 'canceled' WHERE id = :id");
  $up->execute([':id' => $userId]);

  echo json_encode(['success' => true, 'message' => 'Subscription set to cancel at period end.']);

} catch (Throwable $e) {
  echo json_encode(['success' => false, 'message' => 'Stripe error: ' . $e->getMessage()]);
}
