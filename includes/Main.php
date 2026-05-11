<?php
/**
 * WooCommerce payment provider adapter.
 *
 * @package MDS\Extensions\WooCommerce
 */

namespace MDS\Extensions\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

class Main {

    /**
     * @var Main|null
     */
    private static $instance = null;

    /**
     * @var bool
     */
    private $initialized = false;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function init() {
        self::instance()->initialize();
    }

    private function initialize() {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        add_filter('mds3_payment_provider_options', [$this, 'provider_options']);
        add_filter('mds3_payment_providers', [$this, 'providers']);
        add_action('woocommerce_order_status_processing', [$this, 'mark_paid']);
        add_action('woocommerce_order_status_completed', [$this, 'mark_paid']);
        add_action('woocommerce_payment_complete', [$this, 'mark_paid']);
        add_action('woocommerce_order_status_cancelled', [$this, 'mark_cancelled']);
        add_action('woocommerce_order_status_failed', [$this, 'mark_cancelled']);
        add_action('woocommerce_order_status_refunded', [$this, 'mark_cancelled']);
        add_filter('woocommerce_my_account_my_orders_actions', [$this, 'my_account_order_actions'], 10, 2);
        add_filter('mds3_account_url', [$this, 'account_url'], 10, 2);
        add_filter('mds3_checkout_landing_url', [$this, 'checkout_landing_url'], 10, 3);
        add_action('mds3_setup_payment_provider_actions', [$this, 'setup_provider_actions'], 10, 2);
        add_filter('mds3_admin_settings_groups', [$this, 'settings_groups']);
        add_filter('mds3_settings_field_schema', [$this, 'settings_field_schema'], 10, 2);
    }

    public function provider_options(array $options) {
        $options['woocommerce'] = __('WooCommerce', 'mds-woocommerce');

        return $options;
    }

    public function providers(array $providers) {
        $providers['woocommerce'] = [
            'id' => 'woocommerce',
            'label' => __('WooCommerce', 'mds-woocommerce'),
            'ready' => $this->ready(),
            'locks_currency' => true,
            'currency_code' => [$this, 'currency_code'],
            'currency_symbol' => [$this, 'currency_symbol'],
            'create_checkout' => [$this, 'create_checkout'],
            'complete_source_order' => [$this, 'complete_source_order'],
        ];

        return $providers;
    }

    public function create_checkout(array $transaction, array $payload = []) {
        if (!$this->ready()) {
            return new \WP_Error('mds_woocommerce_unavailable', __('WooCommerce is not ready for checkout.', 'mds-woocommerce'));
        }

        $wc_order = $this->existing_order($transaction);
        if (!$wc_order) {
            $legacy_mds2_hook_removed = $this->suppress_legacy_mds2_new_order_hook();
            try {
                $wc_order = wc_create_order(['customer_id' => absint($transaction['user_id'] ?? 0)]);
            } finally {
                if ($legacy_mds2_hook_removed) {
                    $this->restore_legacy_mds2_new_order_hook();
                }
            }
            if (is_wp_error($wc_order)) {
                return $wc_order;
            }

            foreach ($this->items($transaction) as $item) {
                $fee = new \WC_Order_Item_Fee();
                $fee->set_name((string) ($item['name'] ?? __('Million Dollar Script item', 'mds-woocommerce')));
                $fee->set_amount((float) ($item['amount'] ?? 0));
                $fee->set_total((float) ($item['amount'] ?? 0));
                foreach ((array) ($item['metadata'] ?? []) as $key => $value) {
                    if (is_scalar($value)) {
                        $fee->add_meta_data('_mds3_' . sanitize_key((string) $key), sanitize_text_field((string) $value), true);
                    }
                }
                $wc_order->add_item($fee);
            }

            $source = sanitize_key((string) ($transaction['source'] ?? ''));
            $source_id = absint($transaction['source_id'] ?? 0);
            $wc_order->set_currency($this->currency($transaction['currency'] ?? ''));
            $wc_order->update_meta_data('_mds3_payment_source', $source);
            $wc_order->update_meta_data('_mds3_payment_source_id', $source_id);
            $wc_order->update_meta_data('_mds3_payment_provider', 'woocommerce');

            if ('mds-grid' === $source) {
                $wc_order->update_meta_data('_mds3_order_id', $source_id);
                $wc_order->update_meta_data('_mds3_order_key', sanitize_text_field((string) ($transaction['source_key'] ?? '')));
            }

            if (!empty($transaction['manage_url'])) {
                $wc_order->update_meta_data('_mds3_manage_url', esc_url_raw((string) $transaction['manage_url']));
            }

            $wc_order->calculate_totals(false);
            $wc_order->set_status('pending');
            $wc_order->save();
        }

        if ($this->is_paid_order($wc_order)) {
            $this->mark_transaction_paid($transaction, $wc_order);

            return array_merge($payload, [
                'provider' => 'woocommerce',
                'provider_order_id' => (string) $wc_order->get_id(),
                'checkout_url' => '',
                'after_upload_url' => esc_url_raw((string) ($transaction['manage_url'] ?? ($payload['after_upload_url'] ?? ''))),
            ]);
        }

        $checkout_url = $this->order_needs_payment($wc_order) ? $wc_order->get_checkout_payment_url() : '';

        return array_merge($payload, [
            'provider' => 'woocommerce',
            'provider_order_id' => (string) $wc_order->get_id(),
            'checkout_url' => $checkout_url,
            'after_upload_url' => esc_url_raw((string) ($checkout_url ?: ($transaction['manage_url'] ?? ($payload['after_upload_url'] ?? '')))),
        ]);
    }

