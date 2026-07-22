<?php
/**
 * WooCommerce payment provider adapter.
 *
 * @package MillionDollarScript\Extensions\WooCommerce
 */

namespace MillionDollarScript\Extensions\WooCommerce;

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

        add_filter('million-dollar-script/payment/provider/options', [$this, 'provider_options']);
        add_filter('million-dollar-script/payment/providers', [$this, 'providers']);
        add_action('woocommerce_order_status_processing', [$this, 'mark_paid']);
        add_action('woocommerce_order_status_completed', [$this, 'mark_paid']);
        add_action('woocommerce_payment_complete', [$this, 'mark_paid']);
        add_action('woocommerce_order_status_cancelled', [$this, 'mark_cancelled']);
        add_action('woocommerce_order_status_failed', [$this, 'mark_cancelled']);
        add_action('woocommerce_order_status_refunded', [$this, 'mark_cancelled']);
        add_filter('woocommerce_my_account_my_orders_actions', [$this, 'my_account_order_actions'], 10, 2);
        add_filter('user_has_cap', [$this, 'grant_mds_order_payment_capability'], 20, 3);
        add_filter('million-dollar-script/account/url', [$this, 'account_url'], 10, 2);
        add_filter('million-dollar-script/checkout/landing/url', [$this, 'checkout_landing_url'], 10, 3);
        add_filter('woocommerce_login_redirect', [$this, 'login_redirect'], 10, 2);
        add_action('million-dollar-script/setup/payment/provider/actions', [$this, 'setup_provider_actions'], 10, 2);
        add_filter('million-dollar-script/settings/field/schema', [$this, 'settings_field_schema'], 10, 2);
        add_filter('million-dollar-script/extension/onboarding/items', [$this, 'extension_onboarding_items']);
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
            $this->apply_customer_details($wc_order, $transaction);
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
        $checkout_url = $this->add_mds_order_credentials_to_checkout_url($checkout_url, $transaction, $wc_order);

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
        $wc_order = function_exists('wc_get_order') ? wc_get_order($wc_order_id) : null;
        $this->sync_source_status($wc_order_id, $this->mds_status_for_wc_order($wc_order));
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
        if (!$url && class_exists('\\MillionDollarScript\\Commerce\\Payments')) {
            $mds_order_id = absint($wc_order->get_meta('_mds3_order_id'));
            if ($mds_order_id && $this->can_current_customer_manage($wc_order)) {
                $order = \MillionDollarScript\Core\Orders::find($mds_order_id);
                $url = $order ? \MillionDollarScript\Commerce\Payments::customer_manage_url_for_mds_order($order) : '';
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

    public function grant_mds_order_payment_capability($allcaps, $caps, $args) {
        if (empty($caps[0]) || 'pay_for_order' !== $caps[0]) {
            return $allcaps;
        }

        $wc_order_id = absint($args[2] ?? 0);
        if (!$wc_order_id || empty($_GET['mds3_order_id']) || empty($_GET['mds3_order_key']) || empty($_GET['key'])) {
            return $allcaps;
        }

        if (!function_exists('wc_get_order') || !class_exists('\\MillionDollarScript\\Core\\Orders')) {
            return $allcaps;
        }

        $wc_order = wc_get_order($wc_order_id);
        if (!$wc_order || !method_exists($wc_order, 'get_meta') || !method_exists($wc_order, 'get_order_key')) {
            return $allcaps;
        }

        $request_order_key = sanitize_text_field(wp_unslash($_GET['key']));
        if (!hash_equals((string) $wc_order->get_order_key(), $request_order_key)) {
            return $allcaps;
        }

        $source = sanitize_key((string) $wc_order->get_meta('_mds3_payment_source'));
        if ($source && 'mds-grid' !== $source) {
            return $allcaps;
        }

        $linked_mds_order_id = absint($wc_order->get_meta('_mds3_order_id'));
        if (!$linked_mds_order_id) {
            $linked_mds_order_id = absint($wc_order->get_meta('_mds3_payment_source_id'));
        }

        $request_mds_order_id = absint(wp_unslash($_GET['mds3_order_id']));
        if (!$linked_mds_order_id || $linked_mds_order_id !== $request_mds_order_id) {
            return $allcaps;
        }

        $mds_order = \MillionDollarScript\Core\Orders::find($linked_mds_order_id);
        if (!$mds_order) {
            return $allcaps;
        }

        if (
            !empty($mds_order['commerce_order_id']) &&
            absint($mds_order['commerce_order_id']) !== $wc_order_id
        ) {
            return $allcaps;
        }

        $request_mds_order_key = sanitize_text_field(wp_unslash($_GET['mds3_order_key']));
        if (!hash_equals((string) ($mds_order['order_key'] ?? ''), $request_mds_order_key)) {
            return $allcaps;
        }

        $allcaps['pay_for_order'] = true;

        return $allcaps;
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
        if (!$this->ready() || !function_exists('wc_get_checkout_url') || !class_exists('\\MillionDollarScript\\Commerce\\Payments')) {
            return $url;
        }

        if ('woocommerce' !== \MillionDollarScript\Commerce\Payments::active_provider_id($settings)) {
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
        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=mds3-woocommerce')) . '">' . esc_html__('Review checkout readiness', 'mds-woocommerce') . '</a></p>';
    }

    /**
     * Add WooCommerce setup shortcuts and legal draft recommendations.
     *
     * @param array $items Existing onboarding items.
     * @return array
     */
    public function extension_onboarding_items(array $items) {
        $items['mds-woocommerce'] = [
            'name' => __('WooCommerce Checkout', 'mds-woocommerce'),
            'summary' => __('Review store setup, currency, taxes, customer account behavior, payment gateways, order statuses, renewals, and failed-payment handling before accepting placements.', 'mds-woocommerce'),
            'priority' => 10,
            'actions' => [
                [
                    'label' => __('Open WooCommerce setup', 'mds-woocommerce'),
                    'url' => admin_url('admin.php?page=wc-admin'),
                    'primary' => true,
                ],
                [
                    'label' => __('Review checkout settings', 'mds-woocommerce'),
                    'url' => admin_url('admin.php?page=mds3-woocommerce'),
                ],
            ],
            'legal_documents' => [
                [
                    'slug' => 'checkout-payment-terms',
                    'title' => __('WooCommerce Checkout and Payment Terms', 'mds-woocommerce'),
                    'description' => __('Terms for checkout, fees, taxes, currencies, renewals, failed payments, refunds, order status changes, and account access.', 'mds-woocommerce'),
                    'page_slug' => 'woocommerce-checkout-payment-terms',
                    'content' => $this->woocommerce_checkout_terms_content(),
                ],
                [
                    'slug' => 'order-privacy-notice',
                    'title' => __('WooCommerce Order Privacy Notice', 'mds-woocommerce'),
                    'description' => __('Privacy language for customer email, billing details, order metadata, payment gateways, manage links, and support records.', 'mds-woocommerce'),
                    'page_slug' => 'woocommerce-order-privacy-notice',
                    'content' => $this->woocommerce_order_privacy_content(),
                ],
            ],
        ];

        return $items;
    }

    /**
     * Draft WooCommerce checkout terms content.
     *
     * @return string
     */
    private function woocommerce_checkout_terms_content() {
        return '<h2>' . esc_html__('WooCommerce Checkout and Payment Terms', 'mds-woocommerce') . '</h2>'
            . '<p>' . esc_html__('These terms apply when {{site_name}} uses WooCommerce to collect payment for Million Dollar Script grid placements, sponsor bookings, featured listings, renewals, and extension-owned monetization flows.', 'mds-woocommerce') . '</p>'
            . '<h3>' . esc_html__('Checkout, Currency, and Taxes', 'mds-woocommerce') . '</h3>'
            . '<p>' . esc_html__('Prices, taxes, discounts, gateway fees, invoices, and payment instructions are controlled by WooCommerce, enabled payment gateways, and any enabled tax or multi-currency tools. The current store currency may be {{currency_code}}. The final amount due is the amount shown during checkout, including any applicable tax, discount, conversion, payment-provider fee, or manual adjustment.', 'mds-woocommerce') . '</p>'
            . '<h3>' . esc_html__('Order Status and Placement Activation', 'mds-woocommerce') . '</h3>'
            . '<p>' . esc_html__('A placement, renewal, or sponsored listing may remain pending until WooCommerce marks the linked order paid, processing, or completed according to the configured payment workflow. Failed, cancelled, refunded, disputed, reversed, expired, or manually changed orders may pause, cancel, expire, hide, remove, or return the linked Million Dollar Script placement to an unpaid status according to site rules.', 'mds-woocommerce') . '</p>'
            . '<h3>' . esc_html__('Renewals and Expiration', 'mds-woocommerce') . '</h3>'
            . '<p>' . esc_html__('Renewal links, renewal pricing, renewal windows, placement expiration, grace periods, and expired-placement visibility depend on the Million Dollar Script and WooCommerce settings active at the time of renewal. If the original WooCommerce order can no longer be paid, a new renewal order may be required. If subscriptions or recurring billing are enabled, cancellation, failed renewal, retry, and grace-period behavior follow the subscription and gateway terms shown to the customer.', 'mds-woocommerce') . '</p>'
            . '<h3>' . esc_html__('Refunds, Chargebacks, and Removals', 'mds-woocommerce') . '</h3>'
            . '<p>' . esc_html__('Refund eligibility, non-refundable fees, cancellation rules, manual review, gateway dispute handling, and chargeback handling follow the checkout terms, gateway terms, and site policies presented at purchase. Refunds, chargebacks, payment reversals, fraud-risk decisions, abuse reports, or policy violations may cause the linked placement to be removed, hidden, expired, or returned to an unpaid status.', 'mds-woocommerce') . '</p>'
            . '<h3>' . esc_html__('Accounts, Access Links, and Customer Responsibility', 'mds-woocommerce') . '</h3>'
            . '<p>' . esc_html__('Customers are responsible for providing accurate billing information, keeping account credentials secure, and treating pay links, order keys, renewal links, and manage links as private access links. Customers should use {{contact_method}} promptly if they believe an order, payment, renewal, or manage link has been accessed without authorization.', 'mds-woocommerce') . '</p>';
    }

    /**
     * Draft WooCommerce order privacy content.
     *
     * @return string
     */
    private function woocommerce_order_privacy_content() {
        return '<h2>' . esc_html__('WooCommerce Order Privacy Notice', 'mds-woocommerce') . '</h2>'
            . '<p>' . esc_html__('This notice explains how {{site_name}} handles WooCommerce order data connected to Million Dollar Script placements on {{site_url}}. It supplements the main privacy policy at {{privacy_policy_url}} and any privacy information shown by enabled payment gateways.', 'mds-woocommerce') . '</p>'
            . '<h3>' . esc_html__('How Order Data Is Used', 'mds-woocommerce') . '</h3>'
            . '<p>' . esc_html__('WooCommerce checkout may collect names, email addresses, billing details, payment references, order IDs, order status, linked Million Dollar Script order IDs, selected placement details, renewal data, manage-page links, support notes, technical request data, and gateway metadata. Order data is used to process payment, connect WooCommerce orders to placements, send receipts and renewal notices, provide account access, prevent abuse, support customers, handle refunds or disputes, and keep accounting and audit records.', 'mds-woocommerce') . '</p>'
            . '<h3>' . esc_html__('Payment Gateways and Accounts', 'mds-woocommerce') . '</h3>'
            . '<p>' . esc_html__('Payment details may be processed by enabled WooCommerce gateways such as card processors, wallets, bank-transfer providers, offline payment methods, invoice providers, or fraud-prevention services. Those providers may process personal data under their own terms and privacy policies. Customers may use WooCommerce account features to view orders, pay pending orders, manage billing details, and download receipts when those features are enabled.', 'mds-woocommerce') . '</p>'
            . '<h3>' . esc_html__('Retention and Access', 'mds-woocommerce') . '</h3>'
            . '<p>' . esc_html__('Order records, renewal emails, payment references, customer messages, placement-management links, tax records, and security logs may be retained for support, renewals, accounting, fraud prevention, legal compliance, dispute handling, backup recovery, and audit purposes. Customers can request access, correction, deletion, export, or account removal through {{contact_method}}, subject to tax, accounting, fraud-prevention, chargeback, security, backup, and dispute-retention requirements.', 'mds-woocommerce') . '</p>'
            . '<h3>' . esc_html__('Security and Manage Links', 'mds-woocommerce') . '</h3>'
            . '<p>' . esc_html__('Manage links, payment links, and order keys should be treated as private access links. Customers should not share them publicly. Site administrators should revoke or rotate links when there is evidence of unauthorized access.', 'mds-woocommerce') . '</p>';
    }

    public function settings_field_schema($field, $key) {
        if ('woocommerce-login-redirect' === $key) {
            return $this->woocommerce_login_redirect_field();
        }

        return $field;
    }

    /**
     * Apply the optional same-site post-login destination.
     *
     * @param string   $redirect Default WooCommerce redirect URL.
     * @param \WP_User $user     Authenticated user.
     * @return string
     */
    public function login_redirect($redirect, $user = null) {
        unset($user);

        $settings = get_option('mds3_settings', []);
        $configured = is_array($settings) ? esc_url_raw((string) ($settings['woocommerce-login-redirect'] ?? '')) : '';
        if (!$configured) {
            return $redirect;
        }

        return wp_validate_redirect($configured, $redirect);
    }

    public function currency_code($fallback = 'USD') {
        return function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : strtoupper(substr(sanitize_text_field((string) $fallback), 0, 3));
    }

    public function currency_symbol($fallback = '$') {
        return function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol($this->currency_code()) : sanitize_text_field((string) $fallback);
    }

    private function sync_source_status($wc_order_id, $status) {
        if (!$this->ready() || !class_exists('\\MillionDollarScript\\Commerce\\Payments')) {
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
            \MillionDollarScript\Commerce\Payments::mark_source_paid($source, $source_id, $context);
        } elseif (method_exists('\\MillionDollarScript\\Commerce\\Payments', 'mark_source_status')) {
            \MillionDollarScript\Commerce\Payments::mark_source_status($source, $source_id, $status, $context);
        } else {
            \MillionDollarScript\Commerce\Payments::mark_source_cancelled($source, $source_id, $context);
        }
    }

    private function existing_order(array $transaction) {
        $existing_id = absint($transaction['existing_provider_order_id'] ?? 0);
        if (!$existing_id || !function_exists('wc_get_order')) {
            return null;
        }

        $wc_order = wc_get_order($existing_id);
        if (!$wc_order || !method_exists($wc_order, 'get_meta')) {
            return null;
        }

        $source = sanitize_key((string) ($transaction['source'] ?? ''));
        $source_id = absint($transaction['source_id'] ?? 0);
        if ($source && $source_id) {
            $order_source = sanitize_key((string) $wc_order->get_meta('_mds3_payment_source'));
            $order_source_id = absint($wc_order->get_meta('_mds3_payment_source_id'));
            if (!$order_source && absint($wc_order->get_meta('_mds3_order_id'))) {
                $order_source = 'mds-grid';
                $order_source_id = absint($wc_order->get_meta('_mds3_order_id'));
            }

            if ($order_source !== $source || $order_source_id !== $source_id) {
                return null;
            }
        }

        return $wc_order;
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
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency') && method_exists('\\MillionDollarScript\\Commerce\\Currency', 'normalize_code')) {
            return \MillionDollarScript\Commerce\Currency::normalize_code($currency, function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD');
        }

        $currency = strtoupper(substr(preg_replace('/[^A-Z]/', '', strtoupper(sanitize_text_field((string) $currency))), 0, 3));

        return $currency ?: (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD');
    }

    private function apply_customer_details($wc_order, array $transaction) {
        $email = sanitize_email((string) ($transaction['email'] ?? ''));
        if ($email) {
            if (method_exists($wc_order, 'set_billing_email')) {
                $wc_order->set_billing_email($email);
            }
            if (method_exists($wc_order, 'update_meta_data')) {
                $wc_order->update_meta_data('_mds3_customer_email', $email);
            }
        }
    }

    private function ready() {
        $ready = class_exists('\\WooCommerce') && function_exists('wc_create_order') && function_exists('wc_get_order') && class_exists('\\WC_Order_Item_Fee');

        return $ready && (bool) \MillionDollarScript\Core\Hooks::apply_compat('million-dollar-script/extensions/woocommerce/provider/ready', ['mds_woocommerce_provider_ready'], true);
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

    private function add_mds_order_credentials_to_checkout_url($checkout_url, array $transaction, $wc_order) {
        $checkout_url = esc_url_raw((string) $checkout_url);
        if (!$checkout_url) {
            return '';
        }

        $source = sanitize_key((string) ($transaction['source'] ?? ''));
        $source_id = absint($transaction['source_id'] ?? 0);
        $source_key = sanitize_text_field((string) ($transaction['source_key'] ?? ''));

        if ('mds-grid' !== $source && $wc_order && method_exists($wc_order, 'get_meta')) {
            $source = sanitize_key((string) $wc_order->get_meta('_mds3_payment_source'));
            $source_id = absint($wc_order->get_meta('_mds3_order_id'));
            if (!$source_id) {
                $source_id = absint($wc_order->get_meta('_mds3_payment_source_id'));
            }
            $source_key = sanitize_text_field((string) $wc_order->get_meta('_mds3_order_key'));
        }

        if ('mds-grid' !== $source || !$source_id || !$source_key) {
            return $checkout_url;
        }

        return esc_url_raw(add_query_arg([
            'mds3_order_id' => $source_id,
            'mds3_order_key' => $source_key,
        ], $checkout_url));
    }

    private function mds_status_for_wc_order($wc_order) {
        $status = $wc_order && method_exists($wc_order, 'get_status') ? sanitize_key((string) $wc_order->get_status()) : '';

        if (in_array($status, ['failed', 'refunded'], true)) {
            return $status;
        }

        return 'cancelled';
    }

    private function mark_transaction_paid(array $transaction, $wc_order) {
        if (!class_exists('\\MillionDollarScript\\Commerce\\Payments')) {
            return;
        }

        $source = sanitize_key((string) ($transaction['source'] ?? ''));
        $source_id = absint($transaction['source_id'] ?? 0);
        if (!$source || !$source_id) {
            return;
        }

        \MillionDollarScript\Commerce\Payments::mark_source_paid($source, $source_id, [
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
            'help' => __('Optional same-site URL WooCommerce customers are sent to after login.', 'mds-woocommerce'),
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
