<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/billing-calendar.php';

function mdp_billing_save(string $path, array $payload): void
{
    $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !rename($tmp, $path)) {
        throw new RuntimeException('Unable to persist billing state safely.');
    }
    chmod($path, 0600);
}

function mdp_billing_next_month(string $paidAt, int $billingDay): string
{
    $paid = new DateTimeImmutable($paidAt);
    $paid = $paid->setTimezone(new DateTimeZone('UTC'));
    $month = $paid->modify('first day of next month');
    return $month->setDate((int) $month->format('Y'), (int) $month->format('m'), min($billingDay, (int) $month->format('t')))->format('c');
}

function mdp_billing_retry_deadline(string $startedAt): string
{
    return (new DateTimeImmutable($startedAt))->setTimezone(new DateTimeZone('UTC'))->modify('+3 years')->format('c');
}

function mdp_billing_stop_retries(array &$subscription): void
{
    $subscription['status'] = 'cancelled';
    $subscription['cancelled_at'] = gmdate('c');
    $subscription['cancellation_reason'] = 'No successful payment within three years of the first failed renewal.';
    $subscription['next_charge_at'] = null;
}

function mdp_billing_collect(string $path, array &$subscription, int $now, bool $dryRun, int &$charged, int &$failed): bool
{
    $calendarBilling = isset($subscription['billing_calendar']);
    $installment = $calendarBilling ? $subscription['billing_calendar']['open'][0] : $subscription;
    $displayAmount = (float) ($installment['amount_cents'] ?? 0) / 100;
    $displayCurrency = strtoupper((string) ($installment['display_currency'] ?? $installment['currency'] ?? mdp_display_currency()));
    $chargeCurrency = strtoupper((string) ($installment['charge_currency'] ?? mdp_charge_currency()));
    $liveExchangeRate = null;

    if ($chargeCurrency === 'GHS' && $displayCurrency === 'USD') {
        $liveExchangeRate = mdp_usd_to_ghs_rate();
        $chargeAmountCents = (int) round($displayAmount * $liveExchangeRate * 100);
        $subscription['charge_amount_cents'] = $chargeAmountCents;
        $subscription['charge_amount'] = $chargeAmountCents / 100;
        $subscription['exchange_rate_usd_to_ghs'] = $liveExchangeRate;
        $subscription['exchange_rate_updated_at'] = gmdate('c');
    } else {
        $chargeAmountCents = (int) ($installment['charge_amount_cents'] ?? mdp_display_to_charge_cents($displayAmount));
    }

    if ($dryRun) {
        echo json_encode(['subscription' => $subscription['reference'], 'due_at' => $installment['due_at'] ?? $subscription['next_charge_at'], 'amount' => $chargeAmountCents / 100, 'currency' => $chargeCurrency, 'existing_attempt' => isset($subscription['billing_attempt']), 'open_installments' => count($subscription['billing_calendar']['open'] ?? []), 'next_bill_at' => $subscription['billing_calendar']['next_bill_at'] ?? null]), PHP_EOL;
        return false;
    }

    $attempt = $subscription['billing_attempt'] ?? null;
    if (!is_array($attempt)) {
        // Validate the saved authorization without initiating a debit.
        $original = mdp_paystack_request('GET', 'transaction/verify/' . rawurlencode($subscription['reference']));
        $originalData = $original['body']['data'] ?? [];
        if (!$original['ok'] || ($originalData['status'] ?? '') !== 'success'
            || ($originalData['authorization']['reusable'] ?? false) !== true
            || ($originalData['authorization']['authorization_code'] ?? '') !== ($subscription['authorization_code'] ?? '')
            || strcasecmp((string) ($originalData['customer']['email'] ?? ''), (string) $subscription['email']) !== 0) {
            echo json_encode(['subscription' => $subscription['reference'], 'state' => 'authorization_check_failed']), PHP_EOL;
            $failed++;
            return false;
        }
        $attempt = [
            'reference' => 'mdpr_' . bin2hex(random_bytes(16)),
            'started_at' => gmdate('c'),
            'due_at' => $installment['due_at'] ?? $subscription['next_charge_at'],
            'amount_cents' => $chargeAmountCents,
            'currency' => $chargeCurrency,
            'exchange_rate' => $liveExchangeRate,
        ];
        // Persist before sending. An interrupted/uncertain request must be reconciled,
        // never submitted again using a fresh reference.
        if (!$calendarBilling) {
            $subscription['retry_started_at'] = $subscription['retry_started_at'] ?? $attempt['started_at'];
            $subscription['retry_until'] = mdp_billing_retry_deadline($subscription['retry_started_at']);
        }
        $subscription['billing_attempt'] = $attempt;
        mdp_billing_save($path, $subscription);
        $response = mdp_paystack_request('POST', 'transaction/charge_authorization', [
            'authorization_code' => (string) $subscription['authorization_code'],
            'email' => (string) $subscription['email'],
            'amount' => $chargeAmountCents,
            'currency' => $chargeCurrency,
            'reference' => $attempt['reference'],
            'metadata' => [
                'subscription_reference' => (string) $subscription['reference'],
                'billing_due_at' => $attempt['due_at'],
                'product_count' => count($installment['items'] ?? []),
                'display_currency' => $displayCurrency,
                'display_amount' => $displayAmount,
                'charge_currency' => $chargeCurrency,
                'charge_amount' => $chargeAmountCents / 100,
                'exchange_rate_usd_to_ghs' => $liveExchangeRate,
            ],
        ]);

        $requestMessage = (string) ($response['body']['message'] ?? '');
        if ($calendarBilling && $response['status'] === 400
            && preg_match('/^Charge attempt cannot be fulfilled until \d+ minutes?\.?$/i', $requestMessage)) {
            $subscription['billing_attempt']['processor_decline'] = $requestMessage;
            mdp_billing_save($path, $subscription);
        }
    } else {
        $requestMessage = 'Reconciling previously initiated payment.';
    }
    $chargeAmountCents = (int) $attempt['amount_cents'];
    $chargeCurrency = (string) $attempt['currency'];
    $liveExchangeRate = $attempt['exchange_rate'] ?? null;
    $verified = mdp_paystack_request('GET', 'transaction/verify/' . rawurlencode($attempt['reference']));
    $data = $verified['body']['data'] ?? [];
    $paymentStatus = $verified['ok'] ? strtolower((string) ($data['status'] ?? 'unknown')) : 'unknown';
    $matches = ($data['reference'] ?? '') === $attempt['reference']
        && (int) ($data['amount'] ?? -1) === $chargeAmountCents
        && strtoupper((string) ($data['currency'] ?? '')) === $chargeCurrency
        && strtolower((string) ($data['customer']['email'] ?? '')) === strtolower((string) $subscription['email']);
    $regulated = isset($subscription['billing_attempt']['processor_decline']) && $verified['status'] === 400
        && ($verified['body']['code'] ?? '') === 'transaction_not_found';
    if ($regulated) {
        $paymentStatus = 'processor_cooldown';
        $requestMessage = $subscription['billing_attempt']['processor_decline'];
    }
    $subscription['billing_attempt']['last_checked_at'] = gmdate('c');
    $subscription['billing_attempt']['status'] = $paymentStatus;
    $subscription['billing_attempt']['gateway_response'] = (string) ($data['gateway_response'] ?? $requestMessage);

    if ($paymentStatus === 'success' && $matches) {
        // Payment was independently verified against the persisted attempt.
        $reference = $attempt['reference'];
        $receiptUrl = mdp_app_url('/receipt.php?ref=' . rawurlencode($reference));
        $manageUrl = mdp_app_url('/manage.php?token=' . rawurlencode((string) $subscription['manage_token']));
        $receipt = [
            'reference' => $reference,
            'customer_name' => (string) $subscription['customer_name'],
            'email' => (string) $subscription['email'],
            'amount_cents' => (int) $installment['amount_cents'],
            'amount' => $displayAmount,
            'billing_due_at' => $attempt['due_at'] ?? null,
            'currency' => $displayCurrency,
            'display_currency' => $displayCurrency,
            'charge_amount_cents' => $chargeAmountCents,
            'charge_amount' => $chargeAmountCents / 100,
            'charge_currency' => $chargeCurrency,
            'exchange_rate_usd_to_ghs' => $liveExchangeRate,
            'items' => $installment['items'],
            'recurring_accepted' => true,
            'paystack_status' => 'success',
            'paid_at' => (string) ($data['paid_at'] ?? gmdate('c')),
            'receipt_url' => $receiptUrl,
            'manage_url' => $manageUrl,
        ];
        mdp_billing_save(mdp_storage_path('receipts', $reference . '.json'), $receipt);
        $subscription['last_charge_at'] = (string) ($data['paid_at'] ?? gmdate('c'));
        $subscription['last_transaction_id'] = $data['id'] ?? null;
        $subscription['last_transaction_reference'] = $reference;
        $subscription['last_billing_attempt'] = $subscription['billing_attempt'];
        unset($subscription['billing_attempt']);
        if ($calendarBilling) {
            array_shift($subscription['billing_calendar']['open']);
            unset($subscription['billing_calendar']['retry_at']);
            $subscription['next_charge_at'] = $subscription['billing_calendar']['open'][0]['due_at']
                ?? $subscription['billing_calendar']['next_bill_at'];
        } else {
            $subscription['billing_day'] = (int) ($subscription['billing_day'] ?? gmdate('j', strtotime($subscription['last_charge_at'])));
            $subscription['next_charge_at'] = mdp_billing_next_month($subscription['last_charge_at'], $subscription['billing_day']);
        }
        $subscription['failure_count'] = 0;
        unset($subscription['retry_started_at'], $subscription['retry_until']);
        $charged++;
    } elseif ($regulated || ($verified['ok'] && $matches && in_array($paymentStatus, ['failed', 'abandoned', 'reversed'], true))) {
        $subscription['last_billing_attempt'] = $subscription['billing_attempt'];
        unset($subscription['billing_attempt']);
        $subscription['failure_count'] = ((int) ($subscription['failure_count'] ?? 0)) + 1;
        $subscription['last_failure_at'] = gmdate('c');
        $subscription['last_failure_message'] = (string) ($data['gateway_response'] ?? $requestMessage);
        $subscription['next_charge_at'] = gmdate('c', time() + 86400);
        if ($calendarBilling) {
            $subscription['billing_calendar']['retry_at'] = $subscription['next_charge_at'];
        }
        unset($subscription['billing_review_required']);
        $failed++;
    } else {
        // Includes pending, timeouts, mismatches, and unknown references. Retain the
        // attempt for reconciliation and do not advance paid-through dates.
        $subscription['billing_review_required'] = true;
    }

    if (!$calendarBilling && isset($subscription['retry_until']) && !($paymentStatus === 'success' && $matches) && $now >= strtotime($subscription['retry_until'])) {
        mdp_billing_stop_retries($subscription);
    }
    if ($paymentStatus === 'success' && $matches) {
        unset($subscription['billing_review_required']);
    }
    echo json_encode(['subscription' => $subscription['reference'], 'reference' => $attempt['reference'], 'transaction_id' => $data['id'] ?? null, 'state' => $paymentStatus, 'verified_matches' => $matches, 'amount' => $chargeAmountCents / 100, 'currency' => $chargeCurrency, 'gateway_response' => $data['gateway_response'] ?? $requestMessage, 'next_charge_at' => $subscription['next_charge_at']]), PHP_EOL;
    mdp_billing_save($path, $subscription);
    return $paymentStatus === 'success' && $matches;
}

