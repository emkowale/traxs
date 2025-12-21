<?php
/*
 * File: includes/ajax/class-eject-ajax-settings.php
 * Description: Settings + maintenance (export, clear runs/exceptions, unsuppress + repair flags).
 */
if (!defined('ABSPATH')) exit;

class Eject_Ajax_Settings {

    public static function save_settings() {
        Eject_Ajax::guard();
        $opts = get_option('eject_options', []);
        $black = sanitize_text_field($_POST['blacklist'] ?? '');
        $prefix = sanitize_text_field($_POST['prefix'] ?? 'BT');

        $norm_black = array_filter(array_map('trim', explode(',', strtolower($black))));
        $opts['blacklist'] = implode(',', $norm_black);
        $opts['prefix'] = $prefix ?: 'BT';

        update_option('eject_options', $opts);
        wp_send_json_success(['message' => 'Settings saved']);
    }

    /** Clear OPEN runs: unsuppress their items (return to Queue), then delete the runs. */
    public static function clear_runs() {
        Eject_Ajax::guard();

        $runs = get_posts([
            'post_type'   => 'eject_run',
            'post_status' => 'draft',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);

        if (!$runs) wp_send_json_success(['message' => 'No open runs to clear']);

        $cleared = 0;
        foreach ($runs as $po_id) {
            $items = json_decode(get_post_meta($po_id, '_items', true) ?: '[]', true);
            if (is_array($items)) {
                foreach ($items as $line) {
                    foreach ((array)($line['order_item_ids'] ?? []) as $iid) {
                        if ($iid) { wc_delete_order_item_meta((int)$iid, 'eject_in_run'); $cleared++; }
                    }
                }
            }
            wp_delete_post($po_id, true);
        }

        wp_send_json_success(['message' => "Open runs cleared ({$cleared} line(s) unsuppressed)"]);
    }

    public static function clear_exceptions() {
        Eject_Ajax::guard();
        $runs = get_posts([
            'post_type'   => 'eject_run',
            'post_status' => ['draft','publish'],
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        foreach ($runs as $po_id) delete_post_meta($po_id, '_exceptions');
        wp_send_json_success(['message' => 'Exceptions cleared']);
    }

    public static function export_pos() {
        Eject_Ajax::guard();

        $posts = get_posts([
            'post_type'   => 'eject_run',
            'post_status' => ['draft','publish'],
            'numberposts' => -1,
        ]);

        $out = [];
        foreach ($posts as $p) {
            $out[] = [
                'id'        => $p->ID,
                'status'    => $p->post_status,
                'vendor'    => get_post_meta($p->ID, '_vendor_name', true),
                'po_number' => get_post_meta($p->ID, '_po_number', true),
                'po_date'   => get_post_meta($p->ID, '_po_date', true),
                'reopened'  => get_post_meta($p->ID, '_reopened', true),
                'items'     => json_decode(get_post_meta($p->ID, '_items', true) ?: '[]', true),
                'exceptions'=> json_decode(get_post_meta($p->ID, '_exceptions', true) ?: '[]', true),
            ];
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="eject-pos.json"');
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Repair Flags: Make Queue suppression match reality.
     * - Build the set of ALL order_item_ids that are present inside OPEN runs (draft).
     * - For every item in current wc-processing orders:
     *      if item_id in set  -> set eject_in_run=1
     *      else               -> clear eject_in_run
     */
    public static function repair_flags() {
        Eject_Ajax::guard();

        // 1) Gather item ids referenced by draft runs
        $runs = get_posts([
            'post_type'   => 'eject_run',
            'post_status' => 'draft',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        $in_runs = [];
        foreach ($runs as $pid) {
            $items = json_decode(get_post_meta($pid, '_items', true) ?: '[]', true);
            if (!is_array($items)) continue;
            foreach ($items as $rec) {
                foreach ((array)($rec['order_item_ids'] ?? []) as $iid) {
                    if ($iid) $in_runs[(int)$iid] = true;
                }
            }
        }

        // 2) Sync flags across all processing orders
        $q = new WC_Order_Query(['status'=>'processing','limit'=>-1,'return'=>'objects']);
        $set=0; $cleared=0;
        foreach ($q->get_orders() as $order) {
            foreach ($order->get_items() as $iid => $item) {
                if (isset($in_runs[(int)$iid])) {
                    if (!$item->get_meta('eject_in_run')) { $item->update_meta_data('eject_in_run',1); $item->save(); $set++; }
                } else {
                    if ($item->get_meta('eject_in_run')) { wc_delete_order_item_meta((int)$iid,'eject_in_run'); $cleared++; }
                }
            }
        }
        wp_send_json_success(['message'=>"Repaired flags (set {$set}, cleared {$cleared})"]);
    }

    /** Legacy: unsuppress everything in processing orders. */
    public static function unsuppress_queue() {
        Eject_Ajax::guard();
        $q = new WC_Order_Query(['status'=>'processing','limit'=>-1,'return'=>'objects']);
        $cleared = 0;
        foreach ($q->get_orders() as $order) {
            foreach ($order->get_items() as $item_id => $item) {
                if ($item->get_meta('eject_in_run')) { wc_delete_order_item_meta($item_id, 'eject_in_run'); $cleared++; }
            }
        }
        wp_send_json_success(['message' => "Unsuppressed {$cleared} item(s)"]);
    }
}
