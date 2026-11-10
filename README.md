# CoinsPay for WooCommerce

Accept cryptocurrency payments in your WooCommerce store. Buyers pay on the CoinsPay payment page, and the money goes on to your own wallets.

## Requirements

| | Minimum | Tested up to |
|---|---|---|
| WordPress | 4.7 | 7.1 |
| WooCommerce | 3.0 | 11.2 |
| PHP | 7.0 | 8.5 |

You also need a [CoinsPay](https://coinspay.app) account with a shop.

## Description

The plugin adds a "Cryptocurrency" payment method to checkout. The buyer chooses it, goes to the CoinsPay payment page, picks a coin and a network and pays. Once the payment is confirmed, the order moves to Processing (Completed for virtual goods) and the buyer is sent back to your store.

Prices stay in your store currency. CoinsPay converts the total at the current rate and holds the coin amount for 30 minutes, so the rate does not change while the buyer opens their wallet.

### Coins and networks

More than 30 coins and tokens on TRON, Ethereum, BNB Smart Chain, Solana, Bitcoin and Litecoin, among them USDT, USDC, BTC, ETH, BNB, TRX, LTC and SOL. The full list is on [coinspay.app/coins](https://coinspay.app/coins).

### Store currencies

USD, EUR, GBP, AED, CNY, JPY, RUB, TRY and the other currencies CoinsPay accepts. The plugin loads the list from CoinsPay and hides the method at checkout if your store currency is not on it.

### What the plugin takes care of

- A payment that is still confirming puts the order on hold. So does a partial payment, and the buyer can pay the rest on the same page.
- When an invoice expires unpaid, the order is cancelled and its stock returned (you can turn this off). A late payment reopens the order and marks it paid.
- Cancelling an order in the store cancels its CoinsPay invoice, so it can no longer be paid.
- If a payment no longer matches the order total, or arrives for an order the store has cancelled, the order goes on hold with a note for you to review.
- Webhooks are signed and checked. If one does not arrive, the plugin asks CoinsPay about open invoices every few minutes.
- Classic checkout and the Cart and Checkout blocks are supported, and so is High-Performance Order Storage.
- English and Russian translations are included.

### Why CoinsPay

Payments go straight to your wallets. Each network takes up to five payout addresses with percentage shares, for example 70% to you and 30% to a partner, and nothing is kept on the platform.

The fee starts at 0.3% of each payout and goes down as monthly turnover grows. There is no monthly or setup fee. For the first 10 days after your first payment, payouts up to $500 carry no fee. You can let the buyer pay the CoinsPay fee and the network fee on top of the order total. Details are on the [pricing page](https://coinspay.app/pricing).

## Installation

1. Get the plugin archive `coinspay-for-woocommerce-<version>.zip` from the releases of this repository, or build it yourself (see [Building](#building)).
2. In WordPress open Plugins → Add New → Upload Plugin, upload the archive and activate the plugin.

## Configuration

1. Open WooCommerce → Settings → Payments → CoinsPay.
2. In the CoinsPay dashboard open your shop and create an API key under "API keys" with "Invoices only" access. The plugin needs nothing more, and such a key cannot withdraw money. Paste the key into the plugin settings. Leave "API address" empty: the plugin then uses `https://coinspay.app/api/v1`.
3. In the dashboard, under "Webhook", enter the webhook address shown in the plugin settings and save. Copy the `whsec_…` secret into the plugin.
4. Press "Check connection", then "Send test event" in the dashboard. The plugin shows when the event arrived.
5. Enable the payment method and save.

## Test mode

With a test key (`cpk_test_…`) invoices are paid with a button on the payment page and no coins move. A test payment completes only orders placed by a shop manager, so a test key left on a live store cannot mark a buyer's order as paid.

## Frequently asked questions

### What if the buyer pays less than the total?

The order goes on hold with a note of what was received and what is left. The buyer can pay the rest on the same payment page.

### What if the invoice expires?

By default the order is cancelled and its stock is returned. If the buyer pays later anyway, the order is reopened and marked paid with a note.

### Can the buyer pay the CoinsPay fee?

Yes, turn on "The buyer pays the CoinsPay fee". The fee is added to the coin amount on the payment page, and the order total stays the same.

### Can a staging copy share the CoinsPay shop with the live site?

Use a separate CoinsPay shop for staging. A shop has one webhook address, so its events would reach only one of the two sites.

### What data does the plugin send to CoinsPay?

The order number, total and currency, the names and quantities of the items, and the address of the order received page. It sends no buyer names, emails, addresses or phone numbers. See the CoinsPay [terms of service](https://coinspay.app/terms) and [privacy policy](https://coinspay.app/privacy).

## Development

Composer is needed for development only; the plugin has no runtime dependencies. Everything runs in Docker:

```bash
P=$(pwd)
docker run --rm -v "$P:/app" -w /app composer:2 install
docker run --rm -v "$P:/app" -w /app php:7.4-cli vendor/bin/phpunit
docker run --rm -v "$P:/app" -w /app php:8.5-cli vendor/bin/phpunit
docker run --rm -v "$P:/app" -w /app php:8.5-cli vendor/bin/phpcs
```

`phpcs` checks the WordPress coding standards and PHP 7.0 compatibility. The unit tests in `tests/Unit` run without WordPress and cover the webhook signature, the invoice request and the rules that move an order from one status to another.

The code avoids syntax newer than PHP 7.0 and anything deprecated in PHP 8.2 to 8.5. WooCommerce features are detected by presence, not by version number (`src/Compat.php`).

| File | Purpose |
|---|---|
| `coinspay-for-woocommerce.php` | Plugin header, autoloader, bootstrap hooks |
| `src/Gateway.php` | The payment gateway: settings, availability, `process_payment` |
| `src/Payment.php`, `src/InvoiceRequest.php` | The invoice for an order and its idempotency key |
| `src/OrderSync.php` | Invoice state to order state, applied under a MySQL lock |
| `src/Webhook.php` | The `?wc-api=coinspay` endpoint: signature, mode, fresh invoice from the API |
| `src/Poller.php` | Polling of open invoices (Action Scheduler or WP-Cron) |
| `src/Admin/` | Invoice panel on the order screen, "Check connection" |
| `src/Blocks/`, `assets/js/checkout-block.js` | The payment method for the checkout block |

### Building

`bin/build.php` writes `dist/coinspay-for-woocommerce-<version>.zip` without tests, development files and Composer. It needs the zip extension:

```bash
docker run --rm -v "$P:/app" -w /app php:8.5-cli sh -c 'apt-get update -qq >/dev/null && apt-get install -qq -y libzip-dev >/dev/null 2>&1 && docker-php-ext-install zip >/dev/null 2>&1; php bin/build.php'
```

## Changelog

### 0.1.0

- First release.

## License

GPL-2.0-or-later.
