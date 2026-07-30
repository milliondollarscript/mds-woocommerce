<?php
/**
 * Version-gated automatic renewal bridges for WooCommerce gateways.
 *
 * @package MillionDollarScript\Extensions\WooCommerce
 */

namespace MillionDollarScript\Extensions\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

final class AutomaticRenewals {
    private const STRIPE_MIN_VERSION = '10.8.0';
    private const STRIPE_MAX_VERSION = '10.9.0';
    private const PAYPAL_MIN_VERSION = '4.1.0';
    private const PAYPAL_MAX_VERSION = '4.2.0';

    private Main $main;

    public function __construct(Main $main) {
        $this->main = $main;
    }

    public function supported(): bool {
        return $this->stripe_supported() || $this->paypal_supported();
    }

    /**
     * Collect a renewal automatically when the original gateway and token support it.
     * Unsupported methods retain the normal guest-compatible pay-for-order flow.
     *
     * @return array|\WP_Error
     */
    public function collect(array $payload) {
        $manual = $this->main->create_recurring_payment_link($payload);
        if (is_wp_error($manual)) {
            return $manual;
        }

        $order_id = absint($manual['provider_order_id'] ?? 0);
        $order = $order_id && function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order instanceof \WC_Order) {
            return $manual;
        }
        if ($this->is_paid($order)) {
            return $this->result($order, 'paid');
        }

        $subscription = is_array($payload['subscription'] ?? null) ? $payload['subscription'] : [];
        $previous_order_id = absint($subscription['metadata']['last_provider_order_id'] ?? 0);
        $previous_order = $previous_order_id ? wc_get_order($previous_order_id) : null;
        if ($previous_order instanceof \WC_Order) {
            $this->copy_payment_method($previous_order, $order);
        }

        $payment_method = sanitize_key((string) $order->get_payment_method());
        if ($this->stripe_supported() && str_starts_with($payment_method, 'stripe')) {
            return $this->collect_stripe($order, $manual);
        }
        if ($this->paypal_supported() && 'ppcp-gateway' === $payment_method && $order->get_customer_id()) {
            return $this->collect_paypal($order, $manual);
        }

