<?php
/*
 * File: includes/views/view-receive-goods.php
 * Description: Admin view for "Receive Goods" that lists only finalized POs from Eject_Bridge.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-05 EDT
 */

if (!defined('ABSPATH')) exit;

use Traxs\Eject_Bridge;

if (!current_user_can('manage_woocommerce')) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'traxs'));
}

// Source data exclusively from the bridge so UI == API behavior.
$pos = is_callable([Eject_Bridge::class, 'get_open_pos'])
    ? Eject_Bridge::get_open_pos()
    : [];

?>
<div class="wrap traxs-receive-goods">
    <h1 class="wp-heading-inline"><?php esc_html_e('Receive Goods', 'traxs'); ?></h1>
    <hr class="wp-header-end" />

    <?php if (empty($pos)) : ?>
        <div class="notice notice-info">
            <p><?php esc_html_e('No purchase orders found yet. This view lists only finalized POs with BT numbers.', 'traxs'); ?></p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('PO #', 'traxs'); ?></th>
                    <th scope="col"><?php esc_html_e('Vendor', 'traxs'); ?></th>
                    <th scope="col"><?php esc_html_e('Created', 'traxs'); ?></th>
                    <th scope="col"><?php esc_html_e('Items', 'traxs'); ?></th>
                    <th scope="col"><?php esc_html_e('Actions', 'traxs'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pos as $po) :
                    $po_post_id  = (int)($po['po_post_id'] ?? $po['po_id'] ?? 0);
                    $po_number   = (string)($po['po_number'] ?? '');
                    $vendor      = (string)($po['vendor'] ?? '');
                    $created     = (string)($po['created'] ?? '');
                    $items_count = (int)($po['items_count'] ?? 0);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($po_number ?: '—'); ?></strong></td>
                        <td><?php echo esc_html($vendor ?: '—'); ?></td>
                        <td><?php echo esc_html($created ? mysql2date(get_option('date_format'), $created) : '—'); ?></td>
                        <td><?php echo esc_html($items_count); ?></td>
                        <td>
                            <?php if ($po_post_id) : ?>
                                <a class="button button-small" href="<?php echo esc_url(get_edit_post_link($po_post_id)); ?>">
                                    <?php esc_html_e('View', 'traxs'); ?>
                                </a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
