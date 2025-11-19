<?php
/*
 * File: includes/rest-traxs-drafts.php
 * Description: REST endpoints for persistent PO receive drafts.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-13 EDT
 */
namespace Traxs;
if (!defined('ABSPATH')) exit;

use WP_REST_Request;
use WP_REST_Response;

function traxs_rest_drafts_can_use(): bool {
    if (class_exists(__NAMESPACE__ . '\\Traxs_REST_Controller')) {
        return Traxs_REST_Controller::can_use();
    }
    return \current_user_can('manage_woocommerce') || \current_user_can('traxs_operator');
}

function traxs_rest_get_receive_draft(WP_REST_Request $req): WP_REST_Response {
    $po_id = (int) $req->get_param('po_id');
    if ($po_id <= 0) {
        return new WP_REST_Response(['error' => 'Missing po_id'], 400);
    }
    $raw   = \get_post_meta($po_id, '_traxs_receive_draft', true);
    $lines = \is_array($raw) ? $raw : [];
    return new WP_REST_Response([
        'po_id' => $po_id,
        'lines' => $lines,
    ], 200);
}

function traxs_rest_save_receive_draft(WP_REST_Request $req): WP_REST_Response {
    $po_id = (int) $req->get_param('po_id');
    $lines = $req->get_param('lines');

    if ($po_id <= 0 || !\is_array($lines)) {
        return new WP_REST_Response(['ok' => false, 'msg' => 'Missing po_id or lines'], 400);
    }

    $clean = [];
    foreach ($lines as $id => $qty) {
        $id  = \sanitize_text_field((string) $id);
        $qty = (int) $qty;
        if ($id === '' || $qty < 0) continue;
        $clean[$id] = $qty;
    }

    \update_post_meta($po_id, '_traxs_receive_draft', $clean);

    return new WP_REST_Response([
        'ok'    => true,
        'po_id' => $po_id,
        'lines' => $clean,
    ], 200);
}

\add_action('rest_api_init', function () {
    \register_rest_route('traxs/v1', '/receive-draft', [
        'methods'             => 'GET',
        'permission_callback' => __NAMESPACE__ . '\\traxs_rest_drafts_can_use',
        'callback'            => __NAMESPACE__ . '\\traxs_rest_get_receive_draft',
        'args'                => [
            'po_id' => [
                'required' => true,
                'type'     => 'integer',
            ],
        ],
    ]);

    \register_rest_route('traxs/v1', '/receive-draft', [
        'methods'             => 'POST',
        'permission_callback' => __NAMESPACE__ . '\\traxs_rest_drafts_can_use',
        'callback'            => __NAMESPACE__ . '\\traxs_rest_save_receive_draft',
    ]);
});
