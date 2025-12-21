<?php
/*
 * File: includes/class-traxs-rest-workorders.php
 * Description: REST endpoint to stream Work Order PDFs.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-12 EDT
 */

namespace Traxs;

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/class-traxs-po-service.php';
require_once TRAXS_BACKEND_DIR . 'class-eject-workorders.php';

class Traxs_REST_Workorders {

    /** Hook routes */
    public static function init() {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /** Register /traxs/v1/workorders (GET) */
    public static function register_routes() {
        register_rest_route('traxs/v1', '/workorders', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle'],
            // FIX: must be public (and callable)
            'permission_callback' => '__return_true',
            'args'                => [
                // Optional: single order_id for testing a specific order
                'order_id' => [
                    'type'     => 'integer',
                    'required' => false,
                ],
            ],
        ]);
    }

    /** Allow admins, shop managers, or the traxs-only role */
    public static function can_run($request = null) : bool {
        return current_user_can('manage_woocommerce')
            || current_user_can('manage_options')
            || current_user_can('traxs_use');
    }

    /** Build and stream the PDF */
    public static function handle(\WP_REST_Request $req) {
        // Optional single-order test
        $single_id = absint($req->get_param('order_id'));
        if ($single_id) {
            $o = wc_get_order($single_id);
            if ($o && $o->get_status() !== 'completed') {
                \Eject_Workorders::output_single_order_pdf($single_id);
            } else {
                return new \WP_Error('traxs_no_work', 'Order is completed or invalid.', ['status' => 400]);
            }
        }

        $processing = self::get_processing_order_ids();
        if (empty($processing)) {
            return new \WP_Error('traxs_none', 'No outstanding work orders to print.', ['status' => 404]);
        }

        \Eject_Workorders::output_for_order_ids($processing);
    }

    private static function get_processing_order_ids(): array {
        return wc_get_orders([
            'limit'   => -1,
            'status'  => 'processing',
            'return'  => 'ids',
            'orderby' => 'date',
            'order'   => 'ASC',
        ]);
    }

    private static function orders_with_received_items(): array {
        $matched = [];
        $pos = get_posts([
            'post_type'   => 'eject_po',
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
            'meta_query'  => [
                [
                    'key'     => '_order_ids',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        foreach ($pos as $po) {
            $po_id = (int) ($po instanceof \WP_Post ? $po->ID : $po);
            if ($po_id <= 0) {
                continue;
            }
            $lines = Traxs_PO_Service::get_po_lines($po_id);
            foreach ($lines as $line) {
                $received = isset($line['received_qty']) ? (int)$line['received_qty'] : 0;
                if ($received <= 0) {
                    continue;
                }
                $orders = isset($line['order_ids']) && is_array($line['order_ids'])
                    ? array_map('intval', $line['order_ids'])
                    : [];
            foreach ($orders as $oid) {
                if ($oid <= 0) {
                    continue;
                }
                $order = wc_get_order($oid);
                if (!$order || $order->get_status() !== 'processing') {
                    continue;
                }
                $matched[$oid] = $oid;
            }
            }
        }

        return array_values($matched);
    }

    private static function get_run_ids_for_orders(array $order_ids): array {
        if (empty($order_ids)) {
            return [];
        }

        $lookup = array_fill_keys(array_map('intval', $order_ids), true);
        $run_ids = [];

        $pos = get_posts([
            'post_type'      => 'eject_po',
            'post_status'    => ['publish', 'draft'],
            'numberposts'    => -1,
            'meta_query'     => [
                [
                    'key'     => '_order_ids',
                    'compare' => 'EXISTS',
                ]
            ],
        ]);

        foreach ($pos as $po) {
            $ordered = (array) get_post_meta($po->ID, '_order_ids', true);
            foreach ($ordered as $oid) {
                $oid = (int) $oid;
                if (!isset($lookup[$oid])) {
                    continue;
                }
                $run = get_post_meta($po->ID, '_run_id', true);
                if ($run === '') {
                    $run = 'po-' . $po->ID;
                }
                $run_ids[$run] = $run;
                break;
            }
        }

        return array_values($run_ids);
    }

    private static function auto_flag_ready_orders(array $order_ids): array {
        if (empty($order_ids)) {
            return [];
        }

        $ready = [];
        $pending = [];
        foreach ($order_ids as $order_id) {
            $order_id = (int)$order_id;
            if ($order_id <= 0) {
                continue;
            }
            $ready_flag = get_post_meta($order_id, '_traxs_ready_for_workorder', true);
            if ($ready_flag === 'yes') {
                $ready[$order_id] = $order_id;
            } else {
                $pending[$order_id] = $order_id;
            }
        }

        if (!empty($pending)) {
            $auto_ready = self::orders_ready_via_po(array_values($pending));
            foreach ($auto_ready as $order_id) {
                update_post_meta($order_id, '_traxs_ready_for_workorder', 'yes');
                $ready[$order_id] = $order_id;
            }
        }

        return array_values(array_unique($ready));
    }

    private static function orders_ready_via_po(array $order_ids): array {
        if (empty($order_ids)) {
            return [];
        }

        $lookup = array_fill_keys(array_map('intval', $order_ids), true);
        $matched = [];

        $pos = get_posts([
            'post_type'   => 'eject_po',
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
            'meta_query'  => [
                [
                    'key'     => '_order_ids',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        foreach ($pos as $po) {
            if (empty($lookup)) {
                break;
            }

            $po_id = (int) ($po instanceof \WP_Post ? $po->ID : $po);
            if ($po_id <= 0) {
                continue;
            }
            $lines = Traxs_PO_Service::get_po_lines($po_id);
            foreach ($lines as $line) {
                $received = isset($line['received_qty']) ? (int)$line['received_qty'] : 0;
                if ($received <= 0) {
                    continue;
                }
                $orders = isset($line['order_ids']) && is_array($line['order_ids'])
                    ? array_map('intval', $line['order_ids'])
                    : [];
                foreach ($orders as $oid) {
                    if ($oid <= 0 || !isset($lookup[$oid])) {
                        continue;
                    }
                    $matched[$oid] = $oid;
                    unset($lookup[$oid]);
                }
                if (empty($lookup)) {
                    break 2;
                }
            }
        }

        return array_values($matched);
    }
}

// Boot
Traxs_REST_Workorders::init();
