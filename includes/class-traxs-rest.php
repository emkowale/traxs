<?php
/*
 * File: includes/class-traxs-rest.php
 * Description: Traxs REST endpoints (PO list, PO lines, receive, drafts, and workorders PDF).
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-14 EDT
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
                    'required'          => true,
                    'type'              => 'integer',
                    'validate_callback' => function ($v) {
                        return \is_numeric($v) && (int) $v > 0;
                    },
                ],
            ],
        ]);

        // POST /traxs/v1/receive
        \register_rest_route('traxs/v1', '/receive', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'can_use'],
            'callback'            => [__CLASS__, 'receive'],
        ]);

        // GET /traxs/v1/receive-draft?po_id=123
        \register_rest_route('traxs/v1', '/receive-draft', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'can_use'],
            'callback'            => [__CLASS__, 'get_receive_draft'],
            'args'                => [
                'po_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'validate_callback' => function ($v) {
                        return \is_numeric($v) && (int) $v > 0;
                    },
                ],
            ],
        ]);

        // POST /traxs/v1/receive-draft
        \register_rest_route('traxs/v1', '/receive-draft', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'can_use'],
            'callback'            => [__CLASS__, 'save_receive_draft'],
        ]);

        // GET /traxs/v1/workorders  (streams multi-page PDF)
        \register_rest_route('traxs/v1', '/workorders', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'can_use'],
            'callback'            => [__CLASS__, 'workorders'],
        ]);
    }

    /* ---------------- Handlers ---------------- */

    /** GET /pos → finalized POs with BT numbers, excluding fully received POs */
    public static function get_pos(WP_REST_Request $req): WP_REST_Response {
        $raw = \is_callable([Eject_Bridge::class, 'get_open_pos'])
            ? Eject_Bridge::get_open_pos()
            : [];
        if (!\is_array($raw)) {
            $raw = [];
        }

        $result = [];

        foreach ($raw as $row) {
            // Recover PO post ID from common fields.
            $po_id = 0;
            if (isset($row['po_post_id'])) {
                $po_id = (int) $row['po_post_id'];
            } elseif (isset($row['po_id'])) {
                $po_id = (int) $row['po_id'];
            } elseif (isset($row['id'])) {
                $po_id = (int) $row['id'];
            }

            if ($po_id <= 0) {
                // Unknown ID shape → keep it rather than accidentally hiding a PO.
                $result[] = $row;
                continue;
            }

            if (!\is_callable([Eject_Bridge::class, 'get_po_lines'])) {
                $result[] = $row;
                continue;
            }

            $lines = Eject_Bridge::get_po_lines($po_id);
            if (!\is_array($lines) || empty($lines)) {
                // No lines → treat as open to be safe.
                $result[] = $row;
                continue;
            }

            // fully_received = all lines have received_qty >= ordered_qty
            $any_short = false;
            foreach ($lines as $ln) {
                $ordered  = isset($ln['ordered_qty'])  ? (int) $ln['ordered_qty']  : 0;
                $received = isset($ln['received_qty']) ? (int) $ln['received_qty'] : 0;
                if ($received < $ordered) {
                    $any_short = true;
                    break;
                }
            }

            if (!$any_short) {
                // Fully received → drop from /pos list.
                continue;
            }

            $result[] = $row;
        }

        return new WP_REST_Response($result, 200);
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
        if (!\is_array($lines)) {
            $lines = [];
        }

        $po_number = (string) \get_post_meta($po_id, '_po_number', true);

        return new WP_REST_Response([
            'po_id'     => $po_id,
            'po_number' => $po_number,
            'lines'     => $lines,
        ], 200);
    }

    /**
     * GET /receive-draft?po_id=123
     * Returns saved draft quantities for this PO, if any.
     */
    public static function get_receive_draft(WP_REST_Request $req): WP_REST_Response {
        $po_id = (int) $req->get_param('po_id');
        if ($po_id <= 0) {
            return new WP_REST_Response(['error' => 'Missing or invalid po_id'], 400);
        }

        $raw = \get_post_meta($po_id, '_traxs_receive_draft', true);
        $lines = [];

        if (\is_string($raw)) {
            $decoded = \json_decode($raw, true);
            if (\is_array($decoded)) {
                $raw = $decoded;
            }
        }

        if (\is_array($raw) && isset($raw['lines']) && \is_array($raw['lines'])) {
            foreach ($raw['lines'] as $ln) {
                $id  = isset($ln['po_line_id']) ? (string) $ln['po_line_id'] : '';
                $qty = isset($ln['qty']) ? (int) $ln['qty'] : 0;
                if ($id === '' || $qty < 0) {
                    continue;
                }
                $lines[] = [
                    'po_line_id' => $id,
                    'qty'        => $qty,
                ];
            }
        }

        return new WP_REST_Response([
            'po_id' => $po_id,
            'lines' => $lines,
        ], 200);
    }

    /**
     * POST /receive-draft
     * Body: { po_id: int, lines: [{po_line_id, qty:int}, ...] }
     * Saves or clears draft quantities for a PO.
     */
    public static function save_receive_draft(WP_REST_Request $req): WP_REST_Response {
        $po_id    = (int) $req->get_param('po_id');
        $lines_in = $req->get_param('lines');

        if ($po_id <= 0) {
            return new WP_REST_Response(['ok' => false, 'msg' => 'Missing or invalid po_id'], 400);
        }

        if (!\is_array($lines_in)) {
            $lines_in = [];
        }

        $normalized = [];

        foreach ($lines_in as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $id  = isset($row['po_line_id']) ? (string) $row['po_line_id'] : '';
            $qty = isset($row['qty']) ? (int) $row['qty'] : 0;

            if ($id === '' || $qty < 0) {
                continue;
            }

            $normalized[] = [
                'po_line_id' => $id,
                'qty'        => $qty,
            ];
        }

        if (empty($normalized)) {
            \delete_post_meta($po_id, '_traxs_receive_draft');
            return new WP_REST_Response(['ok' => true, 'po_id' => $po_id, 'lines' => []], 200);
        }

        $payload = [
            'po_id' => $po_id,
            'lines' => $normalized,
        ];

        \update_post_meta($po_id, '_traxs_receive_draft', \wp_json_encode($payload));

        return new WP_REST_Response([
            'ok'    => true,
            'po_id' => $po_id,
            'lines' => $normalized,
        ], 200);
    }

    /**
     * POST /receive
     * Body: { po_id: int, lines: [{po_line_id, add_qty:int}, ...] }
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
            $po_line_id = isset($row['po_line_id']) ? \sanitize_text_field($row['po_line_id']) : '';
            $add_qty    = isset($row['add_qty']) ? (int) $row['add_qty'] : 0;
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

        // Delegate to core logic
        $result = Receive::handle_receive([
            'po_id' => $po_id,
            'lines' => $lines,
        ]);

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

        $args = [
            'status'  => \array_diff(\wc_get_is_paid_statuses(), ['completed']),
            'type'    => 'shop_order',
            'limit'   => -1,
            'return'  => 'ids',
            'orderby' => 'date',
            'order'   => 'ASC',
        ];
        if (empty($args['status'])) {
            $args['status'] = ['processing', 'on-hold', 'pending', 'wc-ready', 'wc-in-production'];
        }

        $order_ids = \wc_get_orders($args);
        if (!\is_array($order_ids)) {
            $order_ids = [];
        }

        WorkOrder_PDF::output_for_orders($order_ids);

        return new WP_REST_Response(['ok' => false, 'msg' => 'PDF output failed'], 500);
    }
}

Traxs_REST_Controller::init();
