# Million Dollar Script - WooCommerce Checkout

WooCommerce payment provider adapter for Million Dollar Script.

## What It Does

- Registers WooCommerce as a Million Dollar Script payment provider.
- Creates WooCommerce orders for Million Dollar Script checkout sources.
- Syncs WooCommerce paid, cancelled, failed, and refunded statuses back to Million Dollar Script.
- Adds a Manage action to WooCommerce account orders when a Million Dollar Script manage URL is available.
- Provides WooCommerce store currency to Million Dollar Script when WooCommerce is the active provider.
- Provides an extension-owned checkout readiness screen for payment routing, pages, gateways, currency, HTTPS, and post-login behavior.

## Requirements

- WordPress 6.0+
- PHP 8.1+
- Million Dollar Script 3.0+
- WooCommerce (certified with WooCommerce 11.1.0 on WordPress 7.0.4, including HPOS)

## Setup

1. Install and configure WooCommerce.
2. Activate Million Dollar Script.
3. Activate Million Dollar Script - WooCommerce Checkout.
4. Open Million Dollar Script -> Extensions -> WooCommerce Checkout.
5. Enable WooCommerce for new Million Dollar Script checkouts and resolve any readiness warnings.

Monetization extensions should not call WooCommerce directly. They should call `MillionDollarScript\Commerce\Payments::create_checkout()` and let this adapter handle WooCommerce-specific orders and callbacks.

## Changelog

### 1.1.2

- Declared compatibility with WooCommerce HPOS and cart and checkout blocks after certifying both integration paths.

### 1.1.1

- Certified linked-order creation, payment completion, refunds, HPOS, and frontend requests with WooCommerce 11.1.0 on WordPress 7.0.4.

### 1.1.0

- Added provider-neutral recurring checkout and renewal-order support for the optional Subscriptions extension.
- Added version-gated automatic renewal bridges for supported WooCommerce Stripe and PayPal Payments releases, with secure pay-for-order fallback.
- Preserved subscription identifiers, billing context, idempotency keys, and reusable payment-method requirements on linked WooCommerce orders.
- Certified HPOS, block and classic checkout rendering, linked-order lifecycle synchronization, and replay-safe renewal behavior with WooCommerce 11.0.0 on WordPress 7.0.3.

### 1.0.0

- Production-ready Million Dollar Script WooCommerce checkout adapter.
- Preserves Million Dollar Script customer email on registered and guest WooCommerce orders.
- Adds customer Manage actions for linked WooCommerce orders when the current customer is allowed to manage the source Million Dollar Script order.
- Syncs paid, failed, refunded, and cancelled WooCommerce statuses back to Million Dollar Script through the core payments API.
- Reuses existing linked WooCommerce orders only when the stored payment source matches the Million Dollar Script source order.

### 0.1.0

- Initial WooCommerce provider adapter for the Million Dollar Script payments API.
