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
PAYSTACK_USD_TO_GHS_RATE=15.50
RECEIPT_FROM_EMAIL=receipts@metadataplatforms.9yttrybe.com
SUPPORT_EMAIL=billing@metadataplatforms.9yttrybe.com
```

Never commit `.env` or secret keys. Product prices display in USD. If Paystack USD is unavailable, set `PAYSTACK_CHARGE_CURRENCY=GHS` and update `PAYSTACK_USD_TO_GHS_RATE`; customers will still see dollar prices while Paystack receives the converted GHS charge.

## Monthly billing cron

The checkout stores reusable Paystack authorization details only after a successful verified payment and monthly consent. Configure this cron once per day:

```bash
/usr/local/bin/php /home/rbfiqhyo/domains/metadataplatforms.9yttrybe.com/public_html/cron/monthly-billing.php >> /home/rbfiqhyo/domains/metadataplatforms.9yttrybe.com/public_html/storage/logs/monthly-billing.log 2>&1
```

The script charges due active subscriptions and writes receipts into `storage/receipts`.
