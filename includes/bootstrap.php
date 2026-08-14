<?php

declare(strict_types=1);

function mdp_load_env(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $loaded = true;
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

    if (!is_file($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");

        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function mdp_env(string $key, string $default = ''): string
{
    mdp_load_env();
    $value = getenv($key);

    return $value === false ? $default : (string) $value;
}

function mdp_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function mdp_money(float|int $amount, string $currency = 'USD'): string
{
    return ($currency === 'USD' ? '$' : $currency . ' ') . number_format((float) $amount, 2);
}

function mdp_display_currency(): string
{
    return 'USD';
}

function mdp_charge_currency(): string
{
    $currency = strtoupper(mdp_env('PAYSTACK_CHARGE_CURRENCY', mdp_env('PAYSTACK_CURRENCY', 'USD')));

    return in_array($currency, ['USD', 'GHS'], true) ? $currency : 'USD';
}

function mdp_curl_ca_bundle(): ?string
{
    foreach (array_filter([
        mdp_env('CURL_CA_BUNDLE'),
        ini_get('curl.cainfo') ?: '',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem',
        'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt',
        'C:/Program Files/Git/mingw64/ssl/certs/ca-bundle.crt',
    ]) as $caBundle) {
        if (is_file((string) $caBundle)) {
            return (string) $caBundle;
        }
    }

    return null;
}

function mdp_extract_usd_to_ghs_rate(array $payload): ?float
{
    $rate = null;

    if (isset($payload['rates']['GHS'])) {
        $rate = $payload['rates']['GHS'];
    } elseif (isset($payload['conversion_rates']['GHS'])) {
        $rate = $payload['conversion_rates']['GHS'];
    } elseif (isset($payload['quotes']['USDGHS'])) {
        $rate = $payload['quotes']['USDGHS'];
    } elseif (isset($payload['rate'])) {
        $rate = $payload['rate'];
    }

    if (!is_numeric($rate)) {
        return null;
    }

    $rate = (float) $rate;

    return $rate > 0 ? $rate : null;
}

function mdp_fetch_json(string $url, int $timeout = 8): ?array
{
    if ($url === '' || !str_starts_with($url, 'https://')) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: MetaDataPlatforms/1.0',
        ],
    ]);

    $caBundle = mdp_curl_ca_bundle();

    if ($caBundle !== null) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $error !== '' || $status < 200 || $status >= 300) {
        return null;
    }

    $payload = json_decode((string) $raw, true);

    return is_array($payload) ? $payload : null;
}

function mdp_usd_to_ghs_rate(): float
{
    static $rate = null;

    if ($rate !== null) {
        return $rate;
    }

    $fallbackRate = max(0.01, (float) mdp_env('PAYSTACK_USD_TO_GHS_RATE', '11.08'));
    $cachePath = mdp_storage_path('cache', 'usd-ghs-rate.json');
    $cached = is_file($cachePath) ? json_decode((string) file_get_contents($cachePath), true) : null;
    $sourceUrl = mdp_env('EXCHANGE_RATE_API_URL', 'https://open.er-api.com/v6/latest/USD');
    $payload = mdp_fetch_json($sourceUrl);
    $apiRate = is_array($payload) ? mdp_extract_usd_to_ghs_rate($payload) : null;

    if ($apiRate !== null) {
        $rate = $apiRate;
        mdp_write_json($cachePath, [
            'base' => 'USD',
            'target' => 'GHS',
            'rate' => $rate,
            'source' => $sourceUrl,
            'fetched_at' => gmdate('c'),
            'cache_policy' => 'fallback_only',
        ]);

        return $rate;
    }

    if (is_array($cached) && isset($cached['rate']) && is_numeric($cached['rate'])) {
        return $rate = max(0.01, (float) $cached['rate']);
    }

    return $rate = $fallbackRate;
}

function mdp_display_to_charge_cents(float|int $usdAmount): int
{
    $amount = (float) $usdAmount;

    if (mdp_charge_currency() === 'GHS') {
        return (int) round($amount * mdp_usd_to_ghs_rate() * 100);
    }

    return (int) round($amount * 100);
}

function mdp_charge_money(float|int $amount, ?string $currency = null): string
{
    $currency = strtoupper($currency ?: mdp_charge_currency());

    if ($currency === 'GHS') {
        return 'GHS ' . number_format((float) $amount, 2);
    }

    return ($currency === 'GHS' ? 'GH₵' : '$') . number_format((float) $amount, 2);
}

