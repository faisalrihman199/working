<?php
// api/create_billing_portal.php
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

$userId = (int)$_SESSION['user_id'];
$db     = (new Database())->getConnection();

/** Build dynamic base URL that works on localhost + subfolders */
function app_base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'];
  $base   = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/'); // e.g. /myapp/api
  $root   = preg_replace('~/api$~', '', $base); // -> /myapp
  return $scheme . '://' . $host . $root;
}

/**
 * Build map product_id => [price_id, price_id, ...] from your .env prices.
 * Keeps only ACTIVE + RECURRING prices in the same Stripe mode (test/live) as your key.
 */
function products_with_prices_from_env(): array {
  $envPrices = array_values(array_filter([
    $_ENV['ZICBOT_BASIC_30']        ?? null,
    $_ENV['ZICBOT_PROFESSIONAL_60'] ?? null,
    $_ENV['ZICBOT_ENTERPRISE_100']  ?? null,
  ]));

  $map = []; // product_id => [price_id, ...]
  foreach ($envPrices as $priceId) {
    try {
      $price = stripe_request('GET', "/v1/prices/{$priceId}");
      if (!empty($price['product']) && !empty($price['id'])) {
        $isActive    = ($price['active'] ?? true) === true;
        $isRecurring = ($price['type'] ?? '') === 'recurring'; // 'recurring' or 'one_time'
        if ($isActive && $isRecurring) {
          $prod = $price['product'];
          $map[$prod] = $map[$prod] ?? [];
          $map[$prod][] = $price['id'];
        }
      }
    } catch (\Throwable $e) {
      // Ignore bad/old price ids and continue
    }
  }

  // Dedupe arrays
  foreach ($map as $prod => $prices) {
    $map[$prod] = array_values(array_unique($prices));
  }
  return $map;
}

try {
  // 1) Ensure user has a Stripe customer
  $st = $db->prepare("SELECT stripe_customer_id FROM users WHERE id = :id LIMIT 1");
  $st->execute([':id' => $userId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row || empty($row['stripe_customer_id'])) {
    echo json_encode(['success' => false, 'message' => 'No billing profile found. Start a subscription first.']);
    exit;
  }
  $customerId = $row['stripe_customer_id'];

  // 2) Use an existing config if provided in .env
  $portalConfigId = trim($_ENV['STRIPE_BILLING_PORTAL_CONFIG'] ?? '');

  // 3) Otherwise try to reuse the first ACTIVE configuration
  if (!$portalConfigId) {
    // NOTE: we append query params directly in the path so it works even if stripe_request() doesn't add GET params.
    $existing = stripe_request('GET', '/v1/billing_portal/configurations?active=true&limit=10');
    if (!empty($existing['data'][0]['id'])) {
      $portalConfigId = $existing['data'][0]['id'];
    }
  }

  // 4) Create a configuration programmatically (only if none found)
  if (!$portalConfigId) {
    $prodToPrices = products_with_prices_from_env();
    if (empty($prodToPrices)) {
      echo json_encode([
        'success' => false,
        'message' => 'No valid recurring Prices from env (ZICBOT_*). Check test/live mode, active status, and price IDs.'
      ]);
      exit;
    }

    $baseReturnUrl = app_base_url() . '/billing.php?portal=returned';

    // Base payload
    $payload = [
      'business_profile[headline]'                         => 'Manage your Zicbot subscription',
      'default_return_url'                                 => $baseReturnUrl,

      // Allow card updates & invoice history
      'features[payment_method_update][enabled]'           => 'true',
      'features[invoice_history][enabled]'                 => 'true',

      // Allow customer info updates
      'features[customer_update][enabled]'                 => 'true',
      'features[customer_update][allowed_updates][]'       => 'address',
      'features[customer_update][allowed_updates][]'       => 'shipping',
      'features[customer_update][allowed_updates][]'       => 'tax_id',

      // Allow plan/price switching
      'features[subscription_update][enabled]'             => 'true',
      'features[subscription_update][proration_behavior]'  => 'create_prorations',
      // ✅ Required by some API versions when subscription_update is enabled:
      'features[subscription_update][default_allowed_updates][]' => 'price',

      // Allow cancel (no products block here)
      'features[subscription_cancel][enabled]'             => 'true',
      'features[subscription_cancel][mode]'                => 'at_period_end',
    ];

    // Add per-product allowed prices for switching
    $i = 0;
    foreach ($prodToPrices as $productId => $priceIds) {
      $payload["features[subscription_update][products][{$i}][product]"] = $productId;
      foreach ($priceIds as $priceId) {
        // prices must be plain strings
        $payload["features[subscription_update][products][{$i}][prices][]"] = $priceId;
      }
      $i++;
    }

    $newCfg = stripe_request('POST', '/v1/billing_portal/configurations', $payload);
    $portalConfigId = $newCfg['id'] ?? '';
    if (!$portalConfigId) {
      echo json_encode(['success' => false, 'message' => 'Could not create portal configuration (no id returned).']);
      exit;
    }
  }

  // 5) Create a portal session
  $returnUrl = app_base_url() . '/billing.php?portal=returned';
  $session = stripe_request('POST', '/v1/billing_portal/sessions', [
    'customer'      => $customerId,
    'configuration' => $portalConfigId, // optional if you want Stripe default; explicit for clarity
    'return_url'    => $returnUrl,
  ]);

  echo json_encode(['success' => true, 'url' => $session['url'] ?? null]);

} catch (Throwable $e) {
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
