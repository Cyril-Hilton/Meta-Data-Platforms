<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$reference = mdp_safe_reference((string) ($_GET['ref'] ?? ''));
$receiptPath = mdp_storage_path('receipts', $reference . '.json');
$receipt = is_file($receiptPath) ? json_decode((string) file_get_contents($receiptPath), true) : null;
$currency = mdp_display_currency();
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
        <a class="back-link" href="/">← Back to marketplace</a>
        <?php if (!$receipt): ?>
            <h1>Receipt not found</h1>
            <p>We could not find a receipt for this reference. Please check the link and try again.</p>
        <?php else: ?>
            <p class="eyebrow">Payment receipt</p>
            <h1>Receipt confirmed</h1>
            <p class="receipt-meta">Reference: <strong><?= mdp_h($receipt['reference']) ?></strong></p>
            <p class="receipt-meta">Customer: <strong><?= mdp_h($receipt['customer_name']) ?></strong> · <?= mdp_h($receipt['email']) ?></p>
            <p class="receipt-meta">Paid: <strong><?= mdp_h($receipt['paid_at']) ?></strong></p>
            <div class="receipt-lines">
                <?php foreach ($receipt['items'] as $item): ?>
                    <div>
                        <span><?= mdp_h($item['name']) ?> × <?= (int) $item['quantity'] ?></span>
                        <strong><?= mdp_h(mdp_money((float) $item['line_total'], $currency)) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="receipt-total">
                <span>Monthly subscription total</span>
                <strong><?= mdp_h(mdp_money((float) $receipt['amount'], $currency)) ?></strong>
            </div>
            <?php if (($receipt['charge_currency'] ?? $currency) !== $currency): ?>
                <p class="receipt-meta">Paystack charged: <strong><?= mdp_h(mdp_charge_money((float) ($receipt['charge_amount'] ?? 0))) ?></strong></p>
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