function mdp_products(): array
{
    static $products = null;

    if ($products === null) {
        $products = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'products.php';

        foreach ($products as &$product) {
            $logoSvg = '/assets/images/product-logos/' . $product['id'] . '.svg';
            $logoPng = '/assets/images/product-logos/' . $product['id'] . '.png';
            $photoImage = '/assets/images/product-photos/' . $product['id'] . '.jpg';
            $rasterImage = '/assets/images/product-renders/' . $product['id'] . '.png';
            $generatedImage = '/assets/images/products/' . $product['id'] . '.svg';

            if (is_file(dirname(__DIR__) . $logoPng)) {
                $product['image'] = $logoPng;
                $product['image_type'] = 'logo';
            } elseif (is_file(dirname(__DIR__) . $logoSvg)) {
                $product['image'] = $logoSvg;
                $product['image_type'] = 'logo';
            } elseif (is_file(dirname(__DIR__) . $photoImage)) {
                $product['image'] = $photoImage;
                $product['image_type'] = 'photo';
            } elseif (is_file(dirname(__DIR__) . $rasterImage)) {
                $product['image'] = $rasterImage;
                $product['image_type'] = 'photo';
            } elseif (is_file(dirname(__DIR__) . $generatedImage)) {
                $product['image'] = $generatedImage;
                $product['image_type'] = 'logo';
            }
        }

        unset($product);
    }

    return $products;
}

function mdp_product_lookup(): array
{
    static $lookup = null;

    if ($lookup !== null) {
        return $lookup;
    }

    $lookup = [];

    foreach (mdp_products() as $product) {
        $lookup[$product['id']] = $product;
    }

    return $lookup;
}

function mdp_normalize_cart(array $rawItems): array
{
    $lookup = mdp_product_lookup();
    $items = [];

    foreach ($rawItems as $item) {
        $id = isset($item['id']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $item['id'])) : '';
        $product = $id !== '' && isset($lookup[$id]) ? $lookup[$id] : null;

        if ($id === '' || !$product) {
            continue;
        }

        if (($product['pricing_model'] ?? 'flat') === 'users') {
            $defaultUsers = (int) ($product['default_users'] ?? $product['base_users'] ?? 1);
            $minUsers = (int) ($product['min_users'] ?? 1);
            $users = isset($item['users']) ? (int) $item['users'] : $defaultUsers;
            $items[$id] = [
                'id' => $id,
                'quantity' => 1,
                'users' => min(max($users, $minUsers), 10000000),
            ];

            continue;
        }

        $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 0;

        if ($quantity < 1) {
            continue;
        }

        $items[$id] = [
            'id' => $id,
            'quantity' => min($quantity, 99),
        ];
    }

    return array_values($items);
}

function mdp_product_unit_price(array $product, ?int $users = null): float
{
    if (($product['pricing_model'] ?? 'flat') !== 'users') {
        return round((float) $product['price'], 2);
    }

    $baseUsers = max(1, (int) ($product['base_users'] ?? 1));
    $defaultUsers = (int) ($product['default_users'] ?? $baseUsers);
    $safeUsers = max(1, $users ?? $defaultUsers);
    $basePrice = (float) ($product['base_price'] ?? $product['price']);
    $minPrice = (float) ($product['min_price'] ?? 0);
    $calculated = $basePrice * ($safeUsers / $baseUsers);

    return round(max($minPrice, $calculated), 2);
}

function mdp_cart_summary(array $cart): array
{
    $lookup = mdp_product_lookup();
    $items = [];
    $totalCents = 0;

    foreach (mdp_normalize_cart($cart) as $line) {
        $product = $lookup[$line['id']];
        $users = isset($line['users']) ? (int) $line['users'] : null;
        $unitPrice = mdp_product_unit_price($product, $users);
        $priceCents = (int) round($unitPrice * 100);
        $lineTotal = $priceCents * $line['quantity'];
        $totalCents += $lineTotal;
        $items[] = [
            'id' => $product['id'],
            'name' => $product['name'],
            'image' => $product['image'],
            'image_type' => $product['image_type'] ?? 'logo',
            'quantity' => $line['quantity'],
            'users' => $users,
            'pricing_model' => $product['pricing_model'] ?? 'flat',
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal / 100,
        ];
    }

    $chargeCents = mdp_display_to_charge_cents($totalCents / 100);

    return [
        'items' => $items,
        'total_cents' => $totalCents,
        'total' => $totalCents / 100,
        'display_currency' => mdp_display_currency(),
        'charge_currency' => mdp_charge_currency(),
        'charge_cents' => $chargeCents,
        'charge_total' => $chargeCents / 100,
    ];
}

