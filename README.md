# Meta Data Platforms

Professional one-page commerce site for selling IT, software, API, and developer tooling subscriptions.

## Local setup

1. Copy `.env.example` to `.env`.
2. Add Paystack test keys locally.
3. Start PHP. This is a lightweight PHP site, not Laravel, so there is no `artisan` command:

```bash
php -S 127.0.0.1:8789
```

Then open `http://127.0.0.1:8789`.

On Windows you can also double-click `serve.bat`.

## Production setup

On the server, create a `.env` file in the site root with:

```bash
APP_ENV=production
APP_URL=https://metadataplatforms.9yttrybe.com
PAYSTACK_PUBLIC_KEY=pk_live_replace_me
PAYSTACK_SECRET_KEY=sk_live_replace_me
PAYSTACK_CHARGE_CURRENCY=USD
EXCHANGE_RATE_API_URL=https://open.er-api.com/v6/latest/USD
PAYSTACK_USD_TO_GHS_RATE=11.08
RECEIPT_FROM_EMAIL=receipts@metadataplatforms.9yttrybe.com
SUPPORT_EMAIL=billing@metadataplatforms.9yttrybe.com
```

Never commit `.env` or secret keys. Product prices display in USD. If Paystack USD is unavailable for the merchant account, checkout automatically retries the charge in GHS behind the scenes. The conversion asks the configured exchange-rate API live on each checkout and each recurring billing run. A saved cache is used only if the API is temporarily unreachable; `PAYSTACK_USD_TO_GHS_RATE` is the final emergency fallback if both the live API and saved cache are unavailable.

On local Windows PHP, Paystack SSL verification may require a CA bundle. The app uses `CURL_CA_BUNDLE`, `curl.cainfo`, a local `certs/cacert.pem`, or Git for Windows' CA bundle when available. Do not disable SSL verification.

## Monthly billing cron

The checkout stores reusable Paystack authorization details only after a successful verified payment and optional monthly billing consent. If the customer leaves monthly billing unchecked, the payment still completes but no subscription is created. Configure this cron once per day for customers who opted into monthly billing:

```bash
/usr/local/bin/php /home/rbfiqhyo/domains/metadataplatforms.9yttrybe.com/public_html/cron/monthly-billing.php >> /home/rbfiqhyo/domains/metadataplatforms.9yttrybe.com/public_html/storage/logs/monthly-billing.log 2>&1
```

The script charges due active subscriptions and writes receipts into `storage/receipts`.
