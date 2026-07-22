<?php
/**
 * WooCommerce Checkout admin experience.
 *
 * @package MillionDollarScript\Extensions\WooCommerce
 */

namespace MillionDollarScript\Extensions\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

final class Admin {

    /**
     * Register admin hooks.
     *
     * @return void
     */
    public static function init() {
        add_action('million-dollar-script/admin/menu', [__CLASS__, 'register_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_post_mds_woocommerce_save_settings', [__CLASS__, 'save_settings']);
        add_filter('million-dollar-script/dashboard/extension/cards', [__CLASS__, 'dashboard_card']);
    }

    /**
     * Register the extension-owned settings and readiness page.
     *
     * @return void
     */
    public static function register_menu() {
        add_submenu_page(
            'mds3',
            __('WooCommerce Checkout', 'mds-woocommerce'),
            __('WooCommerce Checkout', 'mds-woocommerce'),
            'manage_options',
            'mds3-woocommerce',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Load page-specific presentation styles.
     *
     * @param string $hook Current admin hook.
     * @return void
     */
    public static function enqueue_assets($hook) {
        if (false === strpos((string) $hook, 'mds3-woocommerce')) {
            return;
        }

        $path = MDS_WOOCOMMERCE_PATH . 'assets/css/admin.css';
        $version = file_exists($path) ? MDS_WOOCOMMERCE_VERSION . '.' . filemtime($path) : MDS_WOOCOMMERCE_VERSION;
        wp_enqueue_style('mds-woocommerce-admin', MDS_WOOCOMMERCE_URL . 'assets/css/admin.css', [], $version);
    }

    /**
     * Render the checkout readiness page.
     *
     * @return void
     */
    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage WooCommerce Checkout.', 'mds-woocommerce'));
        }

        $settings = get_option('mds3_settings', []);
        $settings = is_array($settings) ? $settings : [];
        $main = Main::instance();
        $active_provider = class_exists('\\MillionDollarScript\\Commerce\\Payments')
            ? \MillionDollarScript\Commerce\Payments::active_provider_id($settings)
            : 'standalone';
        $currency_code = $main->currency_code((string) ($settings['currency'] ?? 'USD'));
        $currency_symbol = $main->currency_symbol((string) ($settings['currency-symbol'] ?? '$'));
        $enabled_gateways = self::enabled_gateways();
        $pages = self::required_pages();
        $is_local = in_array(wp_parse_url(home_url('/'), PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true);
        $secure_checkout = is_ssl() || $is_local;
        $notice = sanitize_key((string) wp_unslash($_GET['mds_woocommerce_notice'] ?? ''));

        require MDS_WOOCOMMERCE_PATH . 'templates/admin/page.php';
    }

    /**
     * Persist extension settings and provider selection.
     *
     * @return void
     */
    public static function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage WooCommerce Checkout.', 'mds-woocommerce'));
        }

        check_admin_referer('mds_woocommerce_save_settings');

        $settings = get_option('mds3_settings', []);
        $settings = is_array($settings) ? $settings : [];
        $settings['woocommerce-login-redirect'] = esc_url_raw((string) wp_unslash($_POST['woocommerce_login_redirect'] ?? ''));

        $use_woocommerce = !empty($_POST['use_woocommerce']);
        $active_provider = class_exists('\\MillionDollarScript\\Commerce\\Payments')
            ? \MillionDollarScript\Commerce\Payments::active_provider_id($settings)
            : sanitize_key((string) ($settings['payment_provider'] ?? 'standalone'));

        if ($use_woocommerce && mds_woocommerce_ready()) {
            $settings['payment_provider'] = 'woocommerce';
        } elseif (!$use_woocommerce && 'woocommerce' === $active_provider) {
            $settings['payment_provider'] = 'standalone';
        }

        update_option('mds3_settings', $settings, false);

        wp_safe_redirect(add_query_arg([
            'page' => 'mds3-woocommerce',
            'mds_woocommerce_notice' => 'saved',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Add useful extension actions to the core dashboard.
     *
     * @param array $cards Dashboard cards keyed by extension slug.
     * @return array
     */
    public static function dashboard_card(array $cards) {
        $slug = 'mds-woocommerce';
        $card = isset($cards[$slug]) && is_array($cards[$slug]) ? $cards[$slug] : [
            'slug' => $slug,
            'name' => __('WooCommerce Checkout', 'mds-woocommerce'),
            'description' => __('Route Million Dollar Script purchases through WooCommerce gateways, taxes, currencies, and customer orders.', 'mds-woocommerce'),
            'active' => true,
            'installed' => true,
            'actions' => [],
        ];

        $card['description'] = __('Route Million Dollar Script purchases through WooCommerce gateways, taxes, currencies, and customer orders.', 'mds-woocommerce');
        $card['actions'] = [
            [
                'label' => __('Review checkout', 'mds-woocommerce'),
                'url' => admin_url('admin.php?page=mds3-woocommerce'),
                'primary' => true,
                'icon' => 'dashicons-cart',
            ],
            [
                'label' => __('Docs', 'mds-woocommerce'),
                'url' => add_query_arg([
                    'page' => 'mds3-docs',
                    'package' => $slug,
                    'doc' => 'usage',
                ], admin_url('admin.php')),
                'icon' => 'dashicons-book',
            ],
        ];
        $cards[$slug] = $card;

        return $cards;
    }

    /**
     * Return enabled gateway labels.
     *
     * @return array<int, string>
     */
    private static function enabled_gateways() {
        if (!function_exists('WC') || !WC() || !WC()->payment_gateways()) {
            return [];
        }

        $enabled = [];
        foreach ((array) WC()->payment_gateways()->payment_gateways() as $gateway) {
            if (!is_object($gateway) || 'yes' !== (string) ($gateway->enabled ?? 'no')) {
                continue;
            }

            $title = method_exists($gateway, 'get_title') ? $gateway->get_title() : ($gateway->title ?? '');
            $title = wp_strip_all_tags((string) $title);
            if ($title) {
                $enabled[] = $title;
            }
        }

        return array_values(array_unique($enabled));
    }

    /**
     * Inspect required WooCommerce pages without changing store configuration.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function required_pages() {
        $definitions = [
            'checkout' => __('Checkout', 'mds-woocommerce'),
            'myaccount' => __('My account', 'mds-woocommerce'),
        ];
        $pages = [];

        foreach ($definitions as $key => $label) {
            $page_id = function_exists('wc_get_page_id') ? absint(wc_get_page_id($key)) : 0;
            $published = $page_id && 'publish' === get_post_status($page_id);
            $pages[$key] = [
                'label' => $label,
                'page_id' => $page_id,
                'published' => $published,
                'url' => $published ? get_permalink($page_id) : '',
            ];
        }

        return $pages;
    }
}