function mdp_storage_path(string $folder, string $filename = ''): string
{
    $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $folder;

    if (!is_dir($base)) {
        mkdir($base, 0775, true);
    }

    return $filename === '' ? $base : $base . DIRECTORY_SEPARATOR . $filename;
}

function mdp_safe_reference(string $reference): string
{
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $reference) ?: bin2hex(random_bytes(8));
}

function mdp_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function mdp_write_json(string $path, array $payload): void
{
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $path);
}

function mdp_app_url(string $path = ''): string
{
    $base = rtrim(mdp_env('APP_URL', ''), '/');

    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        $base = $scheme . '://' . $host;
    }

    return $base . '/' . ltrim($path, '/');
}

function mdp_paystack_request(string $method, string $endpoint, array $payload = []): array
{
    $secret = mdp_env('PAYSTACK_SECRET_KEY');

    if ($secret === '' || !str_starts_with($secret, 'sk_')) {
        return [
            'ok' => false,
            'status' => 500,
            'body' => ['message' => 'Paystack secret key is not configured.'],
        ];
    }

    $ch = curl_init('https://api.paystack.co/' . ltrim($endpoint, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $secret,
            'Content-Type: application/json',
        ],
    ]);

    $caBundle = mdp_curl_ca_bundle();

    if ($caBundle !== null) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
    }

    if ($payload !== []) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $error !== '') {
        return [
            'ok' => false,
            'status' => 502,
            'body' => ['message' => $error ?: 'Unable to reach Paystack.'],
        ];
    }

    $body = json_decode((string) $raw, true);

    return [
        'ok' => $status >= 200 && $status < 300 && is_array($body) && ($body['status'] ?? false) === true,
        'status' => $status,
        'body' => is_array($body) ? $body : ['message' => 'Invalid Paystack response.'],
    ];
}

