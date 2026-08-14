<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$token = mdp_safe_reference((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$subscription = null;
$subscriptionPath = null;
$message = '';

foreach (glob(mdp_storage_path('subscriptions', '*.json')) ?: [] as $path) {
    $candidate = json_decode((string) file_get_contents($path), true);

    if (is_array($candidate) && hash_equals((string) ($candidate['manage_token'] ?? ''), $token)) {
        $subscription = $candidate;
        $subscriptionPath = $path;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $subscription && $subscriptionPath) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'cancel') {
        $subscription['status'] = 'cancelled';
        $subscription['cancelled_at'] = gmdate('c');
        $message = 'Subscription cancelled. No future monthly charges will be attempted.';
    }

    if ($action === 'update') {
        $selected = array_map('strval', $_POST['items'] ?? []);
        $submittedUsers = is_array($_POST['users'] ?? null) ? $_POST['users'] : [];
        $lookup = mdp_product_lookup();
        $updatedCart = [];

        foreach ($subscription['items'] as $item) {
            if (in_array($item['id'], $selected, true)) {
                $product = $lookup[$item['id']] ?? null;

                if (($product['pricing_model'] ?? 'flat') === 'users') {
                    $updatedCart[] = [
                        'id' => $item['id'],
                        'quantity' => 1,
                        'users' => (int) ($submittedUsers[$item['id']] ?? $item['users'] ?? $product['default_users'] ?? 1),
                    ];
                } else {
                    $updatedCart[] = [
                        'id' => $item['id'],
                        'quantity' => max(1, (int) $item['quantity']),
                    ];
                }
            }
        }

        $summary = mdp_cart_summary($updatedCart);
        $subscription['items'] = $summary['items'];
        $subscription['amount_cents'] = $summary['total_cents'];
        $subscription['amount'] = $summary['total'];
        $subscription['display_currency'] = $summary['display_currency'];
        $subscription['currency'] = $summary['display_currency'];
        $subscription['charge_amount_cents'] = $summary['charge_cents'];
        $subscription['charge_amount'] = $summary['charge_total'];
        $subscription['charge_currency'] = $summary['charge_currency'];
        $subscription['status'] = $summary['total_cents'] > 0 ? 'active' : 'cancelled';
        $subscription['updated_at'] = gmdate('c');
        $message = $subscription['status'] === 'active'
            ? 'Subscription updated. Future monthly charges will use the new cart.'
            : 'All items were removed, so the subscription was cancelled.';
    }

    mdp_write_json($subscriptionPath, $subscription);
}

$currency = mdp_display_currency();
$productLookup = mdp_product_lookup();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Manage subscription | Meta Data Platforms</title>
    <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body class="receipt-body">
    <main class="receipt-card">
        <a class="back-link" href="/">← Back to marketplace</a>
        <p class="eyebrow">Subscription manager</p>
        <h1>Manage monthly billing</h1>
        <?php if ($message): ?>
            <p class="success-message"><?= mdp_h($message) ?></p>
        <?php endif; ?>
        <?php if (!$subscription): ?>
            <p>We could not find an active subscription for this secure management link.</p>
        <?php else: ?>
            <p class="receipt-meta">Status: <strong><?= mdp_h(ucfirst((string) $subscription['status'])) ?></strong></p>
            <p class="receipt-meta">Customer: <strong><?= mdp_h($subscription['customer_name']) ?></strong> · <?= mdp_h($subscription['email']) ?></p>
            <form method="post" class="manage-form">
                <input type="hidden" name="token" value="<?= mdp_h($token) ?>">
                <div class="receipt-lines">
                    <?php foreach ($subscription['items'] as $item): ?>
                        <?php
                            $product = $productLookup[(string) ($item['id'] ?? '')] ?? [];
                            $image = (string) ($item['image'] ?? $product['image'] ?? '/assets/images/market-og.svg');
                            $imageType = (string) ($item['image_type'] ?? $product['image_type'] ?? 'logo');
                            $pricingModel = (string) ($item['pricing_model'] ?? $product['pricing_model'] ?? 'flat');
                            $quantity = max(1, (int) ($item['quantity'] ?? 1));
                            $baseUsers = max(1, (int) ($product['base_users'] ?? $item['users'] ?? 1));
                            $basePrice = (float) ($product['base_price'] ?? $product['price'] ?? $item['unit_price'] ?? 0);
                            $minPrice = (float) ($product['min_price'] ?? 0);
                            $unitPrice = (float) ($item['unit_price'] ?? $product['price'] ?? 0);
                        ?>
                        <label class="manage-line" data-manage-line data-pricing-model="<?= mdp_h($pricingModel) ?>" data-quantity="<?= $quantity ?>" data-unit-price="<?= mdp_h((string) $unitPrice) ?>" data-base-users="<?= $baseUsers ?>" data-base-price="<?= mdp_h((string) $basePrice) ?>" data-min-price="<?= mdp_h((string) $minPrice) ?>">
                            <input type="checkbox" name="items[]" value="<?= mdp_h($item['id']) ?>" checked data-manage-toggle <?= $subscription['status'] !== 'active' ? 'disabled' : '' ?>>
                            <img class="line-logo manage-line-logo line-logo--<?= mdp_h($imageType) ?>" src="<?= mdp_h($image) ?>" alt="" loading="lazy" decoding="async">
                            <span>
                                <?= mdp_h($item['name']) ?> × <?= (int) $item['quantity'] ?>
                                <?php if ($pricingModel === 'users'): ?>
                                    <label class="user-calculator manage-users">
                                        <span>Users for monthly billing</span>
                                        <input type="number" name="users[<?= mdp_h($item['id']) ?>]" min="1" max="10000000" step="1" value="<?= (int) ($item['users'] ?? 1) ?>" data-manage-users <?= $subscription['status'] !== 'active' ? 'disabled' : '' ?>>
                                        <small data-manage-estimate></small>
                                    </label>
                                <?php endif; ?>
                            </span>
                            <strong data-manage-line-total><?= mdp_h(mdp_money((float) $item['line_total'], $currency)) ?></strong>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="receipt-total">
                    <span>Current monthly total</span>
                    <strong data-manage-total><?= mdp_h(mdp_money((float) $subscription['amount'], $currency)) ?></strong>
                </div>
                <?php if (($subscription['charge_currency'] ?? $currency) !== $currency): ?>
                    <p class="receipt-meta">Paystack charge estimate: <strong><?= mdp_h(mdp_charge_money((float) ($subscription['charge_amount'] ?? 0), (string) ($subscription['charge_currency'] ?? $currency))) ?></strong></p>
                <?php endif; ?>
                <div class="receipt-actions">
                    <button class="button primary" type="submit" name="action" value="update" <?= $subscription['status'] !== 'active' ? 'disabled' : '' ?>>Save changes</button>
                    <button class="button danger" type="submit" name="action" value="cancel" <?= $subscription['status'] !== 'active' ? 'disabled' : '' ?>>Cancel subscription</button>
                </div>
            </form>
        <?php endif; ?>
    </main>
    <script>
        (() => {
            const money = new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: <?= json_encode($currency) ?>,
            });
            const rows = Array.from(document.querySelectorAll('[data-manage-line]'));
            const totalNode = document.querySelector('[data-manage-total]');

            function numberFrom(value, fallback = 0) {
                const parsed = Number(value);
                return Number.isFinite(parsed) ? parsed : fallback;
            }

            function clampUsers(input) {
                const raw = Number.parseInt(input?.value || '1', 10);
                return Math.max(1, Math.min(10000000, Number.isFinite(raw) ? raw : 1));
            }

            function linePrice(row) {
                const toggle = row.querySelector('[data-manage-toggle]');

                if (toggle && !toggle.checked) {
                    return 0;
                }

                if (row.dataset.pricingModel === 'users') {
                    const usersInput = row.querySelector('[data-manage-users]');
                    const users = clampUsers(usersInput);
                    const baseUsers = Math.max(1, numberFrom(row.dataset.baseUsers, 1));
                    const basePrice = numberFrom(row.dataset.basePrice, 0);
                    const minPrice = numberFrom(row.dataset.minPrice, 0);

                    return Math.round(Math.max(minPrice, basePrice * (users / baseUsers)) * 100) / 100;
                }

                return Math.round(numberFrom(row.dataset.unitPrice, 0) * numberFrom(row.dataset.quantity, 1) * 100) / 100;
            }

            function renderManageTotals() {
                let total = 0;

                rows.forEach((row) => {
                    const price = linePrice(row);
                    const lineTotal = row.querySelector('[data-manage-line-total]');
                    const estimate = row.querySelector('[data-manage-estimate]');
                    const usersInput = row.querySelector('[data-manage-users]');

                    total += price;

                    if (lineTotal) {
                        lineTotal.textContent = money.format(price);
                    }

                    if (estimate && usersInput) {
                        estimate.textContent = `${clampUsers(usersInput).toLocaleString()} users → ${money.format(price)} / month. Updates as you edit.`;
                    }
                });

                if (totalNode) {
                    totalNode.textContent = money.format(total);
                }
            }

            document.addEventListener('input', (event) => {
                if (event.target.closest('[data-manage-users]')) {
                    renderManageTotals();
                }
            });

            document.addEventListener('change', (event) => {
                if (event.target.closest('[data-manage-toggle], [data-manage-users]')) {
                    renderManageTotals();
                }
            });

            renderManageTotals();
        })();
    </script>
</body>
</html>
