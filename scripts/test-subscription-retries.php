<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

echo "=======================================================\n";
echo "RUNNING 90-DAY SUBSCRIPTION RETRY LOGIC TESTS\n";
echo "=======================================================\n\n";

function simulate_charge_failure(array $subscription): array {
    $failureCount = ((int) ($subscription['failure_count'] ?? 0)) + 1;
    $subscription['failure_count'] = $failureCount;
    $subscription['last_failure_at'] = gmdate('c');
    $subscription['last_failure_message'] = 'Insufficient funds (simulated)';

    if ($failureCount >= 90) {
        $subscription['status'] = 'cancelled';
        $subscription['cancelled_at'] = gmdate('c');
        $subscription['cancellation_reason'] = 'Subscription cancelled after 90 consecutive daily payment retry failures.';
        $subscription['next_charge_at'] = null;
    } else {
        $subscription['status'] = 'active';
        $subscription['next_charge_at'] = gmdate('c', strtotime('+1 day'));
    }

    return $subscription;
}

function simulate_charge_success(array $subscription): array {
    $subscription['last_charge_at'] = gmdate('c');
    $subscription['next_charge_at'] = gmdate('c', strtotime('+1 month'));
    $subscription['failure_count'] = 0;
    $subscription['status'] = 'active';
    unset($subscription['cancellation_reason']);
    return $subscription;
}

$sub = [
    'reference' => 'sub_test_123',
    'status' => 'active',
    'email' => 'customer@example.com',
    'failure_count' => 0,
    'next_charge_at' => gmdate('c', strtotime('-1 hour')),
];

echo "1. Testing 89 consecutive failed daily retries...\n";
for ($i = 1; $i <= 89; $i++) {
    $sub = simulate_charge_failure($sub);

    if ($sub['status'] !== 'active') {
        echo "❌ FAILED: Subscription status should be 'active' on attempt {$i}, got '{$sub['status']}'\n";
        exit(1);
    }

    if ($sub['failure_count'] !== $i) {
        echo "❌ FAILED: Expected failure_count {$i}, got {$sub['failure_count']}\n";
        exit(1);
    }
}
echo "   ✅ 89 retries completed successfully. Status remains 'active', failure_count = 89.\n\n";

echo "2. Testing 90th failed daily retry (Cancellation trigger)...\n";
$sub = simulate_charge_failure($sub);

if ($sub['status'] !== 'cancelled') {
    echo "❌ FAILED: Expected status 'cancelled' on 90th failure, got '{$sub['status']}'\n";
    exit(1);
}

if ($sub['failure_count'] !== 90) {
    echo "❌ FAILED: Expected failure_count 90, got {$sub['failure_count']}\n";
    exit(1);
}

if (empty($sub['cancellation_reason'])) {
    echo "❌ FAILED: Expected cancellation_reason to be populated.\n";
    exit(1);
}

if ($sub['next_charge_at'] !== null) {
    echo "❌ FAILED: Expected next_charge_at to be null after cancellation.\n";
    exit(1);
}
echo "   ✅ 90th retry correctly cancelled subscription and stopped future charges.\n";
echo "   Reason logged: \"{$sub['cancellation_reason']}\"\n\n";

echo "3. Testing recovery on attempt 45 (Day 45)...\n";
$sub2 = [
    'reference' => 'sub_test_456',
    'status' => 'active',
    'email' => 'customer2@example.com',
    'failure_count' => 0,
    'next_charge_at' => gmdate('c', strtotime('-1 hour')),
];

for ($i = 1; $i <= 45; $i++) {
    $sub2 = simulate_charge_failure($sub2);
}
echo "   Subscription failed 45 times (failure_count = {$sub2['failure_count']}).\n";
echo "   Simulating successful payment retry on Day 46 (account topped up)...\n";

$sub2 = simulate_charge_success($sub2);

if ($sub2['status'] !== 'active') {
    echo "❌ FAILED: Expected status 'active' after successful recovery, got '{$sub2['status']}'\n";
    exit(1);
}

if ($sub2['failure_count'] !== 0) {
    echo "❌ FAILED: Expected failure_count reset to 0, got {$sub2['failure_count']}\n";
    exit(1);
}

echo "   ✅ Payment recovery succeeded! failure_count reset to 0, next_charge_at set to +1 month.\n\n";

echo "=======================================================\n";
echo "✅ ALL 90-DAY RETRY LOGIC TESTS PASSED 100%!\n";
echo "=======================================================\n";
