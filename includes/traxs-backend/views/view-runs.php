<?php
/*
 * File: includes/views/view-runs.php
 * Description: Lists current vendor runs (draft + published) with line items.
 */
if (!defined('ABSPATH')) exit;

$nonce = wp_create_nonce('eject_admin');

$runs = get_posts([
  'post_type'   => 'eject_run',
  'post_status' => ['draft','publish'],
  'numberposts' => -1,
  'orderby'     => 'date',
  'order'       => 'DESC',
]);
?>
<div class="wrap eject-wrap">
  <h1>Traxs: Vendor Runs <span class="eject-ver">v<?php echo esc_html(defined('EJECT_VER') ? EJECT_VER : '1.0.0'); ?></span></h1>

  <?php if (!$runs): ?>
    <p class="notice notice-info" style="padding:12px;margin-top:10px;">No vendor runs yet</p>
    <?php return; endif; ?>

  <?php foreach ($runs as $p): ?>
    <?php
      $vendor   = get_post_meta($p->ID, '_vendor_name', true) ?: '(Unknown Vendor)';
      $po_no    = get_post_meta($p->ID, '_po_number', true);
      $po_date  = get_post_meta($p->ID, '_po_date', true);
      $itemsRaw = get_post_meta($p->ID, '_items', true);
      $itemsArr = $itemsRaw ? json_decode($itemsRaw, true) : [];
      $rows = [];

      foreach ((array)$itemsArr as $key => $rec) {
        if (is_array($rec)) {
          $rows[] = [
            'item'  => (string)($rec['item']  ?? ''),
            'color' => (string)($rec['color'] ?? ''),
            'size'  => (string)($rec['size']  ?? ''),
            'qty'   => (int)   ($rec['qty']   ?? 0),
          ];
        } else {
          $parts = is_string($key) ? explode('|', $key) : [];
          $rows[] = ['item'=>$parts[0] ?? '', 'color'=>$parts[1] ?? '', 'size'=>$parts[2] ?? '', 'qty'=>0];
        }
      }
    ?>
    <div class="eject-vendor-card" data-po-id="<?php echo esc_attr($p->ID); ?>" data-vendor="<?php echo esc_attr($vendor); ?>" style="border:1px solid #ccd0d4;border-radius:8px;padding:12px;margin:12px 0;background:#fff;">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <h2 style="margin:0;">
          <?php echo esc_html($vendor); ?>
          <small style="font-weight:normal;color:#666;">
            <?php echo ($p->post_status === 'publish') ? 'Ordered' : 'Run (Open)'; ?>
            <?php if (!empty($po_no))  echo ' — PO#: '.esc_html($po_no); ?>
            <?php if (!empty($po_date)) echo ' — '.esc_html($po_date); ?>
          </small>
        </h2>
        <div>
          <?php if ($p->post_status === 'draft'): ?>
            <button class="button button-primary eject-mark-ordered" data-nonce="<?php echo esc_attr($nonce); ?>">
              Mark Ordered <span class="spinner"></span>
            </button>
          <?php else: ?>
            <button class="button eject-mark-not-ordered" data-nonce="<?php echo esc_attr($nonce); ?>">
              Set Not Ordered <span class="spinner"></span>
            </button>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!$rows): ?>
        <p style="margin-top:10px;color:#666;">No lines in this run yet.</p>
      <?php else: ?>
        <table class="widefat striped" style="margin-top:10px;">
          <thead><tr><th style="width:30%;">Item</th><th>Color</th><th>Size</th><th style="width:80px;text-align:right;">Qty</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo esc_html($r['item']); ?></td>
                <td><?php echo esc_html($r['color']); ?></td>
                <td><?php echo esc_html($r['size']); ?></td>
                <td style="text-align:right;"><?php echo (int)$r['qty']; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
