<?php
/**
 * Plugin Name: Million Dollar Script - WooCommerce Checkout
 * Plugin URI: https://milliondollarscript.com/extensions/woocommerce
 * Description: WooCommerce payment provider adapter for Million Dollar Script checkout and monetization extensions.
 * Version: 1.1.0
 * Author: Million Dollar Script
 * Author URI: https://milliondollarscript.com
 * Text Domain: mds-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 7.0.3
 * WC tested up to: 11.0.0
 * Requires PHP: 8.1
 * License: GPL-3.0+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires Plugins: million-dollar-script, woocommerce
 *
 * Requires MDS: 3.0.0
 * Requires MDS API: 1
 * Requires License: No
 * MDS Provides: payments.woocommerce commerce.woocommerce
 * MDS Requires: platform.core
 * MDS Recommends: mds-grid mds-sponsorboard
 * MDS Requires Service: no
 * MDS Setup Category: payments
 * MDS Minimum Security Level: api_key_read
 * MDS LLM Safe Actions: read_provider_status
 *
 * @package MillionDollarScript\Extensions\WooCommerce
 */

namespace MillionDollarScript\Extensions\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('MDS_WOOCOMMERCE_VERSION')) {
    define('MDS_WOOCOMMERCE_VERSION', '1.1.0');
}

if (!defined('MDS_WOOCOMMERCE_FILE')) {
    define('MDS_WOOCOMMERCE_FILE', __FILE__);
}

if (!defined('MDS_WOOCOMMERCE_PATH')) {
    define('MDS_WOOCOMMERCE_PATH', plugin_dir_path(__FILE__));
}

if (!defined('MDS_WOOCOMMERCE_URL')) {
    define('MDS_WOOCOMMERCE_URL', plugin_dir_url(__FILE__));
}

if (!defined('MDS_WOOCOMMERCE_BASENAME')) {
    define('MDS_WOOCOMMERCE_BASENAME', plugin_basename(__FILE__));
}

function mds_woocommerce_core_active() {
    return class_exists('\\MillionDollarScript\\Core\\Runtime')
        && \MillionDollarScript\Core\Runtime::is_ready()
        && class_exists('\\MillionDollarScript\\Commerce\\Payments');
}

function mds_woocommerce_woocommerce_active() {
    return class_exists('\\WooCommerce') && function_exists('wc_create_order') && function_exists('wc_get_order');
}

function mds_woocommerce_ready() {
    return mds_woocommerce_core_active() && mds_woocommerce_woocommerce_active();
}

function mds_woocommerce_load_files() {
    require_once MDS_WOOCOMMERCE_PATH . 'includes/AutomaticRenewals.php';
    require_once MDS_WOOCOMMERCE_PATH . 'includes/Main.php';
    require_once MDS_WOOCOMMERCE_PATH . 'includes/Admin.php';
}

function mds_woocommerce_missing_notice() {
    echo '<div class="notice notice-error"><p>';
    echo '<strong>' . esc_html__('Million Dollar Script - WooCommerce Checkout', 'mds-woocommerce') . '</strong>: ';
    echo esc_html__('This extension requires Million Dollar Script and WooCommerce to be installed and activated.', 'mds-woocommerce');
    echo '</p></div>';
}

function mds_woocommerce_init() {
    if (!mds_woocommerce_ready()) {
        add_action('admin_notices', __NAMESPACE__ . '\\mds_woocommerce_missing_notice');
        return;
    }

    mds_woocommerce_load_files();
    Main::init();
    Admin::init();
}

function mds_woocommerce_activate() {
    if (!mds_woocommerce_ready()) {
        deactivate_plugins(MDS_WOOCOMMERCE_BASENAME);
        wp_die(
            esc_html__('Million Dollar Script - WooCommerce Checkout requires Million Dollar Script and WooCommerce to be installed and activated.', 'mds-woocommerce'),
            esc_html__('Plugin Activation Error', 'mds-woocommerce'),
            ['back_link' => true]
        );
    }

    set_transient('mds_woocommerce_activated', true, 30);
}

function mds_woocommerce_activation_notice() {
    if (!get_transient('mds_woocommerce_activated')) {
        return;
    }

    delete_transient('mds_woocommerce_activated');
    echo '<div class="notice notice-success is-dismissible"><p>';
    echo '<strong>' . esc_html__('Million Dollar Script - WooCommerce Checkout', 'mds-woocommerce') . '</strong>: ';
    echo esc_html__('WooCommerce is available as a Million Dollar Script payment provider.', 'mds-woocommerce');
    echo ' <a href="' . esc_url(admin_url('admin.php?page=mds3-woocommerce')) . '">' . esc_html__('Review checkout setup', 'mds-woocommerce') . '</a>';
    echo '</p></div>';
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\\mds_woocommerce_activate');
add_action('plugins_loaded', __NAMESPACE__ . '\\mds_woocommerce_init', 25);
add_action('admin_notices', __NAMESPACE__ . '\\mds_woocommerce_activation_notice');
add_action('init', function() {
    load_plugin_textdomain('mds-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages');
});
