<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require dirname(__DIR__) . '/includes/bootstrap.php';

$now = time();
$processed = 0;
$charged = 0;
$failed = 0;

foreach (glob(mdp_storage_path('subscriptions', '*.json')) ?: [] as $path) {
    $subscription = json_decode((string) file_get_contents($path), true);

    if (!is_array($subscription) || ($subscription['status'] ?? '') !== 'active') {
        continue;
    }

    $nextCharge = strtotime((string) ($subscription['next_charge_at'] ?? ''));

    if ($nextCharge === false || $nextCharge > $now) {
        continue;
    }

    $processed++;
    $displayAmount = (float) ($subscription['amount'] ?? 0);
    $displayCurrency = strtoupper((string) ($subscription['display_currency'] ?? $subscription['currency'] ?? mdp_display_currency()));
    $chargeCurrency = strtoupper((string) ($subscription['charge_currency'] ?? mdp_charge_currency()));
    $liveExchangeRate = null;

    if ($chargeCurrency === 'GHS' && $displayCurrency === 'USD') {
        $liveExchangeRate = mdp_usd_to_ghs_rate();
        $chargeAmountCents = (int) round($displayAmount * $liveExchangeRate * 100);
        $subscription['charge_amount_cents'] = $chargeAmountCents;
        $subscription['charge_amount'] = $chargeAmountCents / 100;
        $subscription['exchange_rate_usd_to_ghs'] = $liveExchangeRate;
        $subscription['exchange_rate_updated_at'] = gmdate('c');
    } else {
        $chargeAmountCents = (int) ($subscription['charge_amount_cents'] ?? mdp_display_to_charge_cents($displayAmount));
    }

    $response = mdp_paystack_request('POST', 'transaction/charge_authorization', [
        'authorization_code' => (string) $subscription['authorization_code'],
        'email' => (string) $subscription['email'],
        'amount' => $chargeAmountCents,
        'currency' => $chargeCurrency,
        'reference' => 'mdp_' . date('Ymd') . '_' . bin2hex(random_bytes(6)),
        'metadata' => [
            'subscription_reference' => (string) $subscription['reference'],
            'product_count' => count($subscription['items'] ?? []),
            'display_currency' => $displayCurrency,
            'display_amount' => $displayAmount,
            'charge_currency' => $chargeCurrency,
            'charge_amount' => $chargeAmountCents / 100,
            'exchange_rate_usd_to_ghs' => $liveExchangeRate,
        ],
    ]);

    if ($response['ok']) {
        $data = $response['body']['data'] ?? [];
        $reference = mdp_safe_reference((string) ($data['reference'] ?? ('mdp_' . bin2hex(random_bytes(6)))));
        $receiptUrl = mdp_app_url('/receipt.php?ref=' . rawurlencode($reference));
        $manageUrl = mdp_app_url('/manage.php?token=' . rawurlencode((string) $subscription['manage_token']));
        $receipt = [
            'reference' => $reference,
            'customer_name' => (string) $subscription['customer_name'],
            'email' => (string) $subscription['email'],
            'amount_cents' => (int) $subscription['amount_cents'],
            'amount' => (float) $subscription['amount'],
            'currency' => $displayCurrency,
            'display_currency' => $displayCurrency,
            'charge_amount_cents' => $chargeAmountCents,
            'charge_amount' => $chargeAmountCents / 100,
            'charge_currency' => $chargeCurrency,
            'exchange_rate_usd_to_ghs' => $liveExchangeRate,
            'items' => $subscription['items'],
            'recurring_accepted' => true,
            'paystack_status' => strtolower((string) ($data['status'] ?? 'success')),
            'paid_at' => gmdate('c'),
            'receipt_url' => $receiptUrl,
            'manage_url' => $manageUrl,
        ];
        mdp_write_json(mdp_storage_path('receipts', $reference . '.json'), $receipt);
        $subscription['last_charge_at'] = gmdate('c');
        $subscription['next_charge_at'] = gmdate('c', strtotime('+1 month'));
        $subscription['failure_count'] = 0;
        $charged++;
    } else {
        $subscription['failure_count'] = ((int) ($subscription['failure_count'] ?? 0)) + 1;
        $subscription['last_failure_at'] = gmdate('c');
        $subscription['last_failure_message'] = (string) ($response['body']['message'] ?? 'Charge failed.');
        $subscription['next_charge_at'] = gmdate('c', strtotime('+1 day'));
        $failed++;
    }

    mdp_write_json($path, $subscription);
}

echo '[' . gmdate('c') . "] processed={$processed} charged={$charged} failed={$failed}" . PHP_EOL;