    public function mark_paid($wc_order_id) {
        $this->sync_source_status($wc_order_id, 'paid');
    }

    public function mark_cancelled($wc_order_id) {
        $this->sync_source_status($wc_order_id, 'cancelled');
    }

    public function complete_source_order(array $order) {
        if (!$this->ready() || empty($order['commerce_order_id'])) {
            return false;
        }

        $wc_order = wc_get_order(absint($order['commerce_order_id']));
        if (!$wc_order || !method_exists($wc_order, 'update_status')) {
            return false;
        }

        if (!$wc_order->has_status('completed')) {
            $wc_order->update_status(
                'completed',
                __('Marked completed because the linked Million Dollar Script order was marked paid.', 'mds-woocommerce'),
                true
            );
        }

        return true;
    }

    public function my_account_order_actions($actions, $wc_order) {
        if (!$wc_order || !method_exists($wc_order, 'get_meta')) {
            return $actions;
        }

        $url = esc_url_raw((string) $wc_order->get_meta('_mds3_manage_url'));
        if (!$url && class_exists('\\MDS3\\Commerce\\Payments')) {
            $mds_order_id = absint($wc_order->get_meta('_mds3_order_id'));
            if ($mds_order_id && $this->can_current_customer_manage($wc_order)) {
                $order = (new \MDS3\Orders\OrderRepository())->find($mds_order_id);
                $url = $order ? \MDS3\Commerce\Payments::customer_manage_url_for_mds_order($order) : '';
            }
        }

        if ($url && $this->can_current_customer_manage($wc_order)) {
            $actions['mds3_manage'] = [
                'url' => esc_url($url),
                'name' => __('Manage', 'mds-woocommerce'),
            ];
        }

        return $actions;
    }

    public function account_url($url, array $settings) {
        if (!$this->ready() || !function_exists('wc_get_page_permalink')) {
            return $url;
        }

        $explicit = esc_url_raw((string) ($settings['account-page'] ?? ''));
        if ($explicit) {
            return $url;
        }

        $woo_url = wc_get_page_permalink('myaccount');

        return $woo_url ? esc_url_raw($woo_url) : $url;
    }

    public function checkout_landing_url($url, array $settings, $fallback) {
        unset($fallback);
        if (!$this->ready() || !function_exists('wc_get_checkout_url') || !class_exists('\\MDS3\\Commerce\\Payments')) {
            return $url;
        }

        if ('woocommerce' !== \MDS3\Commerce\Payments::active_provider_id($settings)) {
            return $url;
        }

        $checkout_url = wc_get_checkout_url();

        return $checkout_url ? esc_url_raw($checkout_url) : $url;
    }

    public function setup_provider_actions($provider, array $settings) {
        unset($settings);
        if ('woocommerce' !== $provider) {
            return;
        }

        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=wc-admin')) . '">' . esc_html__('Open WooCommerce setup', 'mds-woocommerce') . '</a></p>';
    }

    public function settings_groups(array $groups) {
        $groups[__('WooCommerce Checkout', 'mds-woocommerce')][] = $this->woocommerce_login_redirect_field();

        return $groups;
    }

    public function settings_field_schema($field, $key) {
        if ('woocommerce-login-redirect' === $key) {
            return $this->woocommerce_login_redirect_field();
        }

        return $field;
    }

    public function currency_code($fallback = 'USD') {
        return function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : strtoupper(substr(sanitize_text_field((string) $fallback), 0, 3));
    }

    public function currency_symbol($fallback = '$') {
        return function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol($this->currency_code()) : sanitize_text_field((string) $fallback);
    }

    private function sync_source_status($wc_order_id, $status) {
        if (!$this->ready() || !class_exists('\\MDS3\\Commerce\\Payments')) {
            return;
        }

        $wc_order = wc_get_order($wc_order_id);
        if (!$wc_order) {
            return;
        }

        $source = sanitize_key((string) $wc_order->get_meta('_mds3_payment_source'));
        $source_id = absint($wc_order->get_meta('_mds3_payment_source_id'));
        if (!$source && absint($wc_order->get_meta('_mds3_order_id'))) {
            $source = 'mds-grid';
            $source_id = absint($wc_order->get_meta('_mds3_order_id'));
        }
        if (!$source || !$source_id) {
            return;
        }

        $context = [
            'provider' => 'woocommerce',
            'provider_order_id' => absint($wc_order->get_id()),
            'provider_status' => method_exists($wc_order, 'get_status') ? sanitize_key((string) $wc_order->get_status()) : '',
        ];

        if ('paid' === $status) {
            \MDS3\Commerce\Payments::mark_source_paid($source, $source_id, $context);
        } else {
            \MDS3\Commerce\Payments::mark_source_cancelled($source, $source_id, $context);
        }
    }

