<?php
/*
 * File: includes/views/view-settings.php
 * Description: Eject settings screen (blacklist, roles, counters, tools).
 */
if (!defined('ABSPATH')) exit;

$opts      = get_option('eject_options', []);
$blacklist = isset($opts['blacklist']) ? $opts['blacklist'] : 'C&C';
$prefix    = isset($opts['prefix']) ? $opts['prefix'] : 'BT';
$roles     = isset($opts['roles']) ? (array)$opts['roles'] : ['administrator','shop_manager'];
$reset     = !empty($opts['reset_daily']);
$nonce     = wp_create_nonce('eject_admin');
?>
<div class="wrap eject-wrap">
  <h1>Traxs: Settings <span class="eject-ver">v<?php echo esc_html(defined('EJECT_VER') ? EJECT_VER : '1.0.0'); ?></span></h1>

  <form id="eject-settings-form" method="post">
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><label for="eject-blacklist">Vendor Blacklist</label></th>
        <td><input name="blacklist" id="eject-blacklist" type="text" value="<?php echo esc_attr($blacklist); ?>" class="regular-text"></td>
      </tr>
      <tr>
        <th scope="row"><label for="eject-prefix">PO Numbering Prefix</label></th>
        <td><input name="prefix" id="eject-prefix" type="text" value="<?php echo esc_attr($prefix); ?>" class="regular-text"></td>
      </tr>
      <tr>
        <th scope="row">Roles Allowed to Mark Ordered</th>
        <td>
          <label><input type="checkbox" name="roles[]" value="administrator" <?php checked(in_array('administrator',$roles,true)); ?>> Administrator</label><br>
          <label><input type="checkbox" name="roles[]" value="shop_manager"  <?php checked(in_array('shop_manager',$roles,true)); ?>> Shop Manager</label>
        </td>
      </tr>
      <tr>
        <th scope="row">Reset PO Counters Daily</th>
        <td><label><input type="checkbox" name="reset_daily" value="1" <?php checked($reset); ?>> Enabled</label></td>
      </tr>
    </table>

    <p>
      <button type="button" id="eject-export-pos" class="button" data-nonce="<?php echo esc_attr($nonce); ?>">
        Export All POs → JSON <span class="spinner" style="float:none;margin-left:6px;"></span>
      </button>
    </p>

    <h2>Maintenance</h2>
    <p class="description">Revert/delete and repair actions live here.</p>
    <p>
      <button type="button" id="eject-clear-runs" class="button button-secondary" data-nonce="<?php echo esc_attr($nonce); ?>">
        Clear All Open Runs <span class="spinner"></span>
      </button>
      <button type="button" id="eject-clear-exc" class="button button-secondary" data-nonce="<?php echo esc_attr($nonce); ?>">
        Clear All Exceptions <span class="spinner"></span>
      </button>
      <button type="button" id="eject-unsuppress-queue" class="button button-secondary" data-nonce="<?php echo esc_attr($nonce); ?>">
        Unsuppress Queue <span class="spinner"></span>
      </button>
    </p>
  </form>

  <h2>Today’s PO Number Status</h2>
  <table class="widefat striped">
    <thead><tr><th>Vendor</th><th>Next #</th></tr></thead>
    <tbody><tr><td colspan="2">No counters yet today.</td></tr></tbody>
  </table>
</div>
