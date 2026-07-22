=== Million Dollar Script - WooCommerce Checkout ===
Contributors: milliondollarscript
Tags: million dollar script, woocommerce, payments, checkout
Requires at least: 6.0
Tested up to: 7.0.2
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

WooCommerce payment provider adapter for Million Dollar Script.

== Description ==

Million Dollar Script - WooCommerce Checkout registers WooCommerce as a payment provider for the Million Dollar Script core payments API. It creates WooCommerce orders for Million Dollar Script checkout sources, syncs payment status back to Million Dollar Script, adds customer Manage actions for linked WooCommerce orders, and provides a checkout readiness screen for store pages, gateways, currency, HTTPS, and payment routing.

== Installation ==

1. Install and configure WooCommerce.
2. Activate Million Dollar Script.
3. Activate Million Dollar Script - WooCommerce Checkout.
4. Open Million Dollar Script -> Extensions -> WooCommerce Checkout.
5. Enable WooCommerce for new Million Dollar Script checkouts and resolve any readiness warnings.

== Changelog ==

= 1.0.0 =
* Production-ready Million Dollar Script WooCommerce checkout adapter.
* Preserves Million Dollar Script customer email on registered and guest WooCommerce orders.
* Adds customer Manage actions for linked WooCommerce orders when the current customer is allowed to manage the source Million Dollar Script order.
* Syncs paid, failed, refunded, and cancelled WooCommerce statuses back to Million Dollar Script through the core payments API.
* Reuses existing linked WooCommerce orders only when the stored payment source matches the Million Dollar Script source order.
* Adds an extension-owned checkout readiness and settings screen.
* Applies the optional same-site customer login destination.

= 0.1.0 =
* Initial WooCommerce provider adapter.
