<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$reference = mdp_safe_reference((string) ($_GET['ref'] ?? ''));
$receiptPath = mdp_storage_path('receipts', $reference . '.json');
$receipt = is_file($receiptPath) ? json_decode((string) file_get_contents($receiptPath), true) : null;
$currency = mdp_display_currency();
$recurringAccepted = is_array($receipt) && (bool) ($receipt['recurring_accepted'] ?? false);
$productLookup = mdp_product_lookup();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= $receipt ? 'Receipt ' . mdp_h($reference) : 'Receipt not found' ?> | Meta Data Platforms</title>
    <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body class="receipt-body">
    <main class="receipt-card">
        <a class="back-link" href="/">&larr; Back to marketplace</a>
        <header class="receipt-brand" aria-label="Receipt issuer">
            <span class="brand-mark">MDP</span>
            <span>
                <strong>Meta Data Platforms</strong>
                <small>Official payment receipt</small>
            </span>
        </header>

        <?php if (!$receipt): ?>
            <h1>Receipt not found</h1>
            <p>We could not find a receipt for this reference. Please check the link and try again.</p>
        <?php else: ?>
            <p class="eyebrow">Payment receipt</p>
            <h1>Receipt confirmed</h1>
            <p class="receipt-meta">Reference: <strong><?= mdp_h($receipt['reference']) ?></strong></p>
            <p class="receipt-meta">Customer: <strong><?= mdp_h($receipt['customer_name']) ?></strong> &middot; <?= mdp_h($receipt['email']) ?></p>
            <p class="receipt-meta">Paid: <strong><?= mdp_h($receipt['paid_at']) ?></strong></p>

            <div class="receipt-lines">
                <?php foreach ($receipt['items'] as $item): ?>
                    <?php
                        $product = $productLookup[(string) ($item['id'] ?? '')] ?? [];
                        $image = (string) ($item['image'] ?? $product['image'] ?? '/assets/images/market-og.svg');
                        $imageType = (string) ($item['image_type'] ?? $product['image_type'] ?? 'logo');
                    ?>
                    <div>
                        <img class="line-logo receipt-line-logo line-logo--<?= mdp_h($imageType) ?>" src="<?= mdp_h($image) ?>" alt="" loading="lazy" decoding="async">
                        <span class="receipt-product-name"><?= mdp_h($item['name']) ?> &times; <?= (int) $item['quantity'] ?></span>
                        <strong><?= mdp_h(mdp_money((float) $item['line_total'], $currency)) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="receipt-total">
                <span><?= $recurringAccepted ? 'Monthly subscription total' : 'Payment total' ?></span>
                <strong><?= mdp_h(mdp_money((float) $receipt['amount'], $currency)) ?></strong>
            </div>
            <p class="receipt-meta">Monthly billing: <strong><?= $recurringAccepted ? 'Enabled' : 'Not enabled' ?></strong></p>
            <?php if (($receipt['charge_currency'] ?? $currency) !== $currency): ?>
                <p class="receipt-meta">Paystack charged: <strong><?= mdp_h(mdp_charge_money((float) ($receipt['charge_amount'] ?? 0), (string) ($receipt['charge_currency'] ?? $currency))) ?></strong></p>
            <?php endif; ?>
            <div class="receipt-actions">
                <button class="button primary" type="button" onclick="window.print()">Print receipt</button>
                <?php if (!empty($receipt['manage_url'])): ?>
                    <a class="button ghost" href="<?= mdp_h($receipt['manage_url']) ?>">Manage subscription</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
