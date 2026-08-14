<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mdp_json_response(['message' => 'Method not allowed.'], 405);
}

$payload = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($payload)) {
    mdp_json_response(['message' => 'Invalid checkout payload.'], 422);
}

$email = filter_var((string) ($payload['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$name = trim((string) ($payload['name'] ?? ''));
$acceptedTerms = (bool) ($payload['monthly_terms'] ?? false);
$cart = is_array($payload['cart'] ?? null) ? $payload['cart'] : [];
$summary = mdp_cart_summary($cart);

if (!$email || $name === '' || $summary['total_cents'] < 1 || $summary['items'] === []) {
    mdp_json_response(['message' => 'Add products and provide customer details before checkout.'], 422);
}

$secret = mdp_env('PAYSTACK_SECRET_KEY');

if ($secret === '' || !str_starts_with($secret, 'sk_')) {
    mdp_json_response(['message' => 'Paystack secret key is not configured.'], 500);
}

$reference = 'mdp_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(6));
$callbackUrl = mdp_app_url('/api/callback.php?reference=' . rawurlencode($reference));
$pendingPath = mdp_storage_path('pending', $reference . '.json');
$pending = [
    'reference' => $reference,
    'customer_name' => $name,
    'email' => (string) $email,
    'cart' => mdp_normalize_cart($cart),
    'summary' => $summary,
    'monthly_terms' => $acceptedTerms,
    'created_at' => gmdate('c'),
    'status' => 'pending',
];
mdp_write_json($pendingPath, $pending);

$initializeTransaction = static function (array $checkoutSummary) use ($email, $reference, $callbackUrl, $name, $acceptedTerms): array {
    return mdp_paystack_request('POST', 'transaction/initialize', [
        'email' => (string) $email,
        'amount' => $checkoutSummary['charge_cents'],
        'currency' => $checkoutSummary['charge_currency'],
        'reference' => $reference,
        'callback_url' => $callbackUrl,
        'metadata' => [
            'customer_name' => $name,
            'display_currency' => $checkoutSummary['display_currency'],
            'display_amount' => $checkoutSummary['total'],
            'charge_currency' => $checkoutSummary['charge_currency'],
            'charge_amount' => $checkoutSummary['charge_total'],
            'monthly_billing_accepted' => $acceptedTerms,
            'items' => $checkoutSummary['items'],
        ],
    ]);
};

$response = $initializeTransaction($summary);
$paystackMessage = (string) ($response['body']['message'] ?? '');

if (!$response['ok'] && $summary['charge_currency'] === 'USD' && str_contains(strtolower($paystackMessage), 'currency')) {
    $fallbackRate = mdp_usd_to_ghs_rate();
    $fallbackChargeCents = (int) round($summary['total'] * $fallbackRate * 100);
    $summary['charge_currency'] = 'GHS';
    $summary['charge_cents'] = $fallbackChargeCents;
    $summary['charge_total'] = $fallbackChargeCents / 100;
    $pending['summary'] = $summary;
    $pending['currency_fallback'] = 'USD_TO_GHS';
    $pending['fallback_rate'] = $fallbackRate;
    mdp_write_json($pendingPath, $pending);
    $response = $initializeTransaction($summary);
}

if (!$response['ok']) {
    $pending['status'] = 'failed';
    $pending['failure_message'] = $response['body']['message'] ?? 'Unable to initialize Paystack transaction.';
    $pending['failed_at'] = gmdate('c');
    mdp_write_json($pendingPath, $pending);
    mdp_json_response([
        'message' => $response['body']['message'] ?? 'Unable to initialize Paystack transaction.',
    ], $response['status'] ?: 502);
}

$data = $response['body']['data'] ?? [];

if (empty($data['authorization_url'])) {
    mdp_json_response(['message' => 'Paystack did not return a checkout URL.'], 502);
}

mdp_json_response([
    'reference' => $reference,
    'authorization_url' => (string) $data['authorization_url'],
    'display_total' => $summary['total'],
    'display_currency' => $summary['display_currency'],
    'charge_total' => $summary['charge_total'],
    'charge_currency' => $summary['charge_currency'],
]);
