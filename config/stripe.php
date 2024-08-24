<?php
// config/stripe.php
require_once __DIR__ . '/../env.php';
loadEnv(__DIR__ . '/../.env');

$STRIPE_SECRET = $_ENV['STRIPE_SECRET_KEY'] ?? '';
if (!$STRIPE_SECRET) {
    throw new Exception("Missing STRIPE_SECRET_KEY in .env");
}

$CA_FILE = realpath(__DIR__ . '/cacert.pem');
if (!$CA_FILE) {
    throw new Exception("cacert.pem missing in config/");
}

/**
 * Stripe API request helper
 */
function stripe_request(string $method, string $path, array $params = []) {
    global $STRIPE_SECRET, $CA_FILE;

    $url = "https://api.stripe.com{$path}";
    $ch  = curl_init();

    $headers = [
        "Authorization: Bearer {$STRIPE_SECRET}",
        "Content-Type: application/x-www-form-urlencoded",
    ];

    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CAINFO => $CA_FILE,
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }

    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    if ($res === false) {
        throw new Exception("Stripe cURL error: " . curl_error($ch));
    }

    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($res, true);
    if ($http >= 400) {
        $msg = $json['error']['message'] ?? "HTTP $http error";
        throw new Exception($msg);
    }

    return $json;
}