function mdp_finish_verified_payment(string $reference, string $name, string $email, bool $acceptedTerms, array $cart): array
{
    if (trim($reference) === '') {
        return [
            'ok' => false,
            'status' => 422,
            'message' => 'Payment reference is missing.',
        ];
    }

    $reference = mdp_safe_reference($reference);
    $pendingPath = mdp_storage_path('pending', $reference . '.json');
    $pending = is_file($pendingPath) ? json_decode((string) file_get_contents($pendingPath), true) : null;

    if (is_array($pending)) {
        $name = (string) ($pending['customer_name'] ?? $name);
        $email = (string) ($pending['email'] ?? $email);
        $acceptedTerms = (bool) ($pending['monthly_terms'] ?? $acceptedTerms);
        $cart = is_array($pending['cart'] ?? null) ? $pending['cart'] : $cart;
    }

    $email = filter_var($email, FILTER_VALIDATE_EMAIL);
    $name = trim($name);
    $summary = is_array($pending['summary'] ?? null) ? $pending['summary'] : mdp_cart_summary($cart);

    if (
        !isset($summary['total_cents'], $summary['total'], $summary['charge_cents'], $summary['charge_total'], $summary['display_currency'], $summary['charge_currency']) ||
        !is_array($summary['items'] ?? null)
    ) {
        $summary = mdp_cart_summary($cart);
    }

    $summary['total_cents'] = (int) $summary['total_cents'];
    $summary['total'] = (float) $summary['total'];
    $summary['charge_cents'] = (int) $summary['charge_cents'];
    $summary['charge_total'] = (float) $summary['charge_total'];
    $summary['display_currency'] = strtoupper((string) $summary['display_currency']);
    $summary['charge_currency'] = strtoupper((string) $summary['charge_currency']);
    $displayCurrency = $summary['display_currency'];
    $chargeCurrency = $summary['charge_currency'];

    if ($reference === '' || !$email || $name === '' || $summary['total_cents'] < 1 || $summary['items'] === []) {
        return [
            'ok' => false,
            'status' => 422,
            'message' => 'Missing customer, cart, or payment reference.',
        ];
    }

    $receiptPath = mdp_storage_path('receipts', $reference . '.json');
    if (is_file($receiptPath)) {
        $existing = json_decode((string) file_get_contents($receiptPath), true);

        if (is_array($existing) && !empty($existing['receipt_url'])) {
            return [
                'ok' => true,
                'status' => 200,
                'message' => 'Payment already verified.',
                'receipt_url' => (string) $existing['receipt_url'],
                'manage_url' => (string) ($existing['manage_url'] ?? ''),
                'reference' => $reference,
            ];
        }
    }

    $verification = mdp_paystack_request('GET', 'transaction/verify/' . rawurlencode($reference));

    if (!$verification['ok']) {
        return [
            'ok' => false,
            'status' => $verification['status'] ?: 502,
            'message' => (string) ($verification['body']['message'] ?? 'Payment verification failed.'),
        ];
    }

    $data = $verification['body']['data'] ?? [];
    $paidAmount = (int) ($data['amount'] ?? 0);
    $paidCurrency = strtoupper((string) ($data['currency'] ?? $chargeCurrency));
    $status = strtolower((string) ($data['status'] ?? ''));
    $paystackEmail = strtolower((string) ($data['customer']['email'] ?? $email));

    if ($status !== 'success') {
        return [
            'ok' => false,
            'status' => 422,
            'message' => 'Payment was not successful.',
        ];
    }

    if ($paidAmount !== $summary['charge_cents'] || $paidCurrency !== strtoupper($chargeCurrency)) {
        return [
            'ok' => false,
            'status' => 422,
            'message' => 'Payment amount or currency did not match the verified cart.',
        ];
    }

    if ($paystackEmail !== strtolower((string) $email)) {
        return [
            'ok' => false,
            'status' => 422,
            'message' => 'Payment email did not match checkout email.',
        ];
    }

    $authCode = (string) ($data['authorization']['authorization_code'] ?? '');
    $manageToken = $acceptedTerms ? bin2hex(random_bytes(24)) : '';
    $receiptUrl = mdp_app_url('/receipt.php?ref=' . rawurlencode($reference));
    $manageUrl = $manageToken !== '' ? mdp_app_url('/manage.php?token=' . rawurlencode($manageToken)) : '';
    $receipt = [
        'reference' => $reference,
        'customer_name' => $name,
        'email' => (string) $email,
        'amount_cents' => $summary['total_cents'],
        'amount' => $summary['total'],
        'currency' => $displayCurrency,
        'display_currency' => $displayCurrency,
        'charge_amount_cents' => $summary['charge_cents'],
        'charge_amount' => $summary['charge_total'],
        'charge_currency' => $chargeCurrency,
        'items' => $summary['items'],
        'recurring_accepted' => $acceptedTerms,
        'paystack_status' => $status,
        'paid_at' => gmdate('c'),
        'receipt_url' => $receiptUrl,
        'manage_url' => $manageUrl,
    ];

    mdp_write_json($receiptPath, $receipt);

    if ($acceptedTerms && $authCode !== '') {
        $subscription = [
            'reference' => $reference,
            'manage_token' => $manageToken,
            'customer_name' => $name,
            'email' => (string) $email,
            'authorization_code' => $authCode,
            'amount_cents' => $summary['total_cents'],
            'amount' => $summary['total'],
            'currency' => $displayCurrency,
            'display_currency' => $displayCurrency,
            'charge_amount_cents' => $summary['charge_cents'],
            'charge_amount' => $summary['charge_total'],
            'charge_currency' => $chargeCurrency,
            'items' => $summary['items'],
            'status' => 'active',
            'created_at' => gmdate('c'),
            'next_charge_at' => gmdate('c', strtotime('+1 month')),
        ];
        mdp_write_json(mdp_storage_path('subscriptions', $reference . '.json'), $subscription);
    }

    $chargeLine = $chargeCurrency === $displayCurrency ? '' : "\nPaystack charge: " . mdp_charge_money($summary['charge_total'], $chargeCurrency);
    $billingLine = $acceptedTerms ? "\nMonthly billing: enabled" : "\nMonthly billing: not enabled";
    $manageLine = $manageUrl !== '' ? "\nManage subscription: {$manageUrl}" : '';
    $receiptText = "Thank you for your Meta Data Platforms payment.\n\nReference: {$reference}\nTotal: " . mdp_money($summary['total'], $displayCurrency) . $chargeLine . $billingLine . "\nReceipt: {$receiptUrl}" . $manageLine . "\n";
    @mail((string) $email, 'Meta Data Platforms receipt ' . $reference, $receiptText, 'From: ' . mdp_env('RECEIPT_FROM_EMAIL', 'receipts@metadataplatforms.9yttrybe.com'));

    return [
        'ok' => true,
        'status' => 200,
        'message' => 'Payment verified and receipt created.',
        'receipt_url' => $receiptUrl,
        'manage_url' => $manageUrl,
        'reference' => $reference,
    ];
}
