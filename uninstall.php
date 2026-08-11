<?php
/** WooCommerce Checkout uninstall cleanup. WooCommerce orders are intentionally retained. */
if (!defined('WP_UNINSTALL_PLUGIN') || !class_exists('\\MillionDollarScript\\Extensions\\CleanupPolicy')) { exit; }
\MillionDollarScript\Extensions\CleanupPolicy::cleanup('mds-woocommerce', [
    'option_prefixes' => ['mds_woocommerce_'],
    'transient_prefixes' => ['mds_woocommerce_'],
]);
