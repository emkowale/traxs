<?php
/*
 * File: includes/views/view-pos.php
 * Description: Paginated list of published POs with inline expandable details and Delete/Revert.
 */
if (!defined('ABSPATH')) exit;

$focusId = isset($_GET['new_po']) ? intval($_GET['new_po']) : 0;
$paged   = max(1, intval($_GET['paged'] ?? 1));
$ppp     = 20;
$nonce   = wp_create_nonce('eject_admin');

$q = new WP_Query([
  'post_type'      => 'eject_run',
  'post_status'    => 'publish',
  'orderby'        => 'date',
  'order'          => 'DESC',
  'posts_per_page' => $ppp,
  'paged'          => $paged,
]);
?>
<div class="wrap eject-wrap">
  <h1>Traxs: Purchase Orders <span class="eject-ver">v<?php echo esc_html(defined('EJECT_VER') ? EJECT_VER : '1.0.0'); ?></span></h1>

  <?php if (!$q->have_posts()): ?>
    <div style="background:#fff;border:1px solid #e5e5e5;border-radius:4px;padding:12px;margin-top:10px;">
      No POs yet.
    </div>
    <?php return; endif; ?>

  <style>
    .eject-po-table .po-focus { background:#fff8e1; }
    .eject-po-table tr.is-clickable { cursor:pointer; }
    .eject-po-details td { background:#fafafa; }
    .eject-mini { white-space:nowrap; }
    .eject-mini .spinner { float:none; margin-left:6px; vertical-align:middle; }
  </style>

  <table class="widefat fixed striped eject-po-table">
    <thead>
      <tr>
        <th style="width:20%;">PO #</th>
        <th style="width:22%;">Vendor</th>
        <th style="width:14%;">Date</th>
        <th style="width:10%; text-align:right;">Total Qty</th>
        <th>Notes</th>
        <th style="width:14%;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php while ($q->have_posts()): $q->the_post(); $pid = get_the_ID(); ?>
        <?php
          $vendor   = get_post_meta($pid, '_vendor_name', true) ?: '(Unknown Vendor)';
          $po_no    = get_post_meta($pid, '_po_number', true) ?: '(pending)';
          $po_date  = get_post_meta($pid, '_po_date', true) ?: get_the_date('Y-m-d', $pid);
          $itemsArr = json_decode(get_post_meta($pid, '_items', true) ?: '[]', true);

          $totalQty = 0; $rows = [];
          if (is_array($itemsArr)) {
            foreach ($itemsArr as $rec) {
              $rows[] = [
                'item'  => (string)($rec['item']  ?? ''),
                'color' => (string)($rec['color'] ?? ''),
                'size'  => (string)($rec['size']  ?? ''),
                'qty'   => (int)   ($rec['qty']   ?? 0),
              ];
              $totalQty += (int)($rec['qty'] ?? 0);
            }
          }
          $trClass = 'is-clickable' . ( ($focusId && $focusId === (int)$pid) ? ' po-focus' : '' );
        ?>
        <tr class="<?php echo esc_attr($trClass); ?>" data-po="<?php echo esc_attr($pid); ?>" id="po-<?php echo esc_attr($pid); ?>">
          <td><strong><?php echo esc_html($po_no); ?></strong></td>
          <td><?php echo esc_html($vendor); ?></td>
          <td><?php echo esc_html($po_date); ?></td>
          <td style="text-align:right;"><?php echo (int)$totalQty; ?></td>
          <td>Click to view items</td>
          <td class="eject-mini">
            <button class="button button-secondary eject-po-delete" data-nonce="<?php echo esc_attr($nonce); ?>" data-po="<?php echo esc_attr($pid); ?>">
              Delete / Revert <span class="spinner"></span>
            </button>
          </td>
        </tr>
        <tr class="eject-po-details" data-detail-for="<?php echo esc_attr($pid); ?>" style="display:none;">
          <td colspan="6">
            <?php if (!$rows): ?>
              <em>No line items recorded for this PO.</em>
            <?php else: ?>
              <table class="widefat striped" style="margin:8px 0;">
                <thead>
                  <tr><th style="width:30%;">Item</th><th>Color</th><th>Size</th><th style="width:80px;text-align:right;">Qty</th></tr>
                </thead>
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
          </td>
        </tr>
      <?php endwhile; wp_reset_postdata(); ?>
    </tbody>
  </table>

  <div class="tablenav">
    <div class="tablenav-pages">
      <?php
      echo paginate_links([
        'base'      => add_query_arg('paged','%#%'),
        'format'    => '',
        'current'   => $paged,
        'total'     => max(1, (int)$q->max_num_pages),
        'prev_text' => '&laquo;',
        'next_text' => '&raquo;',
      ]);
      ?>
    </div>
  </div>
</div>

<script>
(function($){
  $(document).on('click','.eject-po-table tr.is-clickable', function(e){
    if ($(e.target).closest('.eject-po-delete').length) return;
    var id = $(this).data('po');
    var $detail = $('.eject-po-details[data-detail-for="'+id+'"]');
    $detail.toggle();
  });

  $(document).on('click','.eject-po-delete', function(e){
    e.preventDefault();
    var $b = $(this);
    var id = $b.data('po');
    var data = { action:'eject_delete_or_revert_po', po_id:id, _wpnonce:$b.data('nonce') };
    var $sp = $b.find('.spinner'); $b.prop('disabled',true).addClass('is-busy'); $sp.addClass('is-active');

    $.post(ajaxurl, data).done(function(resp){
      if(!(resp && resp.success && resp.data)) { alert('Action failed.'); return; }
      if(resp.data.status === 'deleted'){
        $('tr#po-'+id).next('.eject-po-details').remove();
        $('tr#po-'+id).remove();
      } else if (resp.data.status === 'reverted'){
        window.location = 'admin.php?page=eject-runs';
      }
    }).fail(function(){ alert('Action failed.'); })
    .always(function(){ $b.prop('disabled',false).removeClass('is-busy'); $sp.removeClass('is-active'); });
  });
})(jQuery);
</script>
