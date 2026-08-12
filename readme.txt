=== Million Dollar Script - WooCommerce Checkout ===
Contributors: milliondollarscript
Tags: million dollar script, woocommerce, payments, checkout
Requires at least: 6.0
Tested up to: 7.0.3
WC tested up to: 11.0.1
Requires PHP: 8.1
Stable tag: 1.1.0
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

= Unreleased =
* Added opt-in uninstall cleanup through the core extension policy for adapter-owned settings and transients while deliberately retaining WooCommerce orders.
* Added a direct return from checkout readiness to the Commerce step in the Million Dollar Script setup flow.
* Certified linked-order creation, payment completion, refunds, HPOS, and frontend requests with WooCommerce 11.0.1 on WordPress 7.0.3.

= 1.1.0 =
* Adds provider-neutral recurring checkout and renewal-order support for the optional Subscriptions extension.
* Preserves subscription identifiers and billing context on WooCommerce orders.
* Uses secure pay-for-order links when automatic collection is unavailable.
* Saves reusable Stripe payment methods for supported initial recurring and renewal checkouts.
* Certified the HPOS, block-checkout, classic-checkout, order lifecycle, and replay-safe adapter paths with WooCommerce 11.0.0 on WordPress 7.0.3.

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
