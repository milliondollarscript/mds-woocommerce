<?php
/**
 * Local workflow fixture for WooCommerce Checkout.
 *
 * Run with:
 * ./scripts/wp eval-file wp-content/plugins/mds-woocommerce/tests/mds3-fixture.php
 */

if (!defined('ABSPATH')) {
    exit;
}

use MillionDollarScript\Extensions\WooCommerce\Admin;
use MillionDollarScript\Extensions\WooCommerce\Main;
use MillionDollarScript\Commerce\Payments;

if (!class_exists(Main::class) || !class_exists(Admin::class) || !class_exists(Payments::class)) {
    fwrite(STDERR, "WooCommerce Checkout classes are not loaded.\n");
    exit(1);
}
if (!function_exists('wc_create_order') || !function_exists('wc_get_order') || !class_exists('WC_Order_Item_Fee')) {
    fwrite(STDERR, "WooCommerce is not active.\n");
    exit(1);
}

wp_set_current_user(1);
$main = Main::instance();
$original_settings = get_option('mds3_settings', []);
$original_settings = is_array($original_settings) ? $original_settings : [];
$created_order_ids = [];
$status_events = [];
$failures = [];
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};
$status_listener = static function ($source, $source_id, $status, $context) use (&$status_events) {
    if ('fixture-extension' !== $source) {
        return;
    }
    $status_events[] = [
        'source_id' => absint($source_id),
        'status' => sanitize_key((string) $status),
        'provider' => sanitize_key((string) ($context['provider'] ?? '')),
        'provider_order_id' => absint($context['provider_order_id'] ?? 0),
    ];
};
add_action('million-dollar-script/payment/source/status', $status_listener, 50, 4);

