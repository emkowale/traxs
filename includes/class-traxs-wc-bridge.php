<?php
/*
 * File: includes/class-traxs-wc-bridge.php
 * Description: Woo helpers for order status/meta.
 */
namespace Traxs;
if (!defined('ABSPATH')) exit;

class WC_Bridge {
    public static function set_processing($order_id) {
        if (!class_exists('\\WC_Order')) return;
        $order = wc_get_order($order_id);
        if (!$order) return;
        if ($order->has_status(['on-hold','pending'])) {
            $order->update_status('processing', 'Traxs: goods fully received.');
        }
    }
    public static function get_customer_email($order) { return $order->get_billing_email(); }
    public static function get_item_meta($item, $key) { return wc_get_order_item_meta($item->get_id(), $key, true); }
}
