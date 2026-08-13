<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

mdp_json_response([
    'configured' => str_starts_with(mdp_env('PAYSTACK_PUBLIC_KEY'), 'pk_'),
    'display_currency' => mdp_display_currency(),
    'charge_currency' => mdp_charge_currency(),
    'usd_to_ghs_rate' => mdp_usd_to_ghs_rate(),
    'product_count' => count(mdp_products()),
]);
