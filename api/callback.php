<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$reference = mdp_safe_reference((string) ($_GET['reference'] ?? $_GET['trxref'] ?? $_GET['reference'] ?? ''));
$pendingPath = mdp_storage_path('pending', $reference . '.json');
$pending = is_file($pendingPath) ? json_decode((string) file_get_contents($pendingPath), true) : null;

if (!is_array($pending)) {
    http_response_code(404);
    echo 'Checkout session not found.';
    exit;
}

$result = mdp_finish_verified_payment(
    $reference,
    (string) ($pending['customer_name'] ?? ''),
    (string) ($pending['email'] ?? ''),
    (bool) ($pending['monthly_terms'] ?? false),
    is_array($pending['cart'] ?? null) ? $pending['cart'] : []
);

if (!$result['ok']) {
    $pending['status'] = 'failed';
    $pending['failure_message'] = $result['message'];
    $pending['failed_at'] = gmdate('c');
    mdp_write_json($pendingPath, $pending);
    http_response_code((int) $result['status']);
    echo mdp_h((string) $result['message']);
    exit;
}

$pending['status'] = 'complete';
$pending['receipt_url'] = $result['receipt_url'];
$pending['completed_at'] = gmdate('c');
mdp_write_json($pendingPath, $pending);

header('Location: ' . $result['receipt_url'], true, 302);
exit;