try {
    $options = apply_filters('million-dollar-script/payment/provider/options', []);
    $providers = Payments::providers();
    $provider = $providers['woocommerce'] ?? null;
    $assert('WooCommerce' === (string) ($options['woocommerce'] ?? ''), 'WooCommerce should be available in payment provider options.');
    $assert(is_array($provider) && !empty($provider['ready']), 'WooCommerce provider should register as ready.');
    $assert(!empty($provider['locks_currency']) && is_callable($provider['create_checkout'] ?? null), 'WooCommerce provider should lock currency and expose checkout creation.');

    $disabled_filter = static function () {
        return false;
    };
    add_filter('mds_woocommerce_provider_ready', $disabled_filter);
    $disabled_providers = Payments::providers();
    $assert(empty($disabled_providers['woocommerce']['ready']), 'The provider readiness filter should be able to disable WooCommerce.');
    $disabled_checkout = $main->create_checkout([
        'source' => 'fixture-extension',
        'source_id' => 991001,
        'total' => 1,
    ]);
    $assert(is_wp_error($disabled_checkout) && 'mds_woocommerce_unavailable' === $disabled_checkout->get_error_code(), 'Disabled checkout should fail safely.');
    $disabled_settings = array_merge($original_settings, ['payment_provider' => 'woocommerce']);
    $assert('standalone' === Payments::active_provider_id($disabled_settings), 'Unavailable WooCommerce should fall back to standalone checkout.');
    remove_filter('mds_woocommerce_provider_ready', $disabled_filter);

    Admin::register_menu();
    global $submenu;
    $menu_slugs = array_map(static function ($item) {
        return (string) ($item[2] ?? '');
    }, (array) ($submenu['mds3'] ?? []));
    $assert(in_array('mds3-woocommerce', $menu_slugs, true), 'WooCommerce Checkout should register its extension submenu.');

    $onboarding = apply_filters('million-dollar-script/extension/onboarding/items', []);
    $assert(!empty($onboarding['mds-woocommerce']['actions']) && 2 === count($onboarding['mds-woocommerce']['legal_documents'] ?? []), 'WooCommerce onboarding should include setup actions and legal documents.');
    $field = $main->settings_field_schema([], 'woocommerce-login-redirect');
    $assert('url' === (string) ($field['type'] ?? '') && !empty($field['help']), 'WooCommerce login redirect should have a documented URL field schema.');

    $settings = array_merge($original_settings, [
        'payment_provider' => 'woocommerce',
        'woocommerce-login-redirect' => home_url('/fixture-account-destination/'),
    ]);
    update_option('mds3_settings', $settings, false);
    $assert('woocommerce' === Payments::active_provider_id($settings), 'WooCommerce should become the active provider when selected and ready.');
    $assert(home_url('/fixture-account-destination/') === $main->login_redirect(home_url('/default-account/')), 'A same-site login destination should be accepted.');

    $settings['woocommerce-login-redirect'] = 'https://invalid.example.test/account/';
    update_option('mds3_settings', $settings, false);
    $assert(home_url('/default-account/') === $main->login_redirect(home_url('/default-account/')), 'An external login destination should fall back to the WooCommerce default.');

    $source_id = random_int(920000, 929999);
    $transaction = [
        'source' => 'fixture-extension',
        'source_id' => $source_id,
        'source_key' => 'fixture-source-key',
        'user_id' => 0,
        'email' => 'woocommerce-fixture@example.test',
        'currency' => get_woocommerce_currency(),
        'total' => 12.34,
        'manage_url' => home_url('/fixture-manage/'),
        'items' => [[
            'name' => 'Fixture extension purchase',
            'amount' => 12.34,
            'metadata' => ['fixture_key' => 'fixture-value'],
        ]],
    ];
    $checkout = $main->create_checkout($transaction, ['fixture_payload' => 'preserved']);
    $order_id = absint(is_array($checkout) ? ($checkout['provider_order_id'] ?? 0) : 0);
    if ($order_id) {
        $created_order_ids[] = $order_id;
    }
    $order = $order_id ? wc_get_order($order_id) : null;
    $assert(is_array($checkout) && 'woocommerce' === (string) ($checkout['provider'] ?? '') && 'preserved' === (string) ($checkout['fixture_payload'] ?? ''), 'Checkout should return WooCommerce provider data without discarding caller payload.');
    $assert($order && 'fixture-extension' === (string) $order->get_meta('_mds3_payment_source') && $source_id === absint($order->get_meta('_mds3_payment_source_id')), 'WooCommerce order should retain the exact extension source identity.');
    $assert('woocommerce' === (string) $order->get_meta('_mds3_payment_provider') && 'woocommerce-fixture@example.test' === (string) $order->get_billing_email(), 'WooCommerce order should retain provider and customer metadata.');
    $assert(get_woocommerce_currency() === $order->get_currency() && 12.34 === (float) $order->get_total(), 'WooCommerce order should use store currency and transaction total.');
    $fees = $order ? array_values($order->get_items('fee')) : [];
    $assert(1 === count($fees) && 'fixture-value' === (string) $fees[0]->get_meta('_mds3_fixture_key'), 'Checkout should create the expected fee item and sanitized metadata.');
    $assert(!empty($checkout['checkout_url']) && false === strpos((string) $checkout['checkout_url'], 'mds3_order_key='), 'Extension checkout should use a payable URL without grid-only credentials.');

    $reused = $main->create_checkout(array_merge($transaction, ['existing_provider_order_id' => $order_id]));
    $assert($order_id === absint($reused['provider_order_id'] ?? 0), 'A matching source should reuse its existing WooCommerce order.');

    $mismatched = $main->create_checkout(array_merge($transaction, [
        'source_id' => $source_id + 1,
        'existing_provider_order_id' => $order_id,
    ]));
    $mismatched_id = absint(is_array($mismatched) ? ($mismatched['provider_order_id'] ?? 0) : 0);
    if ($mismatched_id) {
        $created_order_ids[] = $mismatched_id;
    }
    $assert($mismatched_id > 0 && $mismatched_id !== $order_id, 'An existing order must not be reused for a different source identity.');

    $actions = $main->my_account_order_actions([], $order);
    $assert(home_url('/fixture-manage/') === (string) ($actions['mds3_manage']['url'] ?? ''), 'Administrators should receive the source manage action for linked orders.');
    wp_set_current_user(0);
    $assert(empty($main->my_account_order_actions([], $order)['mds3_manage']), 'Unrelated logged-out visitors should not receive the source manage action.');
    wp_set_current_user(1);

    $status_events = [];
    $main->mark_paid($order_id);
    $last_event = end($status_events);
    $assert('paid' === (string) ($last_event['status'] ?? '') && $order_id === absint($last_event['provider_order_id'] ?? 0), 'Paid order callbacks should mark the linked extension source paid.');

    $order->set_status('failed');
    $order->save();
    $main->mark_cancelled($order_id);
    $failed_events = array_values(array_filter($status_events, static function ($event) {
        return 'failed' === (string) ($event['status'] ?? '');
    }));
    $assert(!empty($failed_events), 'Failed WooCommerce orders should propagate a failed source status.');

    $assert($main->complete_source_order(['commerce_order_id' => $order_id]), 'Core completion should be able to complete the linked WooCommerce order.');
    $order = wc_get_order($order_id);
    $assert($order && $order->has_status('completed'), 'Linked WooCommerce order should be completed.');

    $status_events = [];
    $paid_retry = $main->create_checkout(array_merge($transaction, ['existing_provider_order_id' => $order_id]));
    $last_event = end($status_events);
    $assert('' === (string) ($paid_retry['checkout_url'] ?? '') && 'paid' === (string) ($last_event['status'] ?? ''), 'Retrying an already-paid extension order should re-synchronize its source as paid.');

    $account_url = $main->account_url(home_url('/fallback-account/'), ['account-page' => '']);
    $assert($account_url === wc_get_page_permalink('myaccount'), 'WooCommerce should provide the default account URL when core has no explicit account page.');
    $assert(home_url('/explicit-account/') === $main->account_url(home_url('/explicit-account/'), ['account-page' => home_url('/explicit-account/')]), 'An explicit Million Dollar Script account page should retain priority.');
    $assert(wc_get_checkout_url() === $main->checkout_landing_url(home_url('/fallback-checkout/'), ['payment_provider' => 'woocommerce'], home_url('/')), 'WooCommerce should provide the checkout landing URL when selected.');

    $cards = Admin::dashboard_card([]);
    $assert(!empty($cards['mds-woocommerce']['actions']) && false !== strpos((string) ($cards['mds-woocommerce']['actions'][0]['url'] ?? ''), 'page=mds3-woocommerce'), 'WooCommerce dashboard card should link to its extension-owned settings page.');
} finally {
    remove_action('million-dollar-script/payment/source/status', $status_listener, 50);
    update_option('mds3_settings', $original_settings, false);
    wp_set_current_user(1);
    foreach (array_reverse(array_unique(array_filter(array_map('absint', $created_order_ids)))) as $order_id) {
        $order = wc_get_order($order_id);
        if ($order && method_exists($order, 'delete')) {
            $order->delete(true);
        }
    }
}

if ($failures) {
    fwrite(STDERR, "WooCommerce Checkout fixture failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Success: WooCommerce Checkout fixture passed.\n";
