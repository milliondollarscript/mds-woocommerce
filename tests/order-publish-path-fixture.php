<?php
/**
 * The paid path: what a WooCommerce order owes the Million Dollar Script order it came from.
 *
 * Reading an MDS order is only half of this integration. When the store says the money
 * arrived, the grid has to change: the order becomes paid, its pixel stops being for
 * sale, and the ad its customer saved goes up. The other half is that those three
 * things are not one decision. Core sells the pixel and settles the order; whether the
 * finished ad may appear yet is asked of any integration with a reason to hold it. This
 * fixture runs the whole path in both directions so the two can never be confused again:
 * a hold must not undo the sale, and a released ad must go up through the same door.
 *
 * Run with:
 *   ./scripts/wp-sandbox eval-file wp-content/plugins/mds-woocommerce/tests/order-publish-path-fixture.php
 */

use MillionDollarScript\V3\Blocks\BlockRepository;
use MillionDollarScript\V3\Grid\GridRepository;
use MillionDollarScript\V3\Media\PlacementRepository;
use MillionDollarScript\V3\Orders\OrderRepository;
use MillionDollarScript\V3\Orders\PublishGate;
use MillionDollarScript\V3\Support\DB;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\\MillionDollarScript\\Extensions\\WooCommerce\\Main')) {
    fwrite(STDERR, "MDS3-SKIP: WooCommerce Checkout is not active, and this fixture is about what it does when a store reports a payment.\n");
    return;
}
if (!function_exists('wc_create_order') || !function_exists('wc_get_order')) {
    fwrite(STDERR, "MDS3-SKIP: WooCommerce is not active, so there is no store to report a payment.\n");
    return;
}

$main = \MillionDollarScript\Extensions\WooCommerce\Main::instance();
$settings = get_option('mds3_settings', array());
if (!is_array($settings) || 'woocommerce' !== \MillionDollarScript\Commerce\Payments::active_provider_id($settings)) {
    fwrite(STDERR, "MDS3-SKIP: this site is not set to take payment through WooCommerce, and the path under test starts when WooCommerce reports a payment.\n");
    return;
}

$grid = (new GridRepository())->first_active();
if (!$grid) {
    fwrite(STDERR, "MDS3-SKIP: this fixture needs an active grid to put a paid ad onto.\n");
    return;
}

global $wpdb;
$blocks = new BlockRepository();
$geometry = $grid->geometry();
$uploads = wp_upload_bits('mds3-woo-order-path-fixture.png', '', 'PNG');
$attachment_id = (int) wp_insert_attachment(array(
    'post_mime_type' => 'image/png',
    'post_status' => 'inherit',
    'post_title' => 'MDS3 WooCommerce order path fixture',
), (string) ($uploads['file'] ?? ''));
if (!$attachment_id) {
    throw new RuntimeException('The fixture could not register its image.');
}

$cleanup = array('orders' => array(), 'placements' => array(), 'blocks' => array(), 'wc_orders' => array());

/**
 * An order a customer has filled in: pixel held, ad saved and waiting, nothing paid yet.
 * This is the state a WooCommerce cart is created from, and the state the store's
 * completion callback arrives against.
 */
$new_waiting_order = static function ($row, $col) use ($grid, $blocks, $attachment_id, &$cleanup, $wpdb) {
    $block = $blocks->materialize($grid, $row, $col);
    if (is_wp_error($block) || 'available' !== (string) ($block['status'] ?? '')) {
        return null;
    }
    $order_id = (new OrderRepository())->create(array(), array(
        'email' => 'woo-order-path@example.test',
        'status' => 'pending_payment',
        'total' => 25.0,
        'currency' => 'USD',
    ));
    if (is_wp_error($order_id) || !absint($order_id)) {
        return null;
    }
    $wpdb->update(DB::table('blocks'), array('status' => 'reserved', 'order_id' => absint($order_id)), array('id' => absint($block['id'])));
    $placement_id = (new PlacementRepository())->create(array(
        'attachment_id' => $attachment_id,
        'block_id' => (int) $block['id'],
        'grid_id' => $grid->id(),
        'order_id' => absint($order_id),
        'status' => 'pending',
        'width' => 1,
        'height' => 1,
        'x' => (int) $block['x'],
        'y' => (int) $block['y'],
    ));
    $cleanup['orders'][] = absint($order_id);
    $cleanup['placements'][] = absint($placement_id);
    $cleanup['blocks'][] = (int) $block['id'];

    return array('order_id' => absint($order_id), 'placement_id' => absint($placement_id), 'block_id' => (int) $block['id']);
};

$paid_store_order = static function (array $mds) {
    $wc_order = wc_create_order(array('customer_id' => 0));
    if (!$wc_order) {
        return 0;
    }
    $wc_order->set_total(25.0);
    $wc_order->update_meta_data('_mds3_payment_source', 'mds-grid');
    $wc_order->update_meta_data('_mds3_payment_source_id', (int) $mds['order_id']);
    $wc_order->update_meta_data('_mds3_order_id', (int) $mds['order_id']);
    $wc_order->save();
    // What a real checkout does: the store's own status change, not a call into the
    // integration. The integration is listening for the store, exactly as it does live.
    $wc_order->update_status('completed', 'fixture: the store says the money arrived');

    return absint($wc_order->get_id());
};

