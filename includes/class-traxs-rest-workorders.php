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

require_once __DIR__ . '/class-traxs-workorder-pdf.php';

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
            // Skip completed orders; only outstanding become work orders
            $o = wc_get_order($single_id);
            if ($o && $o->get_status() !== 'completed') {
                WorkOrder_PDF::output_for_orders([$single_id]); // streams & exits
            } else {
                return new \WP_Error('traxs_no_work', 'Order is completed or invalid.', ['status' => 400]);
            }
        }

        // Find ALL non-completed orders whose goods are fully received (flag set by Receive flow)
        $args = [
            'limit'        => -1,
            'status'       => array_diff(wc_get_is_paid_statuses(), ['completed']),
            'return'       => 'ids',
            'orderby'      => 'date',
            'order'        => 'ASC',
            'paginate'     => false,
        ];

        // Only orders marked ready by Traxs
        // (set this meta to "yes" when all POs supplying the order are fully received)
        $ids = wc_get_orders($args);
        $ready = [];

        foreach ($ids as $order_id) {
            $ready_flag = get_post_meta($order_id, '_traxs_ready_for_workorder', true);
            if ($ready_flag === 'yes') {
                $ready[] = $order_id;
            }
        }

        if (empty($ready)) {
            return new \WP_Error('traxs_none', 'No outstanding work orders to print.', ['status' => 404]);
        }

        // Stream multi-page PDF (one page per order); this exits.
        WorkOrder_PDF::output_for_orders($ready);
    }
}

// Boot
Traxs_REST_Workorders::init();
