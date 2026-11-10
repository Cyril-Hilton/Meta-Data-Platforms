<?php

declare(strict_types=1);

function mdp_billing_calendar_installment(array $subscription, string $dueAt): array
{
    return [
        'due_at' => $dueAt,
        'amount_cents' => (int) $subscription['amount_cents'],
        'display_currency' => $subscription['display_currency'] ?? $subscription['currency'],
        'charge_amount_cents' => (int) $subscription['charge_amount_cents'],
        'charge_currency' => $subscription['charge_currency'],
        'items' => $subscription['items'],
    ];
}

function mdp_billing_calendar_add_due(array &$subscription, int $now): bool
{
    $changed = false;
    $calendar = &$subscription['billing_calendar'];
    while (strtotime($calendar['next_bill_at']) <= $now) {
        $dueAt = $calendar['next_bill_at'];
        $calendar['open'][] = mdp_billing_calendar_installment($subscription, $dueAt);
        $calendar['next_bill_at'] = mdp_billing_next_month($dueAt, 28);
        $changed = true;
    }

    return $changed;
}

function mdp_billing_calendar_configure(array $subscription, array $plan, int $now): array
{
    if (($subscription['status'] ?? '') !== 'active' || empty($subscription['authorization_code'])
        || isset($subscription['billing_calendar'])) {
        throw new RuntimeException('An active, unconfigured subscription is required.');
    }

    $next = $plan['first_bill_date'] ?? '';
    $nextAt = mdp_billing_calendar_date($next);
    if (substr($next, -2) !== '28' || strtotime($nextAt) <= $now) {
        throw new RuntimeException('The first new bill must be a future date on the 28th.');
    }

    $open = [];
    foreach ($plan['overdue'] as $installment) {
        $date = mdp_billing_calendar_date($installment['due_date'] ?? '');
        if (strtotime($date) > $now) {
            throw new RuntimeException('Overdue installments must already be due.');
        }
        if (($installment['use_subscription_terms'] ?? false) === true) {
            $open[] = mdp_billing_calendar_installment($subscription, $date);
            continue;
        }
        $cents = $installment['amount_cents'] ?? null;
        $currency = $installment['currency'] ?? '';
        if (!is_int($cents) || $cents <= 0 || !in_array($currency, ['GHS', 'USD'], true)) {
            throw new RuntimeException('Overdue installments require a positive minor-unit amount and GHS or USD.');
        }
        $open[] = [
            'due_at' => $date,
            'amount_cents' => $cents,
            'display_currency' => $currency,
            'charge_amount_cents' => $cents,
            'charge_currency' => $currency,
            'items' => [],
        ];
    }
    // Keep separate installments on the same date in their input order.
    usort($open, static fn (array $first, array $second): int => $first['due_at'] <=> $second['due_at']);

    $group = $plan['collection_group'] ?? $subscription['reference'];
    if (!is_string($group) || $group === '' || mdp_safe_reference($group) !== $group) {
        throw new RuntimeException('Invalid collection group.');
    }

    if (isset($subscription['billing_attempt'])) {
        $attempt = $subscription['billing_attempt'];
        $startedAt = strtotime($attempt['started_at'] ?? '');
        if ($open === [] || ($plan['unrecorded_attempt_reference'] ?? '') !== $attempt['reference']
            || $startedAt === false || $now - $startedAt < 86400) {
            throw new RuntimeException('Resolve the existing attempt before activating calendar billing.');
        }
        $verified = mdp_paystack_request('GET', 'transaction/verify/' . rawurlencode($attempt['reference']));
        if ($verified['status'] !== 400 || ($verified['body']['code'] ?? '') !== 'transaction_not_found') {
            throw new RuntimeException('Paystack did not confirm the reviewed reference was missing.');
        }
        $subscription['unrecorded_legacy_attempt'] = $attempt;
        unset($subscription['billing_attempt'], $subscription['billing_review_required']);
    }

    $subscription['billing_calendar'] = ['next_bill_at' => $nextAt, 'open' => $open, 'group' => $group];
    $subscription['billing_day'] = 28;
    $lastFailure = strtotime($subscription['last_failure_at'] ?? '');
    $retryAt = $lastFailure === false ? $now : max($now, $lastFailure + 86400);
    $subscription['next_charge_at'] = $open === [] ? $nextAt : gmdate('c', $retryAt);
    if ($open !== [] && $retryAt > $now) {
        $subscription['billing_calendar']['retry_at'] = gmdate('c', $retryAt);
    }
    return $subscription;
}

function mdp_billing_calendar_date(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
        throw new RuntimeException('Billing dates must be valid YYYY-MM-DD dates in Accra time.');
    }
    return $parsed->format('c');
}
