=== CoinsPay for WooCommerce ===
Contributors: coinspay
Tags: crypto, cryptocurrency, payments, usdt, bitcoin
Requires at least: 4.7
Tested up to: 7.1
Requires PHP: 7.0
WC requires at least: 3.0
WC tested up to: 11.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept USDT, BTC, ETH and other coins in WooCommerce. The buyer pays on the CoinsPay payment page, the order is marked paid on its own.

== Description ==

CoinsPay for WooCommerce adds a "Cryptocurrency" payment method to your store.

* At checkout the buyer chooses the method and goes to the CoinsPay payment page.
* There the buyer picks a coin and a network: USDT and USDC on TRON, Ethereum, BNB Smart Chain and Solana, Bitcoin, Litecoin, ETH, BNB, TRX, SOL and more.
* The price is in your store currency; CoinsPay converts it at the current rate and holds the amount in coins for 30 minutes.
* When the payment is confirmed, the order becomes "Processing" (or "Completed" for virtual goods) and the buyer returns to your store.
* Payouts go straight to your own wallets by the payout rule of your CoinsPay shop.

What the plugin handles:

* A payment on its way puts the order on hold; a partial payment too, and the buyer can pay the rest on the same page.
* An invoice that expires unpaid cancels the order (optional). A late payment reopens it.
* An order cancelled in the store cancels its invoice. A payment that does not match the order total now, or comes for an order the store cancelled, puts the order on hold for you to decide.
* Events come by webhook with a signature check. If a webhook fails, the plugin asks CoinsPay itself every few minutes, so no payment is lost.
* Test mode: with a cpk_test_ key, invoices are paid with a button on the payment page, no coins move. Such a payment completes only orders placed by a shop manager.
* Classic checkout and the Cart and Checkout blocks; High-Performance Order Storage.
* WooCommerce 3.0 and newer, WordPress 4.7 and newer, PHP 7.0 to 8.5.
* English and Russian.

You need a CoinsPay account and a shop in it.

== External services ==

The plugin works with CoinsPay, a crypto payment service. Without it the plugin does nothing.

* When the buyer places an order with this payment method, the plugin sends CoinsPay the invoice: the order number, the total and the currency, the names and quantities of the items, and the address of the order received page of your store. No names, e-mails, addresses or phone numbers of the buyer are sent.
* To show the order state, the plugin requests the invoices of its orders from CoinsPay: when the buyer returns to the store, when a CoinsPay webhook arrives, and every few minutes while an invoice is open.
* On the settings screen the plugin asks CoinsPay for the list of accepted currencies and, with the "Check connection" button, for the name of your shop.
* The buyer pays on the CoinsPay payment page; what CoinsPay does with the data there is described in its documents.

Requests go to https://coinspay.app/api/v1 (or the API address you enter in the settings), with your API key. CoinsPay: [Terms of service](https://coinspay.app/terms), [Privacy policy](https://coinspay.app/privacy).

== Installation ==

1. Install and activate the plugin, then open WooCommerce → Settings → Payments → CoinsPay.
2. In the CoinsPay dashboard open your shop and issue an API key under "API keys" with "Invoices only" access: the plugin needs nothing more, and such a key cannot withdraw money. Paste the key into the plugin; leave the API address empty to use https://coinspay.app/api/v1.
3. In the dashboard, under "Webhook", enter the webhook address the plugin shows and save. Copy the whsec_ secret into the plugin.
4. Press "Check connection", then "Send test event" in the dashboard: the plugin shows when the event arrived.
5. Enable the method and save.

To try everything without money first, use a test key (cpk_test_).

== Frequently Asked Questions ==

= Which currencies can my prices be in? =

USD, EUR, GBP, AED, CNY, JPY, RUB, TRY and others CoinsPay accepts. The plugin loads the list from CoinsPay; if the store currency is not on it, the method is hidden at checkout.

= What if the buyer pays less? =

The order goes on hold with a note of what was received and what is left. The buyer can pay the rest on the same payment page.

= What if the invoice expires? =

By default the order is cancelled and its stock returned. If the buyer still pays later, the order is reopened and marked paid with a note.

= Can the buyer pay the CoinsPay fee? =

Yes, with the "The buyer pays the CoinsPay fee" setting. The fee is added to the amount in coins on the payment page; the order total stays the same.

= One CoinsPay shop for my live site and a staging copy? =

Use a separate CoinsPay shop for staging: a shop has one webhook, and its events would go to one of the two sites only.

== Changelog ==

= 0.1.0 =
* First version.
