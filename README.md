# Million Dollar Script WooCommerce Checkout

WooCommerce payment provider adapter for Million Dollar Script.

## What It Does

- Registers WooCommerce as a Million Dollar Script payment provider.
- Creates WooCommerce orders for Million Dollar Script checkout sources.
- Syncs WooCommerce paid, cancelled, failed, and refunded statuses back to Million Dollar Script.
- Adds a Manage action to WooCommerce account orders when a Million Dollar Script manage URL is available.
- Provides WooCommerce store currency to Million Dollar Script when WooCommerce is the active provider.

## Requirements

- WordPress 6.0+
- PHP 8.1+
- Million Dollar Script 3.0+
- WooCommerce

## Setup

1. Install and configure WooCommerce.
2. Activate Million Dollar Script.
3. Activate Million Dollar Script WooCommerce Checkout.
4. Open Million Dollar Script -> Setup.
5. Set Payment Provider to WooCommerce.

Monetization extensions should not call WooCommerce directly. They should call `MDS3\Commerce\Payments::create_checkout()` and let this adapter handle WooCommerce-specific orders and callbacks.

## Changelog

### 0.1.0

- Initial WooCommerce provider adapter for the Million Dollar Script payments API.
