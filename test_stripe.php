<?php
require_once __DIR__ . '/config/stripe.php';

try {
  $balance = stripe_request('GET', '/v1/balance');
  echo "<pre>"; print_r($balance); echo "</pre>";
} catch (Exception $e) {
  echo "Stripe error: " . $e->getMessage();
}
