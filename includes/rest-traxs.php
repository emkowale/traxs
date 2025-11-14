<?php
/*
 * File: includes/class-traxs-rest.php
 * Description: Traxs REST endpoints (PO list, PO lines, receive, and workorders PDF).
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-12 EDT
 */

namespace Traxs;
if (!defined('ABSPATH')) exit;

use WP_REST_Request;
use WP_REST_Response;

class Traxs_REST_Controller {

    /** Hook registrar */
    public static function init() {
        \add_action('rest_api_init', [__CLASS__, 'register_routes'], 0);
    }

    /** Single capability gate for all routes */
    public static function can_use(): bool {
        return \current_user_can('manage_woocommerce') || \current_user_can('traxs_operator');
    }

    /** Register all routes unconditionally (idempotent-safe) */
    public static function register_routes() {

        // GET /traxs/v1/pos
        \register_rest_route('traxs/v1', '/pos', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'can_use'],
            'callback'            => [__CLASS__, 'get_pos'],
        ]);

        // GET /traxs/v1/po-lines?po_id={eject_po_post_id}
        \register_rest_route('traxs/v1', '/po-lines', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'can_use'],
            'callback'            => [__CLASS__, 'get_po_lines'],
            'args'                => [
                'po_id' => [
                    'required' => true,
                    'type'     => 'integer',
                    'validate_callback' => function($v){ return is_numeric($v) && (int)$v > 0; },
                ],
            ],
        ]);

        // POST /traxs/v1/receive
        \register_rest_route('traxs/v1', '/receive', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'can_use'],
            'callback'            => [__CLASS__, 'receive'],
        ]);

        // GET /traxs/v1/workorders  (streams multi-page PDF)
        \register_rest_route('traxs/v1', '/workorders', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'can_use'],
            'callback'            => [__CLASS__, 'workorders'],
        ]);
    }

    /* ---------------- Handlers ---------------- */

    /** GET /pos → finalized POs with BT numbers */
    public static function get_pos(WP_REST_Request $req): WP_REST_Response {
        $data = \is_callable([Eject_Bridge::class, 'get_open_pos'])
            ? Eject_Bridge::get_open_pos()
            : [];
        if (!\is_array($data)) $data = [];
        return new WP_REST_Response($data, 200);
    }

    /** GET /po-lines?po_id=123 */
    public static function get_po_lines(WP_REST_Request $req): WP_REST_Response {
        $po_id = (int) $req->get_param('po_id');
        if ($po_id <= 0) {
            return new WP_REST_Response(['error' => 'Missing or invalid po_id'], 400);
        }

        $lines = \is_callable([Eject_Bridge::class, 'get_po_lines'])
            ? Eject_Bridge::get_po_lines($po_id)
            : [];
        if (!\is_array($lines)) $lines = [];

        $po_number = (string) \get_post_meta($po_id, '_po_number', true);

        return new WP_REST_Response([
            'po_id'     => $po_id,
            'po_number' => $po_number,
            'lines'     => $lines,
        ], 200);
    }

    /**
     * POST /receive
     * Body (SPA): { po_id, po_number?, lines: [{po_line_id, actual_qty:int, ordered_qty:int, ...}, ...] }
     * Body expected by core: { po_id, lines: [{po_line_id, add_qty:int}, ...] }
     *
     * This handler now maps actual_qty/qty → add_qty for backwards compatibility.
     */
    public static function receive(WP_REST_Request $req): WP_REST_Response {
        $po_id    = (int) $req->get_param('po_id');
        $lines_in = $req->get_param('lines');

        if ($po_id <= 0 || !\is_array($lines_in)) {
            return new WP_REST_Response(['ok' => false, 'msg' => 'Missing po_id or lines'], 400);
        }

        $lines = [];
        foreach ($lines_in as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $po_line_id = isset($row['po_line_id'])
                ? \sanitize_text_field($row['po_line_id'])
                : '';

            // Accept add_qty, or fall back to actual_qty/qty from the SPA payload
            $add_qty = 0;
            if (isset($row['add_qty'])) {
                $add_qty = (int) $row['add_qty'];
            } elseif (isset($row['actual_qty'])) {
                $add_qty = (int) $row['actual_qty'];
            } elseif (isset($row['qty'])) {
                $add_qty = (int) $row['qty'];
            }

            if ($po_line_id === '' || $add_qty <= 0) {
                continue;
            }

            $lines[] = [
                'po_line_id' => $po_line_id,
                'add_qty'    => $add_qty,
            ];
        }

        if (empty($lines)) {
            return new WP_REST_Response(['ok' => false, 'msg' => 'No valid lines'], 400);
        }

        // Build payload for core logic
        $payload = [
            'po_id'  => $po_id,
            'lines'  => $lines,
        ];

        // Pass through PO number if the SPA sent it (optional)
        $po_number = $req->get_param('po_number');
        if (!empty($po_number)) {
            $payload['po_number'] = \sanitize_text_field((string) $po_number);
        }

        // Delegate to core logic
        $result = Receive::handle_receive($payload);

        $code = (!empty($result['ok'])) ? 200 : 400;
        return new WP_REST_Response($result, $code);
    }

    /**
     * GET /workorders
     * Streams a multi-page PDF for all orders that are NOT completed.
     * (Uses WorkOrder_PDF::output_for_orders to render.)
     */
    public static function workorders(WP_REST_Request $req) {
        if (!\class_exists(__NAMESPACE__ . '\\WorkOrder_PDF')) {
            require_once __DIR__ . '/class-traxs-workorder-pdf.php';
        }

        // Pull all non-completed shop orders (processing/on-hold/pending/etc.)
        $args = [
            'status'       => array_diff(\wc_get_is_paid_statuses(), ['completed']), // leaves processing, etc.
            'type'         => 'shop_order',
            'limit'        => -1,
            'return'       => 'ids',
            'orderby'      => 'date',
            'order'        => 'ASC',
        ];
        // Safety: if Woo changes statuses, ensure a fallback
        if (empty($args['status'])) {
            $args['status'] = ['processing', 'on-hold', 'pending', 'wc-ready', 'wc-in-production'];
        }

        $order_ids = \wc_get_orders($args);
        if (!\is_array($order_ids)) $order_ids = [];

        // Stream PDF (exits)
        WorkOrder_PDF::output_for_orders($order_ids);

        // If the renderer didn’t exit (shouldn’t happen), return an error response
        return new WP_REST_Response(['ok' => false, 'msg' => 'PDF output failed'], 500);
    }
}

Traxs_REST_Controller::init();
