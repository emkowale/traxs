<?php
/*
 * File: includes/ajax/class-eject-ajax-scan.php
 * Description: Queue scanner + dismiss endpoints.
 */
if (!defined('ABSPATH')) exit;

class Eject_Ajax_Scan {
    public static function scan_orders() {
        Eject_Ajax::guard();
        $black = Eject_Ajax::get_blacklist();

        $q = new WC_Order_Query([
            'status' => 'processing',
            'limit'  => 100,
            'return' => 'objects',
        ]);

        $out = [];
        foreach ($q->get_orders() as $order) {
            if ($order->get_meta('_eject_processed')) continue;

            foreach ($order->get_items() as $item_id => $item) {
                if ($item->get_meta('eject_excluded')) continue;  // dismissed
                if ($item->get_meta('eject_in_run')) continue;    // already in run

                $vc = $item->get_meta('Vendor Code');
                if (!$vc || !preg_match('/^([^(]+)\(([^)]+)\)/', $vc, $m)) continue;

                $vendor = strtolower(trim($m[1]));
                if (in_array($vendor, $black, true)) continue;

                $out[] = [
                    'order_id'    => $order->get_id(),
                    'item_id'     => $item_id,
                    'customer'    => $order->get_formatted_billing_full_name(),
                    'vendor'      => trim($m[1]),
                    'vendor_item' => trim($m[2]),
                    'item'        => trim($m[2]),
                    'color'       => (string)$item->get_meta('Color') ?: 'N/A',
                    'size'        => (string)$item->get_meta('Size')  ?: 'N/A',
                    'qty'         => (int)$item->get_quantity(),
                ];
            }
        }
        wp_send_json_success(['items' => $out]);
    }

    public static function dismiss_item() {
        Eject_Ajax::guard();
        $order = wc_get_order(intval($_POST['order_id'] ?? 0));
        $item  = $order ? $order->get_item(intval($_POST['item_id'] ?? 0)) : null;
        if (!$order || !$item) wp_send_json_error(['message'=>'Not found'],404);
        $item->update_meta_data('eject_excluded', 1); $item->save();
        wp_send_json_success(['message'=>'Dismissed']);
    }

    public static function dismiss_bulk() {
        Eject_Ajax::guard();
        $payload = (array) json_decode(stripslashes($_POST['items'] ?? '[]'), true);
        $by_order = [];
        foreach ($payload as $p) {
            $oid = (int)($p['order_id'] ?? 0);
            $iid = (int)($p['item_id'] ?? 0);
            if(!$oid || !$iid) continue; $by_order[$oid][] = $iid;
        }
        foreach ($by_order as $oid => $ids) {
            $order = wc_get_order($oid); if (!$order) continue;
            foreach ($ids as $iid) { $it = $order->get_item($iid); if ($it){ $it->update_meta_data('eject_excluded',1); $it->save(); } }
            $order->save();
        }
        wp_send_json_success(['message'=>'Dismissed selected']);
    }
}