$state = static function (array $mds) use ($wpdb) {
    return array(
        'order' => (string) $wpdb->get_var('SELECT status FROM ' . DB::table('orders') . ' WHERE id = ' . absint($mds['order_id'])),
        'block' => (string) $wpdb->get_var('SELECT status FROM ' . DB::table('blocks') . ' WHERE id = ' . absint($mds['block_id'])),
        'placement' => (string) $wpdb->get_var('SELECT status FROM ' . DB::table('placements') . ' WHERE id = ' . absint($mds['placement_id'])),
    );
};

try {
    // 1. Nothing holding the ad: a completed store order settles the order, sells the
    // pixel, and puts the ad up.
    $case_one = $new_waiting_order(max(0, $geometry->rows() - 1), max(0, $geometry->columns() - 1));
    if (null === $case_one) {
        fwrite(STDERR, "MDS3-SKIP: no free block at the corner of grid {$grid->id()} to run the paid path against.\n");
        return;
    }
    $case_one['wc_order'] = $paid_store_order($case_one);
    if (!$case_one['wc_order']) {
        throw new RuntimeException('The fixture could not create a WooCommerce order to pay.');
    }
    $cleanup['wc_orders'][] = $case_one['wc_order'];

    $after = $state($case_one);
    if ('paid' !== $after['order']) {
        throw new RuntimeException('A completed store order left the MDS order in ' . $after['order'] . '.');
    }
    if ('sold' !== $after['block']) {
        throw new RuntimeException('A paid order left its pixel marked ' . $after['block'] . '.');
    }
    if ('active' !== $after['placement']) {
        throw new RuntimeException('A paid order with nothing holding it did not put the ad on the grid: ' . $after['placement'] . '.');
    }

    // 2. The store can also be told from the other side: an order core marks paid
    // completes the store order it came from.
    $linked = \MillionDollarScript\Core\Orders::find($case_one['order_id']);
    if (!$main->complete_source_order(is_array($linked) ? $linked : array())) {
        throw new RuntimeException('An MDS order with a paid store order behind it could not complete that store order.');
    }
    $wc_after = wc_get_order($case_one['wc_order']);
    if (!$wc_after || 'completed' !== (string) $wc_after->get_status()) {
        throw new RuntimeException('Completing from the MDS side left the store order at ' . ($wc_after ? $wc_after->get_status() : 'nothing') . '.');
    }

    // 3. Something holding the ad: the money and the sale still happen. Only the ad waits.
    $case_two = $new_waiting_order(max(0, $geometry->rows() - 2), max(0, $geometry->columns() - 1));
    if (null === $case_two) {
        throw new RuntimeException('The fixture needs a second free block to run the held case.');
    }
    $hold = static function ($allowed, $order_id, $context) use ($case_two) {
        return absint($case_two['order_id']) === absint($order_id) ? false : $allowed;
    };
    add_filter(PublishGate::HOOK, $hold, 10, 3);
    $case_two['wc_order'] = $paid_store_order($case_two);
    if (!$case_two['wc_order']) {
        throw new RuntimeException('The fixture could not create a second WooCommerce order.');
    }
    $cleanup['wc_orders'][] = $case_two['wc_order'];

    $held = $state($case_two);
    if ('paid' !== $held['order']) {
        throw new RuntimeException('Holding an ad left its order ' . $held['order'] . ' - the money is not what the gate decides.');
    }
    if ('sold' !== $held['block']) {
        throw new RuntimeException('Holding an ad put the customer\'s pixel back in the shop window: ' . $held['block'] . '.');
    }
    if ('active' === $held['placement']) {
        throw new RuntimeException('An integration said not yet and the paid ad appeared anyway.');
    }

    // 4. The hold is not the end of the path: when its own moment comes, the ad goes
    // up through the same question, and the store side still works.
    remove_filter(PublishGate::HOOK, $hold, 10);
    if (!\MillionDollarScript\Core\Orders::publish_ad($case_two['order_id'], 'fixture_release')) {
        throw new RuntimeException('An integration that stopped holding could not release the ad.');
    }
    $released = $state($case_two);
    if ('active' !== $released['placement']) {
        throw new RuntimeException('A released ad is still not on the grid: ' . $released['placement'] . '.');
    }

    echo "mds-woocommerce order-publish-path fixture passed\n";
} finally {
    foreach ($cleanup['wc_orders'] as $wc_order_id) {
        $wc_order = wc_get_order($wc_order_id);
        if ($wc_order) {
            $wc_order->delete(true);
        }
    }
    foreach ($cleanup['placements'] as $placement_id) {
        $wpdb->delete(DB::table('placements'), array('id' => absint($placement_id)));
    }
    foreach ($cleanup['blocks'] as $block_id) {
        $wpdb->delete(DB::table('blocks'), array('id' => absint($block_id)));
    }
    (new OrderRepository())->delete_permanently($cleanup['orders']);
    wp_delete_post($attachment_id, true);
}
