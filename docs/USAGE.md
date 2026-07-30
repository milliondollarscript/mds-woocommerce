# WooCommerce Checkout

WooCommerce Checkout lets Million Dollar Script send pixel orders and extension checkout sources through WooCommerce payment, order, tax, currency, and receipt workflows.

## Practical Examples

- Let a customer select pixels, upload placement content, and pay through the gateways already configured in WooCommerce.
- Apply WooCommerce tax, currency, checkout, customer-account, and receipt behavior to orders created by Million Dollar Script and compatible extensions.
- Send an eligible expired placement through a new renewal checkout while retaining the placement and order relationship needed by Million Dollar Script.

### Example workflow: accept a paid pixel placement

1. Install and configure WooCommerce, then activate WooCommerce Checkout and enable it for new Million Dollar Script checkouts.
2. A visitor selects available blocks on the Order Pixels page, supplies the required placement fields, and continues to checkout.
3. WooCommerce collects customer and payment details using its configured gateways and updates the linked Million Dollar Script order when payment completes.
4. Review the placement in Million Dollar Script and the commercial order in WooCommerce. Publication remains governed by the Million Dollar Script placement workflow.

## Requirements

- WooCommerce is installed and active.
- Million Dollar Script - WooCommerce Checkout is installed and active.
- WooCommerce has at least one working payment method.
- The Million Dollar Script payment provider is set to WooCommerce.

The setup wizard can install WooCommerce from WordPress.org when you choose it as the payment provider. The Million Dollar Script - WooCommerce Checkout extension is installed through the extension catalog.

After activation, open **Million Dollar Script -> Extensions -> WooCommerce Checkout**. The checkout status shows whether WooCommerce is selected, which currency is active, how many payment methods are enabled, whether checkout is secure, and whether the Checkout and My account pages are published.

Enable **Use WooCommerce for new Million Dollar Script checkouts**, then save the page. You can also select WooCommerce from **Million Dollar Script -> Settings -> General** or during the setup wizard.

## Settings

### Payment Routing

Enable **Use WooCommerce for new Million Dollar Script checkouts** to route new purchases through WooCommerce. Million Dollar Script retains the placement or extension order while WooCommerce handles payment, tax, currency, receipts, and customer checkout.

Disabling this option switches back to standalone checkout only when WooCommerce is the currently selected provider. It does not replace another payment provider selected by an extension.

### After-login Destination

Enter an optional same-site URL for WooCommerce customers after login. Leave it blank to keep the normal WooCommerce destination. For security, redirects to hosts WordPress does not allow are rejected and WooCommerce keeps its default destination.

## Currency

When WooCommerce is the active payment provider, Million Dollar Script follows WooCommerce currency behavior. If a multi-currency plugin changes the active store currency, prices displayed by Million Dollar Script should use the current provider currency instead of a hardcoded currency.

## Customer Flow

1. The customer selects blocks on the order page or starts checkout from an extension.
2. Million Dollar Script creates or reserves the source order.
3. WooCommerce checkout opens for payment.
4. A completed WooCommerce order marks the Million Dollar Script source order paid.
5. The customer can manage uploads or extension-specific details from the manage link.

## Renewals

Renewal links are created for eligible paid placements. A renewal keeps the original placement context, creates a payable checkout order, and reactivates the placement after payment.

If a renewal checkout says the order cannot be paid, check that the original Million Dollar Script order is eligible for renewal, the WooCommerce order exists, and the payment-provider extension is active.

## Subscription Billing

When the optional Subscriptions extension is active and enabled, WooCommerce Checkout can create the initial recurring order and a separate source-linked order for each billing cycle. One-time checkout remains unchanged when subscriptions are disabled or no compatible plan is active.

Every gateway can use a secure WooCommerce pay-for-order link for renewal. Automatic collection is additionally available for explicitly supported WooCommerce Stripe and PayPal Payments versions when the previous paid order has a reusable payment method. Unknown gateway versions, guest PayPal purchases, missing tokens, and customer-authentication requirements fall back to the renewal payment link.

Test the exact gateway and version combination before enabling subscription offers. Confirm initial checkout, saved payment method behavior, a successful renewal, a failed or authentication-required renewal, customer recovery, and period-end cancellation.

## Troubleshooting

- If WooCommerce does not appear as a provider, install and activate both WooCommerce and this checkout extension.
- If the checkout status says **Needs attention**, open the WooCommerce Checkout extension page and resolve the payment-provider, payment-method, page, or HTTPS item shown there.
- If checkout opens but payment callbacks do not update the placement, check WooCommerce order status and scheduled actions.
- If the wrong currency appears, confirm the store currency and any active multi-currency plugin behavior.