$lock = fopen(mdp_storage_path('logs', 'monthly-billing.lock'), 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Billing already running or lock unavailable.\n");
    exit(1);
}
$dryRun = in_array('--dry-run', $argv, true);
$now = time();
$processed = 0;
$charged = 0;
$failed = 0;
if (in_array('--configure-calendar', $argv, true)) {
    try {
        $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $updates = [];
        foreach ($input['subscriptions'] ?? [$input] as $plan) {
            $reference = $plan['reference'] ?? '';
            if (!is_string($reference) || $reference === '' || mdp_safe_reference($reference) !== $reference
                || !is_array($plan['overdue'] ?? null)) {
                throw new RuntimeException('A subscription reference and an explicit overdue list are required.');
            }
            $path = mdp_storage_path('subscriptions', $reference . '.json');
            if (!is_file($path) || isset($updates[$path])) {
                throw new RuntimeException('Each subscription must exist and be specified once.');
            }
            $subscription = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $updates[$path] = mdp_billing_calendar_configure($subscription, $plan, $now);
        }
        foreach ($updates as $path => $updated) {
            if (!$dryRun) {
                mdp_billing_save($path, $updated);
            }
            echo json_encode(['state' => $dryRun ? 'would_configure' : 'configured',
                'first_bill_at' => $updated['billing_calendar']['next_bill_at'],
                'overdue_installments' => count($updated['billing_calendar']['open'])]), PHP_EOL;
        }
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}

$calendarGroups = [];
foreach (glob(mdp_storage_path('subscriptions', '*.json')) ?: [] as $path) {
    $subscription = json_decode((string) file_get_contents($path), true);

    if (!is_array($subscription) || ($subscription['status'] ?? '') !== 'active') {
        continue;
    }
    if (isset($subscription['billing_calendar'])) {
        if (mdp_billing_calendar_add_due($subscription, $now) && !$dryRun) {
            mdp_billing_save($path, $subscription);
        }
        $calendarGroups[$subscription['billing_calendar']['group']][$path] = $subscription;
        continue;
    }

    $nextCharge = strtotime((string) ($subscription['next_charge_at'] ?? ''));

    if ($nextCharge === false || $nextCharge > $now) {
        continue;
    }

    $retryStarted = $subscription['retry_started_at'] ?? null;
    // Adopt existing failed renewals without resetting their elapsed retry time.
    if ($retryStarted === null && (int) ($subscription['failure_count'] ?? 0) > 0) {
        $retryStarted = $subscription['last_failure_at'] ?? gmdate('c');
        $subscription['retry_started_at'] = $retryStarted;
    }
    if ($retryStarted !== null) {
        $subscription['retry_until'] = mdp_billing_retry_deadline($retryStarted);
    }
    $retryExpired = $retryStarted !== null && $now >= strtotime($subscription['retry_until']);
    if ($retryExpired && !isset($subscription['billing_attempt'])) {
        echo json_encode(['subscription' => $subscription['reference'], 'state' => $dryRun ? 'would_stop_retries' : 'retry_period_expired', 'retry_until' => $subscription['retry_until']]), PHP_EOL;
        if (!$dryRun) {
            mdp_billing_stop_retries($subscription);
            mdp_billing_save($path, $subscription);
        }
        continue;
    }

    $processed++;
    mdp_billing_collect($path, $subscription, $now, $dryRun, $charged, $failed);
}

foreach ($calendarGroups as $group) {
    foreach ($group as $subscription) {
        if (strtotime($subscription['billing_calendar']['retry_at'] ?? '') > $now) {
            continue 2;
        }
    }

    $open = array_filter($group, static fn (array $s): bool => $s['billing_calendar']['open'] !== []);
    while ($open !== []) {
        uasort($open, static function (array $a, array $b): int {
            $first = $a['billing_calendar']['open'][0];
            $second = $b['billing_calendar']['open'][0];
            return [!isset($a['billing_attempt']), $first['due_at'], $first['amount_cents']]
                <=> [!isset($b['billing_attempt']), $second['due_at'], $second['amount_cents']];
        });
        $path = array_key_first($open);
        $processed++;
        if (!mdp_billing_collect($path, $open[$path], $now, $dryRun, $charged, $failed)) {
            break;
        }
        if ($open[$path]['billing_calendar']['open'] === []) {
            unset($open[$path]);
        }
    }
}

echo '[' . gmdate('c') . "] processed={$processed} charged={$charged} failed={$failed}" . PHP_EOL;
