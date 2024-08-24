<?php
// api/create_checkout.php
session_start();
require_once __DIR__ . '/../config/stripe.php'; // stripe_request($method, $path, $params = [])
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../env.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}
loadEnv(__DIR__ . '/../.env');

$userId     = (int)$_SESSION['user_id'];
$email      = $_SESSION['user_email'] ?? null;
$restaurant = $_SESSION['restaurant_name'] ?? 'Restaurant';

$priceId = $_POST['plan_id'] ?? '';
if (!$priceId) {
    echo json_encode(['success' => false, 'message' => 'Missing plan ID']);
    exit;
}

function planDbIdFromPrice(string $priceId): ?int
{
    $basic = $_ENV['ZICBOT_BASIC_30']        ?? '';
    $pro   = $_ENV['ZICBOT_PROFESSIONAL_60'] ?? '';
    $ent   = $_ENV['ZICBOT_ENTERPRISE_100']  ?? '';
    $map = [];
    if ($basic) $map[$basic] = 1;
    if ($pro)   $map[$pro]   = 2;
    if ($ent)   $map[$ent]   = 3;
    return $map[$priceId] ?? null;
}

function app_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $root   = preg_replace('~/api$~', '', $base);
    return $scheme . '://' . $host . $root;
}

/**
 * Ensure we have a Stripe Customer linked to this user.
 * 1) Try DB
 * 2) Try Stripe search by email
 * 3) Create new customer (with user_id in metadata), persist to DB
 */
function ensureStripeCustomer(PDO $db, int $userId, ?string $email, ?string $name): ?string
{
    // 1) DB lookup
    $st = $db->prepare("SELECT stripe_customer_id FROM users WHERE id = :id LIMIT 1");
    $st->execute([':id' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['stripe_customer_id'])) {
        return $row['stripe_customer_id'];
    }

    if ($email) {
        // 2) Try search (requires Customers Search enabled on your Stripe account)
        try {
            $res = stripe_request('GET', '/v1/customers/search', [
                'query' => "email:'" . $email . "'",
            ]);
            if (!empty($res['data'][0]['id'])) {
                $custId = $res['data'][0]['id'];
                $upd = $db->prepare("UPDATE users SET stripe_customer_id = :c WHERE id = :id");
                $upd->execute([':c' => $custId, ':id' => $userId]);
                return $custId;
            }
        } catch (Throwable $e) {
            // If search is not enabled/allowed, just fall through to create
        }
    }

    // 3) Create new customer
    try {
        $params = [
            'metadata[user_id]' => (string)$userId,
        ];
        if ($email) $params['email'] = $email;
        if ($name)  $params['name']  = $name;

        $cust = stripe_request('POST', '/v1/customers', $params);
        if (!empty($cust['id'])) {
            $custId = $cust['id'];
            $upd = $db->prepare("UPDATE users SET stripe_customer_id = :c WHERE id = :id");
            $upd->execute([':c' => $custId, ':id' => $userId]);
            return $custId;
        }
    } catch (Throwable $e) {
        // swallow; we can still attempt Checkout with customer_email as last resort
    }

    return null;
}

try {
    $db = (new Database())->getConnection();

    // Make sure we have and persist a Stripe customer for this user
    $stripeCustomerId = ensureStripeCustomer($db, $userId, $email, $restaurant);

    $successUrl = app_base_url() . "/memberships.php?checkout=success&session_id={CHECKOUT_SESSION_ID}";
    $cancelUrl  = app_base_url() . "/memberships.php?checkout=canceled";

    $planDbId = planDbIdFromPrice($priceId);

    $params = [
        'mode'                     => 'subscription',
        'payment_method_types[]'   => 'card',
        'line_items[0][price]'     => $priceId,
        'line_items[0][quantity]'  => 1,
        'success_url'              => $successUrl,
        'cancel_url'               => $cancelUrl,

        // breadcrumbs
        'client_reference_id'      => (string)$userId,
        'metadata[user_id]'        => (string)$userId,
        'metadata[plan_price_id]'  => (string)$priceId,
        'metadata[plan_db_id]'     => (string)($planDbId ?? ''),

        // subscription metadata (your webhook reads this)
        'subscription_data[metadata][user_id]'         => (string)$userId,
        'subscription_data[metadata][restaurant_name]' => (string)$restaurant,
        'subscription_data[metadata][plan_price_id]'   => (string)$priceId,
        'subscription_data[metadata][plan_db_id]'      => (string)($planDbId ?? ''),
    ];
    if ($stripeCustomerId) {
        $params['customer'] = $stripeCustomerId;
    } elseif ($email) {
        $params['customer_email'] = $email;
    }


    // (Optional) tighten checkout experience; uncomment if you need these:
    // $params['allow_promotion_codes'] = 'true';
    // $params['billing_address_collection'] = 'auto'; // or 'required'
    // $params['automatic_tax[enabled]'] = 'true';

    $session = stripe_request('POST', '/v1/checkout/sessions', $params);

    echo json_encode([
        'success'       => true,
        'checkout_url'  => $session['url'] ?? null,
        'session_id'    => $session['id'] ?? null,     // helpful for debugging
        'customer_id'   => $stripeCustomerId,          // you’ll usually have this now
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Stripe error: ' . $e->getMessage()]);
}
