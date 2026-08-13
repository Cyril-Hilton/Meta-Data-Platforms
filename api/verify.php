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

$result = mdp_finish_verified_payment(
    (string) ($payload['reference'] ?? ''),
    (string) ($payload['name'] ?? ''),
    (string) ($payload['email'] ?? ''),
    (bool) ($payload['monthly_terms'] ?? false),
    is_array($payload['cart'] ?? null) ? $payload['cart'] : []
);

if (!$result['ok']) {
    mdp_json_response(['message' => $result['message']], $result['status']);
}

mdp_json_response([
    'message' => $result['message'],
    'receipt_url' => $result['receipt_url'],
    'manage_url' => $result['manage_url'],
]);
