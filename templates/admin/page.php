<?php
/**
 * WooCommerce Checkout admin page.
 *
 * @package MillionDollarScript\Extensions\WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap mds3-admin mds-woocommerce-admin">
    <header class="mds-woocommerce-header">
        <div>
            <h1><?php esc_html_e('WooCommerce Checkout', 'mds-woocommerce'); ?></h1>
            <p><?php esc_html_e('Connect Million Dollar Script purchases to WooCommerce checkout, payment gateways, taxes, currencies, and customer orders.', 'mds-woocommerce'); ?></p>
        </div>
        <div class="mds-woocommerce-header-actions">
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wc-admin')); ?>"><?php esc_html_e('Open WooCommerce', 'mds-woocommerce'); ?></a>
            <?php echo wp_kses_post(\MillionDollarScript\Extensions\Admin::docs_button('mds-woocommerce')); ?>
        </div>
    </header>
    <hr class="wp-header-end">

    <?php if ('saved' === $notice) : ?>
        <div class="notice notice-success inline is-dismissible"><p><?php esc_html_e('WooCommerce Checkout settings saved.', 'mds-woocommerce'); ?></p></div>
    <?php endif; ?>

    <section class="mds-woocommerce-status" aria-labelledby="mds-woocommerce-status-title">
        <div class="mds-woocommerce-section-heading">
            <div>
                <h2 id="mds-woocommerce-status-title"><?php esc_html_e('Checkout status', 'mds-woocommerce'); ?></h2>
                <p><?php esc_html_e('Resolve any item marked Needs attention before accepting live orders.', 'mds-woocommerce'); ?></p>
            </div>
        </div>
        <div class="mds-woocommerce-status-grid">
            <article>
                <span class="mds-woocommerce-status-label"><?php esc_html_e('Payment provider', 'mds-woocommerce'); ?></span>
                <strong><?php echo 'woocommerce' === $active_provider ? esc_html__('Active', 'mds-woocommerce') : esc_html__('Not selected', 'mds-woocommerce'); ?></strong>
                <span class="mds-woocommerce-state <?php echo 'woocommerce' === $active_provider ? 'is-ready' : 'needs-attention'; ?>">
                    <?php echo 'woocommerce' === $active_provider ? esc_html__('Ready', 'mds-woocommerce') : esc_html__('Needs attention', 'mds-woocommerce'); ?>
                </span>
            </article>
            <article>
                <span class="mds-woocommerce-status-label"><?php esc_html_e('Store currency', 'mds-woocommerce'); ?></span>
                <strong><?php echo esc_html($currency_code . ' (' . $currency_symbol . ')'); ?></strong>
                <span class="mds-woocommerce-state is-ready"><?php esc_html_e('Controlled by WooCommerce', 'mds-woocommerce'); ?></span>
            </article>
            <article>
                <span class="mds-woocommerce-status-label"><?php esc_html_e('Payment methods', 'mds-woocommerce'); ?></span>
                <strong><?php echo esc_html((string) count($enabled_gateways)); ?></strong>
                <span class="mds-woocommerce-state <?php echo $enabled_gateways ? 'is-ready' : 'needs-attention'; ?>">
                    <?php echo $enabled_gateways ? esc_html__('Enabled', 'mds-woocommerce') : esc_html__('Needs attention', 'mds-woocommerce'); ?>
                </span>
            </article>
            <article>
                <span class="mds-woocommerce-status-label"><?php esc_html_e('Secure checkout', 'mds-woocommerce'); ?></span>
                <strong><?php echo $secure_checkout ? esc_html__('Available', 'mds-woocommerce') : esc_html__('HTTPS required', 'mds-woocommerce'); ?></strong>
                <span class="mds-woocommerce-state <?php echo $secure_checkout ? 'is-ready' : 'needs-attention'; ?>">
                    <?php echo $secure_checkout ? esc_html__('Ready', 'mds-woocommerce') : esc_html__('Needs attention', 'mds-woocommerce'); ?>
                </span>
            </article>
        </div>
    </section>

    <section class="mds-woocommerce-readiness" aria-labelledby="mds-woocommerce-readiness-title">
        <div class="mds-woocommerce-section-heading">
            <div>
                <h2 id="mds-woocommerce-readiness-title"><?php esc_html_e('Store readiness', 'mds-woocommerce'); ?></h2>
                <p><?php esc_html_e('Million Dollar Script uses your current WooCommerce pages and enabled gateways.', 'mds-woocommerce'); ?></p>
            </div>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wc-settings')); ?>"><?php esc_html_e('WooCommerce settings', 'mds-woocommerce'); ?></a>
        </div>
        <div class="mds-woocommerce-check-list">
            <?php foreach ($pages as $page) : ?>
                <div class="mds-woocommerce-check-row">
                    <span class="dashicons <?php echo !empty($page['published']) ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
                    <div>
                        <strong><?php echo esc_html((string) $page['label']); ?></strong>
                        <span><?php echo !empty($page['published']) ? esc_html__('Published', 'mds-woocommerce') : esc_html__('Missing or unpublished', 'mds-woocommerce'); ?></span>
                    </div>
                    <?php if (!empty($page['url'])) : ?>
                        <a href="<?php echo esc_url((string) $page['url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View', 'mds-woocommerce'); ?><span class="screen-reader-text"> <?php echo esc_html((string) $page['label']); ?></span></a>
                    <?php else : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=advanced')); ?>"><?php esc_html_e('Configure', 'mds-woocommerce'); ?></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="mds-woocommerce-check-row">
                <span class="dashicons <?php echo $enabled_gateways ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
                <div>
                    <strong><?php esc_html_e('Enabled payment methods', 'mds-woocommerce'); ?></strong>
                    <span><?php echo $enabled_gateways ? esc_html(implode(', ', $enabled_gateways)) : esc_html__('No payment methods are enabled.', 'mds-woocommerce'); ?></span>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')); ?>"><?php esc_html_e('Manage', 'mds-woocommerce'); ?></a>
            </div>
        </div>
    </section>

    <section class="mds-woocommerce-settings" aria-labelledby="mds-woocommerce-settings-title">
        <div class="mds-woocommerce-section-heading">
            <div>
                <h2 id="mds-woocommerce-settings-title"><?php esc_html_e('Million Dollar Script checkout', 'mds-woocommerce'); ?></h2>
                <p><?php esc_html_e('Choose whether new purchases use WooCommerce and optionally set a same-site destination after customer login.', 'mds-woocommerce'); ?></p>
            </div>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="mds_woocommerce_save_settings" />
            <?php wp_nonce_field('mds_woocommerce_save_settings'); ?>
            <fieldset class="mds-woocommerce-toggle-field">
                <legend><?php esc_html_e('Payment routing', 'mds-woocommerce'); ?></legend>
                <label>
                    <input type="checkbox" name="use_woocommerce" value="1" <?php checked('woocommerce', $active_provider); ?> />
                    <span>
                        <strong><?php esc_html_e('Use WooCommerce for new Million Dollar Script checkouts', 'mds-woocommerce'); ?></strong>
                        <small><?php esc_html_e('Orders remain in Million Dollar Script while payment, tax, currency, and customer checkout are handled by WooCommerce.', 'mds-woocommerce'); ?></small>
                    </span>
                </label>
            </fieldset>
            <div class="mds-woocommerce-field">
                <label for="mds-woocommerce-login-redirect"><?php esc_html_e('After-login destination', 'mds-woocommerce'); ?></label>
                <input id="mds-woocommerce-login-redirect" class="regular-text" type="url" name="woocommerce_login_redirect" value="<?php echo esc_attr((string) ($settings['woocommerce-login-redirect'] ?? '')); ?>" placeholder="<?php echo esc_attr(wc_get_page_permalink('myaccount')); ?>" />
                <p class="description"><?php esc_html_e('Optional same-site URL for customers after WooCommerce login. Leave blank to keep the normal WooCommerce destination.', 'mds-woocommerce'); ?></p>
            </div>
            <?php submit_button(__('Save checkout settings', 'mds-woocommerce')); ?>
        </form>
    </section>
</div>
