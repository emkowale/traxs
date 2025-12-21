<?php
/*
 * File: includes/ajax/traits/trait-eject-ajax-run-add.php
 * Description: Adds queue items to a vendor's single open run.
 * Plugin: Eject
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-05 EDT
 */
if (!defined('ABSPATH')) exit;

trait Eject_Ajax_Run_Add {

    /** Add one or many queue items to a run (creates run if missing) */
    public static function add_to_run() {
        Eject_Ajax::guard();

        $payload = !empty($_POST['bulk']) && !empty($_POST['items'])
            ? (array) json_decode(stripslashes($_POST['items']), true)
            : [[
                'order_id'      => intval($_POST['order_id'] ?? 0),
                'order_item_id' => intval($_POST['item_id'] ?? 0),
                'vendor'        => sanitize_text_field($_POST['vendor'] ?? ''),
                'item'          => sanitize_text_field($_POST['item'] ?? ''),
                'color'         => sanitize_text_field($_POST['color'] ?? 'N/A'),
                'size'          => sanitize_text_field($_POST['size']  ?? 'N/A'),
                'qty'           => intval($_POST['qty'] ?? 0),
            ]];

        $black = Eject_Ajax::get_blacklist();
        $count = 0;

        foreach ($payload as $p) {
            $vendor = isset($p['vendor']) ? (string)$p['vendor'] : '';
            if (!$vendor || empty($p['order_id']) || empty($p['order_item_id'])) continue;
            if (in_array(strtolower($vendor), $black, true)) continue;

            // Always use the single open run for this vendor
            $run_id = Eject_Data::get_or_create_run($vendor);
            Eject_Data::add_line($run_id, $p);
            $count++;

            // Suppress line in Queue
            $order = wc_get_order((int)$p['order_id']);
            if ($order) {
                $item = $order->get_item((int)$p['order_item_id']);
                if ($item) { $item->update_meta_data('eject_in_run', 1); $item->save(); }
            }
        }
        wp_send_json_success(['message' => "Added {$count} line(s) to runs"]);
    }
}
