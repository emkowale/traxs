<?php
/*
 * File: includes/class-traxs-receive-goods.php
 * Description: Registers the "Receive Goods" admin page, renders the PO-only view,
 *              and (lightly) wires it to the REST endpoint /traxs/v1/pos so UI and SPA stay in sync.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-05 EDT
 */

if (!defined('ABSPATH')) exit;

class Traxs_Receive_Goods_Page {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 20);
        add_action('admin_enqueue_scripts', [__CLASS__, 'maybe_enqueue'], 10);
    }

    public static function register_menu() {
        $parent_slug = 'traxs'; // Adjust if your Traxs top-level slug differs.
        add_submenu_page(
            $parent_slug,
            __('Receive Goods', 'traxs'),
            __('Receive Goods', 'traxs'),
            'manage_woocommerce',
            'traxs-receive-goods',
            [__CLASS__, 'render_page']
        );
    }

    /** Enqueue a tiny inline JS on this page to pull /traxs/v1/pos (keeps UI == REST) */
    public static function maybe_enqueue($hook) {
        // Only load on our page.
        if (isset($_GET['page']) && $_GET['page'] === 'traxs-receive-goods') {
            // No external file yet: minimal inline to avoid extra files this step.
            add_action('admin_print_footer_scripts', function () {
                $endpoint = esc_url_raw( rest_url('traxs/v1/pos') );
                $nonce    = wp_create_nonce('wp_rest');
                ?>
<script>
(() => {
  const endpoint = "<?php echo $endpoint; ?>";
  const nonce = "<?php echo esc_js($nonce); ?>";
  fetch(endpoint, { headers: { 'X-WP-Nonce': nonce }})
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(data => {
      // Expose for quick checks; later we can drive the DOM from here.
      window.TraxsPOs = Array.isArray(data) ? data : [];
      console.info('[Traxs] /traxs/v1/pos →', window.TraxsPOs);
      // If the PHP-rendered table shows rows but REST is empty (or vice versa), flag it.
      const phpHasRows = !!document.querySelector('.traxs-receive-goods table tbody tr');
      const restHasRows = window.TraxsPOs.length > 0;
      if (phpHasRows !== restHasRows) {
        const wrap = document.querySelector('.traxs-receive-goods .wp-header-end')?.parentElement || document.body;
        const div = document.createElement('div');
        div.className = 'notice notice-warning';
        div.innerHTML = '<p><strong>Heads up:</strong> UI/REST mismatch detected. This page is now wired to /traxs/v1/pos; next step will unify rendering from REST.</p>';
        wrap.insertBefore(div, wrap.children[2] || null);
      }
    })
    .catch(err => console.warn('[Traxs] REST fetch failed:', err));
})();
</script>
                <?php
            });
        }
    }

    public static function render_page() {
        $view = __DIR__ . '/views/view-receive-goods.php';
        if (file_exists($view)) {
            require $view;
        } else {
            echo '<div class="wrap"><h1>' . esc_html__('Receive Goods', 'traxs') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('View file not found: includes/views/view-receive-goods.php', 'traxs') . '</p></div></div>';
        }
    }
}

Traxs_Receive_Goods_Page::init();
