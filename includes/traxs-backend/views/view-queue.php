<?php
/*
 * File: includes/views/view-queue.php
 * Description: Queue screen — intake for new Processing orders.
 * Plugin: Eject
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-03 EDT
 */
if (!defined('ABSPATH')) exit;

$nonce = wp_create_nonce('eject_admin');
?>
<script>
  window.EJECT_QUEUE = window.EJECT_QUEUE || {};
  window.EJECT_QUEUE.nonce = '<?php echo esc_js($nonce); ?>';
  window.EJECT_QUEUE.ajax  = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
</script>

<div class="wrap eject-wrap">
  <h1>Traxs: Queue <span class="eject-ver">v<?php echo esc_html(defined('EJECT_VER') ? EJECT_VER : '1.0.0'); ?></span></h1>

  <table class="widefat fixed striped eject-table" id="eject-queue-table">
    <thead>
      <tr>
        <td style="width:24px;"><input type="checkbox" id="eject-q-all"></td>
        <th>Order #</th>
        <th>Customer</th>
        <th>Vendor Code</th>
        <th>Item</th>
        <th>Color</th>
        <th>Size</th>
        <th style="width:80px;">Qty</th>
        <th style="width:120px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <tr class="eject-empty">
        <td></td>
        <td colspan="8">✅ All current Processing orders have been assigned to vendor runs.</td>
      </tr>
    </tbody>
  </table>

  <div class="eject-bulk">
    <button id="eject-queue-add-selected" class="button" data-action="eject_add_to_run" data-nonce="<?php echo esc_attr($nonce); ?>">
      Add Selected to Runs <span class="spinner"></span>
    </button>
    <button id="eject-queue-dismiss-selected" class="button" data-action="eject_dismiss_bulk" data-nonce="<?php echo esc_attr($nonce); ?>">
      Dismiss Selected <span class="spinner"></span>
    </button>
    <button id="eject-queue-refresh" class="button" data-action="eject_scan_orders" data-nonce="<?php echo esc_attr($nonce); ?>">
      Refresh <span class="spinner"></span>
    </button>
  </div>
</div>