        return $manual;
    }

    public function force_stripe_payment_method_save($force, $order_id) {
        if (!$this->stripe_supported()) {
            return $force;
        }

        return $this->payment_method_save_required($order_id) ? true : $force;
    }

    public function payment_method_save_required($order_id): bool {
        $order = function_exists('wc_get_order') ? wc_get_order(absint($order_id)) : null;
        if (!$order instanceof \WC_Order) {
            return false;
        }

        return 'recurring' === sanitize_key((string) $order->get_meta('_mds3_billing_mode'))
            || 'mds-subscription-cycle' === sanitize_key((string) $order->get_meta('_mds3_payment_source'));
    }

    public function stripe_supported(): bool {
        if (!$this->version_supported('woocommerce-gateway-stripe/woocommerce-gateway-stripe.php', self::STRIPE_MIN_VERSION, self::STRIPE_MAX_VERSION)) {
            return false;
        }

        foreach ($this->gateways() as $gateway) {
            if (
                $gateway instanceof \WC_Payment_Gateway
                && str_starts_with((string) $gateway->id, 'stripe')
                && is_callable([$gateway, 'process_subscription_payment'])
            ) {
                return true;
            }
        }

        return false;
    }

    public function paypal_supported(): bool {
        if (
            !$this->version_supported('woocommerce-paypal-payments/woocommerce-paypal-payments.php', self::PAYPAL_MIN_VERSION, self::PAYPAL_MAX_VERSION)
            || !class_exists('\\WooCommerce\\PayPalCommerce\\PPCP')
            || !class_exists('\\WooCommerce\\PayPalCommerce\\WcSubscriptions\\RenewalHandler')
        ) {
            return false;
        }

        try {
            $container = \WooCommerce\PayPalCommerce\PPCP::container();
            return method_exists($container, 'has')
                && $container->has('wc-subscriptions.renewal-handler');
        } catch (\Throwable $exception) {
            return false;
        }
    }

    private function collect_stripe(\WC_Order $order, array $manual) {
        $gateway = $this->gateway((string) $order->get_payment_method());
        if (!$gateway || !is_callable([$gateway, 'process_subscription_payment'])) {
            return $manual;
        }

        try {
            // MDS owns retry timing, so disable the Stripe gateway's nested retry loop.
            $gateway->process_subscription_payment((float) $order->get_total(), $order, false, false);
        } catch (\Throwable $exception) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: payment error */
                    __('Automatic Stripe renewal could not be completed: %s', 'mds-woocommerce'),
                    sanitize_text_field($exception->getMessage())
                )
            );
            $order->save();
        }

        $order = wc_get_order($order->get_id());
        if ($order instanceof \WC_Order && $this->is_paid($order)) {
            return $this->result($order, 'paid');
        }
        if ($order instanceof \WC_Order && $order->has_status('failed')) {
            return $this->result($order, $order->get_checkout_payment_url() ? 'action_required' : 'failed');
        }

        return $order instanceof \WC_Order ? $this->result($order, 'pending') : $manual;
    }

    private function collect_paypal(\WC_Order $order, array $manual) {
        try {
            $container = \WooCommerce\PayPalCommerce\PPCP::container();
            $handler = $container->get('wc-subscriptions.renewal-handler');
            $method = new \ReflectionMethod($handler, 'process_order');
            if (!$method->isPrivate()) {
                return $manual;
            }

            // PayPal Payments 4.1.x exposes its renewal service through the container,
            // while the token-backed collector itself is private and version-pinned here.
            $method->setAccessible(true);
            $method->invoke($handler, $order);
        } catch (\Throwable $exception) {
            $order->update_status(
                'failed',
                sprintf(
                    /* translators: %s: payment error */
                    __('Automatic PayPal renewal could not be completed: %s', 'mds-woocommerce'),
                    sanitize_text_field($exception->getMessage())
                )
            );
        }

        $order = wc_get_order($order->get_id());
        if ($order instanceof \WC_Order && $this->is_paid($order)) {
            return $this->result($order, 'paid');
        }
        if ($order instanceof \WC_Order && $order->has_status('failed')) {
            return $this->result($order, $order->get_checkout_payment_url() ? 'action_required' : 'failed');
        }

        return $order instanceof \WC_Order ? $this->result($order, 'pending') : $manual;
    }

    private function copy_payment_method(\WC_Order $from, \WC_Order $to): void {
        $to->set_payment_method((string) $from->get_payment_method());
        $to->set_payment_method_title((string) $from->get_payment_method_title());

        foreach (['_stripe_customer_id', '_stripe_source_id'] as $key) {
            $value = $from->get_meta($key);
            if (is_scalar($value) && '' !== (string) $value) {
                $to->update_meta_data($key, sanitize_text_field((string) $value));
            }
        }

        foreach ($from->get_payment_tokens() as $token_id) {
            $token = class_exists('\\WC_Payment_Tokens') ? \WC_Payment_Tokens::get($token_id) : null;
            if ($token instanceof \WC_Payment_Token && (int) $token->get_user_id() === (int) $to->get_customer_id()) {
                $to->add_payment_token($token);
            }
        }
        $to->save();
    }

    private function result(\WC_Order $order, string $status): array {
        return [
            'status' => sanitize_key($status),
            'provider_order_id' => (string) $order->get_id(),
            'payment_url' => 'paid' === $status ? '' : esc_url_raw((string) $order->get_checkout_payment_url()),
            'payment_method' => sanitize_key((string) $order->get_payment_method()),
            'transaction_id' => sanitize_text_field((string) $order->get_transaction_id()),
        ];
    }

    private function is_paid(\WC_Order $order): bool {
        return $order->is_paid() || $order->has_status(['processing', 'completed']);
    }

    private function gateway(string $id): ?\WC_Payment_Gateway {
        $gateway = $this->gateways()[sanitize_key($id)] ?? null;

        return $gateway instanceof \WC_Payment_Gateway ? $gateway : null;
    }

    private function gateways(): array {
        if (!function_exists('WC') || !WC() || !WC()->payment_gateways()) {
            return [];
        }

        $gateways = WC()->payment_gateways()->payment_gateways();

        return is_array($gateways) ? $gateways : [];
    }

    private function version_supported(string $plugin, string $minimum, string $maximum): bool {
        $file = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/' . $plugin : '';
        if (!$file || !is_readable($file)) {
            return false;
        }

        $headers = get_file_data($file, ['Version' => 'Version'], 'plugin');
        $version = sanitize_text_field((string) ($headers['Version'] ?? ''));

        return $version
            && version_compare($version, $minimum, '>=')
            && version_compare($version, $maximum, '<');
    }
}