    private function existing_order(array $transaction) {
        $existing_id = absint($transaction['existing_provider_order_id'] ?? 0);
        if (!$existing_id || !function_exists('wc_get_order')) {
            return null;
        }

        return wc_get_order($existing_id) ?: null;
    }

    private function items(array $transaction) {
        $items = is_array($transaction['items'] ?? null) ? $transaction['items'] : [];
        if ($items) {
            return $items;
        }

        return [[
            'name' => __('Million Dollar Script item', 'mds-woocommerce'),
            'amount' => (float) ($transaction['total'] ?? 0),
            'metadata' => [],
        ]];
    }

    private function currency($currency) {
        $currency = strtoupper(substr(sanitize_text_field((string) $currency), 0, 3));

        return $currency ?: (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD');
    }

    private function ready() {
        return class_exists('\\WooCommerce') && function_exists('wc_create_order') && function_exists('wc_get_order') && class_exists('\\WC_Order_Item_Fee');
    }

    private function is_paid_order($wc_order) {
        if (!$wc_order) {
            return false;
        }
        if (method_exists($wc_order, 'is_paid') && $wc_order->is_paid()) {
            return true;
        }

        $status = method_exists($wc_order, 'get_status') ? sanitize_key((string) $wc_order->get_status()) : '';
        $paid_statuses = function_exists('wc_get_is_paid_statuses') ? array_map('sanitize_key', (array) wc_get_is_paid_statuses()) : ['processing', 'completed'];

        return $status && in_array($status, $paid_statuses, true);
    }

    private function order_needs_payment($wc_order) {
        if (!$wc_order) {
            return false;
        }
        if (method_exists($wc_order, 'needs_payment')) {
            return (bool) $wc_order->needs_payment();
        }

        $status = method_exists($wc_order, 'get_status') ? sanitize_key((string) $wc_order->get_status()) : '';

        return in_array($status, ['pending', 'failed'], true);
    }

    private function mark_transaction_paid(array $transaction, $wc_order) {
        if (!class_exists('\\MDS3\\Commerce\\Payments')) {
            return;
        }

        $source = sanitize_key((string) ($transaction['source'] ?? ''));
        $source_id = absint($transaction['source_id'] ?? 0);
        if ('mds-grid' !== $source || !$source_id) {
            return;
        }

        \MDS3\Commerce\Payments::mark_source_paid($source, $source_id, [
            'provider' => 'woocommerce',
            'provider_order_id' => method_exists($wc_order, 'get_id') ? absint($wc_order->get_id()) : 0,
            'provider_status' => method_exists($wc_order, 'get_status') ? sanitize_key((string) $wc_order->get_status()) : '',
        ]);
    }

    private function woocommerce_login_redirect_field() {
        return [
            'key' => 'woocommerce-login-redirect',
            'label' => __('WooCommerce Login Redirect URL', 'mds-woocommerce'),
            'type' => 'url',
            'default' => '',
            'help' => __('URL WooCommerce customers are sent to after login.', 'mds-woocommerce'),
        ];
    }

    private function suppress_legacy_mds2_new_order_hook() {
        $callback = ['MillionDollarScript\\Classes\\WooCommerce\\WooCommerce', 'new_order'];
        if (!has_action('woocommerce_new_order', $callback)) {
            return false;
        }

        remove_action('woocommerce_new_order', $callback, 10);

        return true;
    }

    private function restore_legacy_mds2_new_order_hook() {
        add_action('woocommerce_new_order', ['MillionDollarScript\\Classes\\WooCommerce\\WooCommerce', 'new_order'], 10, 1);
    }

    private function can_current_customer_manage($wc_order) {
        if (current_user_can('manage_options')) {
            return true;
        }
        if (!$wc_order || !method_exists($wc_order, 'get_customer_id')) {
            return false;
        }

        $customer_id = absint($wc_order->get_customer_id());
        if (!$customer_id) {
            if (!method_exists($wc_order, 'get_billing_email') || !function_exists('wp_get_current_user')) {
                return false;
            }

            $user = wp_get_current_user();
            $user_email = sanitize_email((string) ($user->user_email ?? ''));
            $billing_email = sanitize_email((string) $wc_order->get_billing_email());

            return $user_email && $billing_email && hash_equals(strtolower($user_email), strtolower($billing_email));
        }

        return get_current_user_id() === $customer_id;
    }
}
