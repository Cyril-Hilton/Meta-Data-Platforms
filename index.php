<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$products = mdp_products();
$displayCurrency = mdp_display_currency();
$chargeCurrency = mdp_charge_currency();
$publicKey = mdp_env('PAYSTACK_PUBLIC_KEY', '');
$configured = str_starts_with($publicKey, 'pk_');
$canonical = mdp_app_url('/');
$productCount = count($products);
$categories = array_values(array_unique(array_map(static fn (array $product): string => $product['category'], $products)));
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
$categoryCount = count($categories);
$cssVersion = is_file(__DIR__ . '/assets/css/styles.css') ? substr(md5_file(__DIR__ . '/assets/css/styles.css'), 0, 10) : (string) time();
$jsVersion = is_file(__DIR__ . '/assets/js/app.js') ? substr(md5_file(__DIR__ . '/assets/js/app.js'), 0, 10) : (string) time();
$productListSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'ItemList',
    'name' => 'Meta Data Platforms developer tools marketplace',
    'itemListElement' => array_map(static function (array $product, int $index) use ($displayCurrency): array {
        return [
            '@type' => 'ListItem',
            'position' => $index + 1,
            'item' => [
                '@type' => 'Product',
                'name' => $product['name'],
                'description' => $product['description'],
                'image' => mdp_app_url($product['image']),
                'offers' => [
                    '@type' => 'Offer',
                    'price' => number_format((float) $product['price'], 2, '.', ''),
                    'priceCurrency' => $displayCurrency,
                    'availability' => 'https://schema.org/InStock',
                    'url' => mdp_app_url('/#' . $product['id']),
                ],
            ],
        ];
    }, $products, array_keys($products)),
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meta Data Platforms | Developer Tools, APIs & Software Subscriptions</title>
    <meta name="description" content="Buy developer tools, APIs, security, analytics, messaging, and infrastructure software from Meta Data Platforms. Fast checkout, verified receipts, and optional monthly billing.">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <link rel="canonical" href="<?= mdp_h($canonical) ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Meta Data Platforms">
    <meta property="og:description" content="A professional IT marketplace for APIs, developer tools, and monthly software subscriptions.">
    <meta property="og:url" content="<?= mdp_h($canonical) ?>">
    <meta property="og:image" content="<?= mdp_h(mdp_app_url('/assets/images/market-og.svg')) ?>">
    <meta name="theme-color" content="#050816">
    <link rel="preload" href="/assets/css/styles.css?v=<?= mdp_h($cssVersion) ?>" as="style">
    <link rel="stylesheet" href="/assets/css/styles.css?v=<?= mdp_h($cssVersion) ?>">
    <script type="application/ld+json"><?= json_encode($productListSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body>
    <div class="site-shell">
        <header class="topbar">
            <a class="brand" href="/" aria-label="Meta Data Platforms home">
                <span class="brand-mark">MDP</span>
                <span>
                    <strong>Meta Data Platforms</strong>
                    <small>Developer commerce cloud</small>
                </span>
            </a>
            <nav aria-label="Primary navigation">
                <a href="#products">Products</a>
                <a href="#checkout">Checkout</a>
                <a href="#billing">Billing</a>
            </nav>
            <button class="cart-pill" type="button" data-open-cart>
                Cart <span data-cart-count>0</span>
            </button>
        </header>

        <main>
            <section class="hero">
                <div class="hero-copy">
                    <p class="eyebrow">Curated IT marketplace</p>
                    <h1>A complete IT marketplace for developer tools.</h1>
                    <p class="hero-lede">APIs, observability, security, messaging, analytics, automation, and infrastructure packs for serious teams that want speed without procurement chaos.</p>
                    <div class="hero-actions">
                        <a class="button primary" href="#products">Browse products</a>
                        <button class="button ghost" type="button" data-open-cart>Review cart</button>
                    </div>
                    <div class="trust-row" aria-label="Marketplace highlights">
                        <span>Verified Paystack checkout</span>
                        <span>Optional monthly billing</span>
                        <span>Instant receipts</span>
                    </div>
                </div>
                <aside class="hero-card" aria-label="Featured platform metrics">
                    <div class="pulse"></div>
                    <h2>Platform stack preview</h2>
                    <p>Mix APIs, cloud tooling, reporting, and developer ops into one managed subscription.</p>
                    <div class="metric-grid">
                        <span><strong><?= (int) $productCount ?></strong><small>Products</small></span>
                        <span><strong><?= (int) $categoryCount ?></strong><small>Categories</small></span>
                        <span><strong>1</strong><small>Monthly bill</small></span>
                    </div>
                </aside>
            </section>

            <section class="toolbar" aria-label="Product filters">
                <label class="search-box">
                    <span>Search tools</span>
                    <input type="search" placeholder="Search APIs, security, analytics..." data-search>
                </label>
                <label class="filter-box">
                    <span>Category</span>
                    <select data-category>
                        <option value="all">All categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= mdp_h($category) ?>"><?= mdp_h($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </section>

            <section class="product-section" id="products">
                <div class="section-heading">
                    <p class="eyebrow">Marketplace</p>
                    <h2>Developer tools, software licenses, and infrastructure packs</h2>
                    <p>Featured enterprise location, messaging, and analytics tools are mixed into the first rows for quick access.</p>
                </div>

                <div class="product-grid" data-product-grid>
                    <?php foreach ($products as $index => $product): ?>
                        <article class="product-card" id="<?= mdp_h($product['id']) ?>" data-product-card data-name="<?= mdp_h(strtolower($product['name'] . ' ' . $product['description'])) ?>" data-category="<?= mdp_h($product['category']) ?>">
                            <div class="product-image product-image--<?= mdp_h($product['image_type'] ?? 'logo') ?>">
                                <img src="<?= mdp_h($product['image']) ?>" alt="<?= mdp_h($product['name']) ?> product preview" loading="eager" decoding="async"<?= $index < 8 ? ' fetchpriority="high"' : '' ?>>
                            </div>
                            <div class="product-content">
                                <span class="category"><?= mdp_h($product['category']) ?></span>
                                <?php if (!empty($product['featured'])): ?>
                                    <span class="featured-badge">Featured</span>
                                <?php endif; ?>
                                <?php if (($product['pricing_model'] ?? 'flat') === 'users'): ?>
                                    <span class="usage-badge">Usage-based</span>
                                <?php endif; ?>
                                <h3><?= mdp_h($product['name']) ?></h3>
                                <p><?= mdp_h($product['description']) ?></p>
                            </div>
                            <div class="product-footer">
                                <strong><?= mdp_h(mdp_money((float) $product['price'], $displayCurrency)) ?><small>/mo<?= (($product['pricing_model'] ?? 'flat') === 'users') ? ' starting' : '' ?></small></strong>
                                <button type="button" data-add-to-cart="<?= mdp_h($product['id']) ?>">Add to cart</button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="checkout-section" id="checkout" aria-labelledby="checkout-heading">
                <div class="section-heading checkout-heading">
                    <p class="eyebrow">Checkout</p>
                    <h2 id="checkout-heading">Review your monthly software stack.</h2>
                    <p>Adjust quantities, set usage-based user counts, confirm your billing details, then continue to secure Paystack payment.</p>
                </div>

                <div class="checkout-shell">
                    <div class="checkout-card checkout-cart-card">
                        <div class="checkout-card-head">
                            <div>
                                <span class="step-pill">Step 1</span>
                                <h3>Order summary</h3>
                            </div>
                            <a href="#products">Add more tools</a>
                        </div>
                        <div class="cart-lines checkout-lines" data-cart-lines></div>
                        <div class="cart-summary checkout-summary">
                            <div>
                                <span>Monthly total</span>
                                <small data-charge-note></small>
                            </div>
                            <strong data-cart-total>$0.00</strong>
                        </div>
                    </div>

                    <div class="checkout-card checkout-payment-card">
                        <div class="checkout-card-head">
                            <div>
                                <span class="step-pill">Step 2</span>
                                <h3>Customer & payment</h3>
                            </div>
                        </div>
                        <form class="checkout-form checkout-form-page" data-checkout-form>
                            <label>
                                Full name
                                <input name="name" autocomplete="name" required placeholder="Jane Doe">
                            </label>
                            <label>
                                Email address
                                <input name="email" type="email" autocomplete="email" required placeholder="jane@company.com">
                            </label>
                            <label class="terms-check">
                                <input name="monthly_terms" type="checkbox">
                                <span>Enable monthly auto-billing for these tools. Leave unchecked for this payment only, with no future automatic charges.</span>
                            </label>
                            <button class="button primary full" type="submit" data-pay-button>Proceed to Paystack checkout</button>
                            <p class="form-note" data-payment-note><?= $configured ? 'Payments are handled securely by Paystack. Products display in dollars.' : 'Paystack keys are not configured yet. Add keys in .env to enable live checkout.' ?></p>
                        </form>
                    </div>
                </div>
            </section>

            <section class="billing-panel" id="billing">
                <div>
                    <p class="eyebrow">Billing choice</p>
                    <h2>Monthly billing is optional.</h2>
                    <p>Customers can make a one-time payment, or opt into monthly auto-billing. The server stores a reusable authorization only when monthly billing is selected and Paystack verifies the first payment.</p>
                </div>
                <div class="billing-steps">
                    <span>1. Add tools</span>
                    <span>2. Choose billing</span>
                    <span>3. Pay securely</span>
                    <span>4. Receive receipt</span>
                </div>
            </section>
        </main>

        <footer class="footer">
            <span>© <?= date('Y') ?> Meta Data Platforms. Professional software subscriptions.</span>
        </footer>
    </div>

    <aside class="cart-drawer" data-cart-drawer aria-hidden="true">
        <div class="cart-panel" role="dialog" aria-modal="true" aria-labelledby="cart-title">
            <div class="cart-head">
                <div>
                    <p class="eyebrow">Selected tools</p>
                    <h2 id="cart-title">Your monthly cart</h2>
                </div>
                <button type="button" class="icon-button" data-close-cart aria-label="Close cart">×</button>
            </div>
            <div class="cart-lines" data-cart-lines></div>
            <div class="cart-summary">
                <div>
                    <span>Monthly total</span>
                    <small data-charge-note></small>
                </div>
                <strong data-cart-total>$0.00</strong>
            </div>
            <div class="cart-actions">
                <a class="button primary full" href="#checkout" data-go-checkout>Continue to checkout</a>
                <button class="button ghost full" type="button" data-close-cart>Keep browsing</button>
            </div>
        </div>
    </aside>

    <div class="toast" data-toast role="status" aria-live="polite"></div>

    <script>
        window.MDP_PRODUCTS = <?= json_encode($products, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.MDP_CONFIG = {
            paystackPublicKey: <?= json_encode($publicKey) ?>,
            paystackConfigured: <?= json_encode($configured) ?>,
            displayCurrency: <?= json_encode($displayCurrency) ?>,
            chargeCurrency: <?= json_encode($chargeCurrency) ?>,
            initializeEndpoint: '/api/initialize.php',
            verifyEndpoint: '/api/verify.php'
        };
    </script>
    <script src="/assets/js/app.js?v=<?= mdp_h($jsVersion) ?>" defer></script>
</body>
</html>
