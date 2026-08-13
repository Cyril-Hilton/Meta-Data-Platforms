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

if (!$email || $name === '' || !$acceptedTerms || $summary['total_cents'] < 1 || $summary['items'] === []) {
    mdp_json_response(['message' => 'Add products and provide customer details with monthly billing consent.'], 422);
}

$reference = 'mdp_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(6));
$callbackUrl = mdp_app_url('/api/callback.php?reference=' . rawurlencode($reference));
$pending = [
    'reference' => $reference,
    'customer_name' => $name,
    'email' => (string) $email,
    'cart' => mdp_normalize_cart($cart),
    'summary' => $summary,
    'monthly_terms' => true,
    'created_at' => gmdate('c'),
    'status' => 'pending',
];
mdp_write_json(mdp_storage_path('pending', $reference . '.json'), $pending);

$response = mdp_paystack_request('POST', 'transaction/initialize', [
    'email' => (string) $email,
    'amount' => $summary['charge_cents'],
    'currency' => $summary['charge_currency'],
    'reference' => $reference,
    'callback_url' => $callbackUrl,
    'metadata' => [
        'customer_name' => $name,
        'display_currency' => $summary['display_currency'],
        'display_amount' => $summary['total'],
        'charge_currency' => $summary['charge_currency'],
        'charge_amount' => $summary['charge_total'],
        'monthly_billing_accepted' => true,
        'items' => $summary['items'],
    ],
]);

if (!$response['ok']) {
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
