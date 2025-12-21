<?php
/*
 * File: includes/class-eject-workorders.php
 * Description: Generates Work Order PDFs for a run of POs.
 */

if (!defined('ABSPATH')) exit;

$tcpdf_cache = trailingslashit(wp_normalize_path(WP_CONTENT_DIR . '/uploads/traxs-tcpdf-cache'));
if (!file_exists($tcpdf_cache)) {
    wp_mkdir_p($tcpdf_cache);
}
if (!defined('K_PATH_CACHE')) {
    define('K_PATH_CACHE', $tcpdf_cache);
}

require_once EJECT_DIR . 'lib/tcpdf.php';

class Eject_Workorders {
    private const FOOTER_BUFFER = 12.0;
    public static function register(): void {
        add_action('admin_post_eject_print_workorders', [self::class, 'handle_print']);
        add_action('admin_post_eject_print_workorder', [self::class, 'handle_print_order']);
    }

    public static function handle_print(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Permission denied.');
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field($_POST['_wpnonce']) : '';
        if (!wp_verify_nonce($nonce, 'eject_print_workorders')) wp_die('Bad nonce.');

        $po_id  = isset($_POST['po_id']) ? absint($_POST['po_id']) : 0;
        if (!$po_id) wp_die('Missing PO ID.');

        $run_id = get_post_meta($po_id, '_run_id', true);
        if (!$run_id) $run_id = 'po-' . $po_id;

        $data = self::collect_run_data($run_id);
        if (empty($data['orders'])) {
            wp_die('No orders found for this run.');
        }

        self::render_pdf($data, $run_id);
        exit;
    }

    public static function handle_print_order(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Permission denied.');
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field((string) $_REQUEST['_wpnonce']) : '';
        if (!wp_verify_nonce($nonce, 'eject_print_workorder')) wp_die('Bad nonce.');

        $order_id = isset($_REQUEST['order_id']) ? absint($_REQUEST['order_id']) : 0;
        if ($order_id <= 0) {
            wp_die('Missing or invalid order ID.');
        }

        self::output_single_order_pdf($order_id);
    }

    public static function output_single_order_pdf(int $order_id, array $vendor_info = []): void {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            wp_die('Order not found.');
        }

        if (empty($vendor_info)) {
            $vendor_info = self::collect_vendor_pos_for_order($order_id);
        }

        $order_date = $order->get_date_created();
        $date_label = $order_date ? $order_date->date_i18n(get_option('date_format')) : '';
        $lines = \Eject_Service::lines_for_order($order, $vendor_info['vendor_ids'] ?? []);
        if (empty($lines)) {
            wp_die('No line items found for this order.');
        }

        $billing_name = trim((string)$order->get_formatted_billing_full_name()) ?: trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $shipping_name = trim((string)$order->get_formatted_shipping_full_name()) ?: trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
        if ($shipping_name === '') {
            $shipping_name = $billing_name;
        }
        $billing_address = self::format_order_address($order, 'billing');
        $shipping_address = self::format_order_address($order, 'shipping');
        if ($shipping_address === '') {
            $shipping_address = $billing_address;
        }

        $order_entry = [
            'order_id'        => $order->get_id(),
            'order_number'    => $order->get_order_number(),
            'items'           => self::group_lines($lines),
            'media'           => self::collect_media($order),
            'instructions'    => self::collect_instructions($order),
            'vendor_pos'      => $vendor_info['labels'] ?? [],
            'order_date'      => $date_label,
            'billing_name'    => $billing_name,
            'billing_address' => $billing_address,
            'billing_phone'   => trim(($order->get_billing_phone() ?: '')),
            'billing_email'   => trim(($order->get_billing_email() ?: '')),
            'shipping_name'   => $shipping_name,
            'shipping_address'=> $shipping_address,
        ];

        $data = [
            'orders'     => [$order_entry],
            'vendors'    => array_values($vendor_info['vendor_ids'] ?? []),
            'po_numbers' => !empty($vendor_info['labels']) ? $vendor_info['labels'] : [$order_entry['order_number']],
            'po_dates'   => $date_label ? [$date_label] : [],
            'po_ids'     => [$order_entry['order_id']],
        ];

        self::render_pdf($data, 'order-' . $order_entry['order_id']);
    }

    /** Collect orders + items for a run id */
    private static function collect_run_data(string $run_id): array {
        $args = [
            'post_type'   => 'eject_po',
            'post_status' => ['publish','draft'],
            'numberposts' => -1,
            'fields'      => 'ids',
        ];

        $po_by_id = 0;
        if (preg_match('/^po-(\d+)$/', $run_id, $matches)) {
            $po_by_id = (int) $matches[1];
        }

        if ($po_by_id > 0) {
            $args['post__in'] = [$po_by_id];
        } else {
            $args['meta_key']   = '_run_id';
            $args['meta_value'] = $run_id;
        }

        $pos = get_posts($args);

        if (empty($pos)) return ['orders' => [], 'vendors' => [], 'po_ids' => []];

        $vendors = [];
        $order_ids = [];
        $po_numbers = [];
        $po_dates   = [];
        $order_vendor_pos = [];
        foreach ($pos as $pid) {
            $vendor = get_post_meta($pid, '_vendor_id', true);
            if ($vendor !== '') $vendors[$vendor] = true;
            $oids = (array) get_post_meta($pid, '_order_ids', true);
            foreach ($oids as $oid) {
                if ($oid) $order_ids[$oid] = true;
            }
            $po_no = get_post_meta($pid, '_po_number', true);
            $po_numbers[] = $po_no ?: $pid;
            $po_date = get_post_meta($pid, '_po_date', true);
            if ($po_date) $po_dates[] = $po_date;
            $label = $po_no ?: (string) $pid;
            foreach ($oids as $oid) {
                $oid = (int) $oid;
                if ($oid <= 0) continue;
                $order_vendor_pos[$oid][$label] = $label;
            }
        }

        $orders = [];
        $vendor_list = array_keys($vendors);
        $processing_order_ids = wc_get_orders([
            'status' => 'processing',
            'limit'  => -1,
            'return' => 'ids',
        ]);
        foreach ($processing_order_ids as $processing_id) {
            if ($processing_id <= 0 || isset($order_ids[$processing_id])) {
                continue;
            }
            $order_ids[$processing_id] = true;
        }
        foreach (array_keys($order_ids) as $oid) {
            $order = wc_get_order($oid);
            if (!$order || !$order->has_status('processing')) continue;
            $lines = Eject_Service::lines_for_order($order, $vendor_list);
            $billing_name = trim((string)$order->get_formatted_billing_full_name()) ?: trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $shipping_name = trim((string)$order->get_formatted_shipping_full_name()) ?: trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
            if ($shipping_name === '') {
                $shipping_name = $billing_name;
            }
            $billing_address = self::format_order_address($order, 'billing');
            $shipping_address = self::format_order_address($order, 'shipping');
            if ($shipping_address === '') {
                $shipping_address = $billing_address;
            }
            $orders[] = [
                'order_id'      => $oid,
                'order_number'  => $order->get_order_number(),
                'vendor_pos'    => array_values(array_unique(array_filter($order_vendor_pos[$oid] ?? []))),
                'order_date'    => $order->get_date_created()
                    ? $order->get_date_created()->date_i18n(get_option('date_format'))
                    : '',
                'billing_name'     => $billing_name,
                'billing_address'  => $billing_address,
                'billing_phone'    => trim(($order->get_billing_phone() ?: '')),
                'billing_email'    => trim(($order->get_billing_email() ?: '')),
                'shipping_name'    => $shipping_name,
                'shipping_address' => $shipping_address,
            ];
            $billing_first = $order->get_billing_first_name() ?: get_post_meta($oid, '_billing_first_name', true);
            $billing_last = $order->get_billing_last_name() ?: get_post_meta($oid, '_billing_last_name', true);
            $shipping_first = $order->get_shipping_first_name() ?: get_post_meta($oid, '_shipping_first_name', true);
            $shipping_last = $order->get_shipping_last_name() ?: get_post_meta($oid, '_shipping_last_name', true);
        }

        return [
            'orders'     => $orders,
            'vendors'    => $vendor_list,
            'po_ids'     => $pos,
            'po_numbers' => array_values(array_unique(array_filter($po_numbers))),
            'po_dates'   => array_values(array_unique(array_filter($po_dates))),
        ];
    }

    private static function format_order_address(\WC_Order $order, string $type): string {
        $formatted = '';
        $formatted_method = sprintf('get_formatted_%s_address', $type);
        if (method_exists($order, $formatted_method)) {
            $formatted = (string)$order->$formatted_method();
        }
        if (trim($formatted) === '') {
            $fields = ['address_1', 'address_2', 'city', 'state', 'postcode', 'country'];
            $segments = [];
            foreach ($fields as $field) {
                $getter = "get_{$type}_{$field}";
                if (!method_exists($order, $getter)) {
                    continue;
                }
                $value = trim((string)$order->$getter());
                if ($value !== '') {
                    $segments[] = $value;
                }
            }
            if (!empty($segments)) {
                $formatted = implode("\n", $segments);
            }
        }
        return trim($formatted);
    }

    /** Group lines to VendorItem -> {product, colors => Size => Qty} */
    private static function group_lines(array $lines): array {
        $tree = [];
        foreach ($lines as $line) {
            $code  = $line['vendor_item_code'] ?: $line['product_name'];
            $color = $line['color'] ?: 'N/A';
            $size  = $line['size'] ?: 'N/A';
            $qty   = max(1, (int) $line['qty']);
            if (!isset($tree[$code])) {
                $tree[$code] = [
                    'product'     => $line['product_name'],
                    'colors'      => [],
                    'vendor_code' => $line['vendor_code'] ?? '',
                    'production'  => $line['production'] ?? '',
                ];
            } else {
                if ($tree[$code]['vendor_code'] === '' && !empty($line['vendor_code'])) {
                    $tree[$code]['vendor_code'] = $line['vendor_code'];
                }
                if ($tree[$code]['production'] === '' && !empty($line['production'])) {
                    $tree[$code]['production'] = $line['production'];
                }
            }
            if (!isset($tree[$code]['colors'][$color])) $tree[$code]['colors'][$color] = [];
            if (!isset($tree[$code]['colors'][$color][$size])) $tree[$code]['colors'][$color][$size] = 0;
            $tree[$code]['colors'][$color][$size] += $qty;
        }

        $size_order = ['NB','06M','12M','18M','24M','XS','S','M','L','XL','2XL','3XL','4XL','5XL'];
        foreach ($tree as $code => $entry) {
            foreach ($entry['colors'] as $color => $sizes) {
                uksort($sizes, function($a, $b) use ($size_order) {
                    $a_i = array_search(strtoupper(trim($a)), $size_order, true);
                    $b_i = array_search(strtoupper(trim($b)), $size_order, true);
                    $a_i = ($a_i === false) ? 999 : $a_i;
                    $b_i = ($b_i === false) ? 999 : $b_i;
                    if ($a_i === $b_i) return strcmp($a, $b);
                    return $a_i <=> $b_i;
                });
                $tree[$code]['colors'][$color] = $sizes;
            }
        }

        return $tree;
    }

    /** Collect special instructions from line items */
    private static function collect_instructions(WC_Order $order): array {
        $out = [];
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            $val = $item->get_meta('Special Instructions for production', true);
            if (!$val && $product) {
                $val = $product->get_meta('Special Instructions for production', true);
            }
            if ($val) $out[] = trim((string) $val);
        }
        return array_values(array_unique(array_filter($out)));
    }

    /** Collect media (mockup + original art) per product on the order */
    private static function collect_media(WC_Order $order): array {
        $out = [];
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            $name = $item->get_name();

            $mockup = self::extract_media_url($item, $product, [
                'mockup', 'mockup_url', 'mockup image', 'mockup_image', 'mockup preview',
                'mockup link', 'mockup file',
            ], false);

            $print_locations = self::collect_print_locations($item, $product);
            $art_keys = array_merge(
                self::build_print_location_art_keys($print_locations),
                [
                    'original_art', 'original art', 'art', 'artwork', 'art_url', 'art file', 'art_file',
                ]
            );
            $art_debug = [];
            $art = self::extract_media_url($item, $product, $art_keys, true, $art_debug);
            self::log_media_debug($item, 'art', [
                'status' => $art !== '' ? 'found' : 'missing',
                'scope' => $art_debug['scope'] ?? '',
                'key' => $art_debug['key'] ?? '',
                'url' => $art !== '' ? $art : '',
                'locations' => $print_locations,
            ]);

            if (!$mockup && !$art) continue;
            $out[] = [
                'product' => $name,
                'mockup'  => $mockup,
                'art'     => $art,
            ];
        }

        // Deduplicate by product+urls
        $seen = [];
        $dedup = [];
        foreach ($out as $rec) {
            $key = md5(strtolower($rec['product']).'|'.$rec['mockup'].'|'.$rec['art']);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $dedup[] = $rec;
        }
        return $dedup;
    }

    private static function extract_media_url(\WC_Order_Item_Product $item, ?\WC_Product $product, array $keys, bool $require_art_key = false, ?array &$debug = null): string {
        foreach ($keys as $key) {
            $value = $item->get_meta($key, true);
            $url = self::normalize_media_value($value);
            if ($url !== '') {
                self::apply_media_debug($debug, 'order_item_meta', $key, $url);
                return $url;
            }
        }

        foreach ($item->get_meta_data() as $meta) {
            $data = $meta->get_data();
            $metaKey = isset($data['key']) ? (string)$data['key'] : ($meta->key ?? '');
            if ($require_art_key && !self::key_needs_art($metaKey)) {
                continue;
            }
            $value = $data['value'] ?? ($meta->value ?? '');
            $url = self::normalize_media_value($value);
            if ($url !== '') {
                self::apply_media_debug($debug, 'order_item_meta_data', $metaKey, $url);
                return $url;
            }
        }

        $candidates = self::collect_item_product_candidates($item, $product);
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof \WC_Product) {
                continue;
            }
            foreach ($keys as $key) {
                $value = $candidate->get_meta($key, true);
                $url = self::normalize_media_value($value);
                if ($url !== '') {
                    $candidateKey = sprintf('%s (prod:%d)', $key, $candidate->get_id());
                    self::apply_media_debug($debug, 'candidate_meta', $candidateKey, $url);
                    return $url;
                }
            }
        }

        foreach ($candidates as $candidate) {
            $imageId = $candidate->get_image_id();
            if (!$imageId) {
                $imageId = (int) \get_post_thumbnail_id($candidate->get_id());
            }
            if (!$imageId) {
                continue;
            }
            $src = '';
            if (\function_exists('wp_get_attachment_image_url')) {
                $src = (string) \wp_get_attachment_image_url($imageId, 'thumbnail');
            }
            if ($src === '' && \function_exists('wp_get_attachment_url')) {
                $src = (string) \wp_get_attachment_url($imageId);
            }
            if ($src !== '') {
                self::apply_media_debug($debug, 'attachment', 'attachment_' . $imageId, $src);
                return $src;
            }
        }

        self::apply_media_debug($debug, 'missing', 'not_found', '');
        self::apply_media_debug($debug, 'missing', 'not_found', '');
        return '';
    }

    private static function apply_media_debug(?array &$debug, string $scope, string $key, string $url): void {
        if ($debug === null) {
            return;
        }
        $debug['scope'] = $scope;
        $debug['key'] = $key;
        $debug['url'] = $url;
    }

    private static function log_media_debug(\WC_Order_Item_Product $item, string $media_type, array $context): void {
        if (!defined('WP_CONTENT_DIR')) {
            return;
        }
        $order_id = method_exists($item, 'get_order_id') ? (int) $item->get_order_id() : 0;
        $locations = $context['locations'] ?? [];
        if (is_array($locations)) {
            $locations = implode('|', array_filter($locations, static function ($val) {
                return trim((string)$val) !== '';
            }));
        }
        if ($locations === '') {
            $locations = 'none';
        }
        $status = $context['status'] ?? 'unknown';
        $scope = $context['scope'] ?? '';
        $key = $context['key'] ?? '';
        $url = $context['url'] ?? '';
        $message = sprintf(
            "[Traxs media:%s] order_id=%d item_id=%d status=%s scope=%s key=%s url=%s locations=%s",
            $media_type,
            $order_id,
            $item->get_id(),
            $status,
            $scope,
            $key,
            $url,
            $locations
        );
        @file_put_contents(WP_CONTENT_DIR . '/debug.log', $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private static function key_needs_art(string $key): bool {
        $normalized = strtolower(trim($key));
        if ($normalized === '') {
            return false;
        }
        return strpos($normalized, 'original') !== false && strpos($normalized, 'art') !== false;
    }

    private static function collect_item_product_candidates(\WC_Order_Item_Product $item, ?\WC_Product $product): array {
        $candidates = [];
        $seen = [];
        $add = function (? \WC_Product $candidate) use (&$candidates, &$seen): void {
            if (!$candidate instanceof \WC_Product) {
                return;
            }
            $id = (int) $candidate->get_id();
            if ($id <= 0 || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $candidates[] = $candidate;
        };

        $variation_id = (int) $item->get_variation_id();
        if ($variation_id > 0) {
            $variation = wc_get_product($variation_id);
            $add($variation);
        }

        $add($product ?? $item->get_product());

        if (isset($variation) && $variation instanceof \WC_Product && $variation->get_parent_id()) {
            $parent = wc_get_product($variation->get_parent_id());
            $add($parent);
        }

        return $candidates;
    }

    private static function collect_print_locations(\WC_Order_Item_Product $item, ?\WC_Product $product): array {
        $keys = [
            'Print Location',
            'print-location',
            'print_location',
            'print location',
        ];
        $values = [];
        foreach ($keys as $key) {
            $value = $item->get_meta($key, true);
            $value = is_scalar($value) ? trim((string) $value) : '';
            if ($value === '') {
                continue;
            }
            $values[] = $value;
        }
        if (empty($values) && $product instanceof \WC_Product) {
            foreach ($keys as $key) {
                $value = $product->get_meta($key, true);
                $value = is_scalar($value) ? trim((string) $value) : '';
                if ($value === '') {
                    continue;
                }
                $values[] = $value;
            }
        }
        return array_values(array_unique($values));
    }

    private static function build_print_location_art_keys(array $locations): array {
        $keys = [
            'Original Art {Print Location}',
            'original_art_print_location',
            'original art print location',
        ];
        foreach ($locations as $location) {
            $normalized = trim((string) $location);
            if ($normalized === '') {
                continue;
            }
            $title = ucwords(strtolower($normalized));
            $openParen = sprintf('Original Art (%s)', $normalized);
            $openParenTitle = sprintf('Original Art (%s)', $title);
            $keys[] = sprintf('Original Art %s', $normalized);
            $keys[] = sprintf('Original Art %s', $title);
            $keys[] = $openParen;
            $keys[] = $openParenTitle;
            $keys[] = sprintf('Original Image %s', $normalized);
            $keys[] = sprintf('Original Image %s', $title);
            $keys[] = sprintf('Original Image (%s)', $normalized);
            $keys[] = sprintf('Original Image (%s)', $title);
            foreach ([' - ', ' – ', ' — '] as $dash) {
                $keys[] = sprintf('Original Art%s%s', $dash, $normalized);
                $keys[] = sprintf('Original Art%s%s', $dash, $title);
                $keys[] = sprintf('Original Image%s%s', $dash, $normalized);
                $keys[] = sprintf('Original Image%s%s', $dash, $title);
            }
            $slug = '';
            if (function_exists('sanitize_key')) {
                $slug = sanitize_key($normalized);
            } else {
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $normalized));
            }
            if ($slug !== '') {
                $keys[] = sprintf('original_art_%s', $slug);
                $keys[] = sprintf('original-image-%s', $slug);
                $keys[] = sprintf('original_image_%s', $slug);
                $keys[] = sprintf('originalimage_%s', $slug);
            }
        }
        return array_values(array_unique(array_filter($keys)));
    }

    private static function collect_vendor_pos_for_order(int $order_id): array {
        $pos = get_posts([
            'post_type'   => 'eject_po',
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                [
                    'key'     => '_order_ids',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        if (empty($pos)) {
            return [
                'labels'     => [],
                'vendor_ids' => [],
            ];
        }

        $labels = [];
        $vendor_ids = [];
        foreach ($pos as $po_id) {
            $order_ids = (array) get_post_meta($po_id, '_order_ids', true);
            $order_ids = array_map('intval', $order_ids);
            if (!in_array($order_id, $order_ids, true)) {
                continue;
            }
            $labels[] = self::po_label_for($po_id);
            $vendor = get_post_meta($po_id, '_vendor_id', true);
            if ($vendor !== '') {
                $vendor_ids[] = $vendor;
            }
        }

        return [
            'labels'     => array_values(array_unique(array_filter($labels))),
            'vendor_ids' => array_values(array_unique(array_filter($vendor_ids))),
        ];
    }

    private static function po_label_for(int $po_id): string {
        $po_number = get_post_meta($po_id, '_po_number', true);
        if ($po_number) {
            return (string) $po_number;
        }
        return (string) $po_id;
    }

    private static function normalize_media_value($val): string {
        if (is_array($val)) {
            if (isset($val['url'])) return self::clean_url($val['url']);
            if (isset($val[0])) return self::clean_url($val[0]);
        }
        if (is_numeric($val)) {
            $url = wp_get_attachment_url((int)$val);
            if ($url) return self::clean_url($url);
        }
        if (is_string($val) && $val !== '') {
            return self::clean_url($val);
        }
        return '';
    }

    private static function clean_url(string $url): string {
        $url = trim($url);
        if ($url === '') return '';
        // Basic validation: only http/https
        if (stripos($url, 'http://') === 0 || stripos($url, 'https://') === 0) {
            return $url;
        }
        return '';
    }

    private static function render_pdf(array $data, string $run_id): void {
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '512M');
        }
        $margin = 6.35; // 1/4"
        $pdf = new Traxs_Workorder_TCPDF('P','mm','LETTER', true, 'UTF-8', false);
        $pdf->SetCreator('Traxs');
        $pdf->SetAuthor('Traxs');
        $pdf->SetTitle('Traxs Work Orders');
        while ($pdf->getNumPages() > 0) {
            $pdf->deletePage(1);
        }
        $pdf->SetMargins($margin, $margin, $margin);
        $pdf->SetFooterMargin(self::FOOTER_BUFFER);
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintFooter(false);

        $logo      = self::get_logo_url();

        $pdf->setPrintHeader(true);
        Traxs_Workorder_TCPDF::resetWorkorderHeaderContext();

        $vendor_list = $data['vendors'] ?? [];
        foreach ($data['orders'] as $order) {
            $start_page = $pdf->getNumPages() + 1;
            $order_po_label = !empty($order['vendor_pos']) ? implode(', ', $order['vendor_pos']) : '';
            $order_date_label = isset($order['order_date']) ? (string)$order['order_date'] : '';
            Traxs_Workorder_TCPDF::setWorkorderHeaderContext([
                'order_number' => $order['order_number'],
                'po_label'     => $order_po_label,
                'date_label'   => $order_date_label,
                'logo_url'     => $logo,
                'billing_name'     => $order['billing_name'] ?? '',
                'billing_address'  => $order['billing_address'] ?? '',
                'billing_phone'    => $order['billing_phone'] ?? '',
                'billing_email'    => $order['billing_email'] ?? '',
                'shipping_name'    => $order['shipping_name'] ?? '',
                'shipping_address' => $order['shipping_address'] ?? '',
            ]);
            $wc_order = wc_get_order($order['order_id'] ?? 0);
            if (!$wc_order) {
                Traxs_Workorder_TCPDF::resetWorkorderHeaderContext();
                continue;
            }
            $pdf->AddPage();
            $lines = Eject_Service::lines_for_order($wc_order, $vendor_list);
            $items = self::group_lines($lines);
            $media = self::collect_media($wc_order);

            self::ensure_page_space($pdf, 40);
            self::render_items_table($pdf, $items);
            self::render_special_instructions($pdf, $order['instructions'] ?? []);

            if (!empty($media)) {
                $pdf->Ln(6);
                self::ensure_page_space($pdf, 62);
                self::render_media_table($pdf, $media);
            }

            // Footer (per order page counts)
            $end_page    = $pdf->getNumPages();
            $total_pages = $end_page - $start_page + 1;
            $current     = $pdf->getPage();
            $order_footers = [];
            for ($p = $start_page, $i = 1; $p <= $end_page; $p++, $i++) {
                $order_footers[$p] = [
                    'order_number' => $order['order_number'],
                    'page_number'  => $i,
                    'total_pages'  => $total_pages,
                ];
            }
            foreach ($order_footers as $page => $footer) {
                $pdf->setPage($page);
                self::render_order_footer(
                    $pdf,
                    $footer['order_number'],
                    $footer['page_number'],
                    $footer['total_pages']
                );
            }
            $pdf->setPage($current);
            Traxs_Workorder_TCPDF::resetWorkorderHeaderContext();
        }

        $tmp_file = tempnam(sys_get_temp_dir(), 'traxs_pdf_');
        if ($tmp_file === false) {
            wp_die('Unable to create temporary file for PDF output.');
        }
        $pdf->Output($tmp_file, 'F');
        if (function_exists('ob_get_length') && ob_get_length()) {
            @ob_end_clean();
        }
        $size = filesize($tmp_file);
        if ($size === false) $size = 0;
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="work-orders-'.$run_id.'.pdf"');
        header('Content-Length: '. $size);
        readfile($tmp_file);
        @unlink($tmp_file);
    }

    public static function output_for_run_ids(array $run_ids): void {
        $run_ids = array_values(array_unique(array_filter(array_map('trim', $run_ids))));
        if (empty($run_ids)) {
            wp_die('No ready work orders to print.');
        }

        $combined = [
            'orders'     => [],
            'vendors'    => [],
            'po_numbers' => [],
            'po_dates'   => [],
            'po_ids'     => [],
        ];

        foreach ($run_ids as $run_id) {
            $data = self::collect_run_data($run_id);
            if (empty($data['orders'])) {
                continue;
            }
            $combined['orders']     = array_merge($combined['orders'], $data['orders']);
            $combined['po_numbers'] = array_merge($combined['po_numbers'], $data['po_numbers']);
            $combined['po_dates']   = array_merge($combined['po_dates'], $data['po_dates']);
            $combined['po_ids']     = array_merge($combined['po_ids'], $data['po_ids']);
        }

        if (empty($combined['orders'])) {
            wp_die('No ready work orders to print.');
        }

        $combined['po_numbers'] = array_values(array_unique(array_filter($combined['po_numbers'])));
        $combined['po_dates']   = array_values(array_unique(array_filter($combined['po_dates'])));
        if (empty($combined['po_numbers'])) {
            $combined['po_numbers'] = ['Traxs Work Orders'];
        }

        self::serve_chunked_orders(
            $combined['orders'],
            $combined['vendors'],
            'traxs-ready-chunk'
        );
    }

    public static function output_for_order_ids(array $order_ids): void {
        $order_ids = array_values(array_unique(array_filter(array_map('intval', $order_ids))));
        if (empty($order_ids)) {
            wp_die('No ready work orders to print.');
        }

        $data = self::collect_orders_data($order_ids);
        if (empty($data['orders'])) {
            wp_die('No ready work orders to print.');
        }

        self::serve_chunked_orders(
            $data['orders'],
            $data['vendors'],
            'traxs-ready-orders'
        );
    }

    private static function collect_orders_data(array $order_ids): array {
        $orders = [];
        $vendor_accumulator = [];
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order || $order->has_status('completed')) {
                continue;
            }
            $vendor_info = self::collect_vendor_pos_for_order($order_id);
            $vendor_labels = array_values(array_unique(array_filter($vendor_info['labels'] ?? [])));
            if (empty($vendor_labels)) {
                $vendor_labels = [$order->get_order_number()];
            }
            foreach ($vendor_info['vendor_ids'] ?? [] as $vendor_id) {
                $vendor_accumulator[(string) $vendor_id] = $vendor_id;
            }

            $billing_name = trim((string)$order->get_formatted_billing_full_name()) ?: trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $shipping_name = trim((string)$order->get_formatted_shipping_full_name()) ?: trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
            if ($shipping_name === '') {
                $shipping_name = $billing_name;
            }
            $billing_address = self::format_order_address($order, 'billing');
            $shipping_address = self::format_order_address($order, 'shipping');
            if ($shipping_address === '') {
                $shipping_address = $billing_address;
            }

            $orders[] = [
                'order_id'        => $order->get_id(),
                'order_number'    => $order->get_order_number(),
                'vendor_pos'      => $vendor_labels,
                'order_date'      => $order->get_date_created()
                    ? $order->get_date_created()->date_i18n(get_option('date_format'))
                    : '',
                'billing_name'    => $billing_name,
                'billing_address' => $billing_address,
                'billing_phone'   => trim(($order->get_billing_phone() ?: '')),
                'billing_email'   => trim(($order->get_billing_email() ?: '')),
                'shipping_name'   => $shipping_name,
                'shipping_address'=> $shipping_address,
            ];
        }

        return [
            'orders'  => $orders,
            'vendors' => array_values(array_unique(array_filter($vendor_accumulator))),
        ];
    }

    private static function serve_chunked_orders(array $orders, array $vendors, string $run_prefix): void {
        $chunk_size = isset($_REQUEST['chunk_size']) ? max(1, (int) $_REQUEST['chunk_size']) : 8;
        $chunk_index = isset($_REQUEST['chunk']) ? max(0, (int) $_REQUEST['chunk']) : 0;
        $order_chunks = array_chunk($orders, $chunk_size);
        if (!isset($order_chunks[$chunk_index])) {
            wp_die('Invalid order chunk requested.');
        }
        $chunk_data = [
            'orders'  => $order_chunks[$chunk_index],
            'vendors' => $vendors,
        ];
        header('X-Traxs-Chunk-Index: ' . $chunk_index);
        header('X-Traxs-Chunk-Total: ' . count($order_chunks));
        self::render_pdf($chunk_data, $run_prefix . '-' . $chunk_index);
    }

    private const DEFAULT_LOGO_URL = 'https://thebeartraxs.com/wp-content/uploads/2025/05/The-Bear-Traxs-Logo.png';

    private static function render_items_table(TCPDF $pdf, array $items): void {
        $available = $pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right'];
        $weights = [
            'item'        => 60,
            'vendor_code' => 10,
            'color'       => 10,
            'size'        => 10,
            'qty'         => 10,
            'production'  => 10,
        ];
        $totalWeight = array_sum($weights);
        $cols = [];
        foreach ($weights as $key => $percent) {
            $cols[$key] = $available * ($percent / $totalWeight);
        }

        $pdf->SetFont('dejavusans','B',8);
        $pdf->Cell($cols['item'],7,'Items',1,0,'L');
        $pdf->Cell($cols['vendor_code'],7,'Vendor Code',1,0,'C');
        $pdf->Cell($cols['color'],7,'Color',1,0,'C');
        $pdf->Cell($cols['size'],7,'Size',1,0,'C');
        $pdf->Cell($cols['qty'],7,'Qty',1,0,'C');
        $pdf->Cell($cols['production'],7,'Production',1,1,'C');

        $pdf->SetFont('dejavusans','',10);
        $rowIndex = 0;
        foreach ($items as $code => $entry) {
            $product = $entry['product'] ?? '';
            $vendor_code = (string)($entry['vendor_code'] ?? '');
            $production = (string)($entry['production'] ?? '');
            $colors  = $entry['colors'] ?? [];
            foreach ($colors as $color => $sizes) {
                foreach ($sizes as $size => $qty) {
                    $item_text = $product !== '' ? $product : $code;
                    $h_item = $pdf->getStringHeight($cols['item'], $item_text);
                    $h_color = $pdf->getStringHeight($cols['color'], $color);
                    $h_size = $pdf->getStringHeight($cols['size'], $size);
                    $max_height = max($h_item, $h_color, $h_size, 6);
                    self::ensure_page_space($pdf, $max_height + 4);
                    $fill = ($rowIndex % 2) === 1;
                    if ($fill) {
                        $pdf->SetFillColor(245, 245, 245);
                    } else {
                        $pdf->SetFillColor(255, 255, 255);
                    }
                    $pdf->MultiCell($cols['item'], $max_height, $item_text, 0, 'L', $fill, 0);
                    $pdf->Cell($cols['vendor_code'], $max_height, $vendor_code, 0, 0, 'C', $fill);
                    $pdf->MultiCell($cols['color'], $max_height, $color, 0, 'C', $fill, 0);
                    $pdf->MultiCell($cols['size'], $max_height, $size, 0, 'C', $fill, 0);
                    $pdf->Cell($cols['qty'], $max_height, (string)$qty, 0, 0, 'C', $fill);
                    $pdf->Cell($cols['production'], $max_height, $production, 0, 1, 'L', $fill);
                    $rowIndex++;
                }
            }
        }
    }

    private static function render_special_instructions(TCPDF $pdf, array $instructions): void {
        $lines = [];
        foreach ($instructions as $instruction) {
            $trimmed = trim((string) $instruction);
            if ($trimmed === '') {
                continue;
            }
            $lines[] = $trimmed;
        }
        if (empty($lines)) {
            return;
        }

        $pdf->SetFont('dejavusans','',9);
        $width = $pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right'];
        $text = 'Special instructions for production: ' . implode("\n", $lines);
        $lineHeight = 5;
        $height = max(6, $pdf->getStringHeight($width, $text));
        self::ensure_page_space($pdf, $height + 6);

        $pdf->Ln(4);
        $pdf->MultiCell($width, $lineHeight, $text, 0, 'L', false, 1);
    }

    private static function ensure_page_space(TCPDF $pdf, float $required_height): void {
        $bottom_limit = $pdf->getPageHeight() - $pdf->getMargins()['bottom'] - self::FOOTER_BUFFER;
        if ($pdf->GetY() + $required_height > $bottom_limit) {
            $pdf->AddPage();
            Traxs_Workorder_TCPDF::ensureContentBelowHeader($pdf);
        }
    }

    private static function render_media_table(TCPDF $pdf, array $media): void {
        $col_width = ($pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right']) / 2;
        $cell_height = 55 * 0.8; // 20% shorter
        $inner_margin_x = 10 / 2.54; // horizontal padding
        $inner_margin_y = 10 / 2.54; // vertical padding

        $pdf->SetFont('dejavusans','B',11);
        $pdf->Cell($col_width,6,'Mockup',1,0,'C');
        $pdf->Cell($col_width,6,'Original Art',1,1,'C');
        $pdf->SetFont('dejavusans','',9);

        foreach ($media as $m) {
            self::ensure_page_space($pdf, $cell_height + 4);
            $startX = $pdf->GetX();
            $startY = $pdf->GetY();

            $pdf->Rect($startX, $startY, $col_width, $cell_height);
            $pdf->Rect($startX + $col_width, $startY, $col_width, $cell_height);

            $mockup_url = $m['mockup'] ?? '';
            $art_url = $m['art'] ?? '';

            $mockup_ready_width = $col_width - ($inner_margin_x * 2);
            $mockup_ready_height = $cell_height - ($inner_margin_y * 2);
            $pdf->SetXY($startX + $inner_margin_x, $startY + $inner_margin_y);
            if ($mockup_url) {
                $error = '';
                if (!self::render_image($pdf, $mockup_url, $mockup_ready_width, $mockup_ready_height, true, 0.9, $error)) {
                    $text = 'Mockup unavailable';
                    if ($error) {
                        $text .= ' – ' . $error;
                    }
                    $pdf->MultiCell($mockup_ready_width, 5, $text, 0, 'C', false, 0);
                }
            } else {
                $pdf->MultiCell($mockup_ready_width, 5, 'Mockup missing (no URL provided)', 0, 'C', false, 0);
            }

            $art_ready_width = $col_width - ($inner_margin_x * 2);
            $art_ready_height = $cell_height - ($inner_margin_y * 2);
            $pdf->SetXY($startX + $col_width + $inner_margin_x, $startY + $inner_margin_y);
            if ($art_url) {
                $error = '';
                if (!self::render_image($pdf, $art_url, $art_ready_width, $art_ready_height, true, 0.9, $error)) {
                    $text = 'Original art unavailable';
                    if ($error) {
                        $text .= ' – ' . $error;
                    }
                    $pdf->MultiCell($art_ready_width, 5, $text, 0, 'C', false, 0);
                }
            } else {
                $pdf->MultiCell($art_ready_width, 5, 'Original art missing (no URL provided)', 0, 'C', false, 0);
            }

            $pdf->SetXY($startX, $startY + $cell_height);
        }
    }

    public static function render_header(TCPDF $pdf, string $order_number, string $po_label, string $date_label, string $logo_url, array $context = []): void {
        $margins = $pdf->getMargins();
        $qr_size = 25.4; // 1"
        $logo_w  = 25.4; // 1"

        $qr_url = 'https://thebeartraxs.com/traxs?ordernumber='.urlencode($order_number);
        $style = ['border'=>0,'padding'=>0,'fgcolor'=>[0,0,0],'bgcolor'=>false];
        $qr_x = $pdf->getPageWidth() - $margins['right'] - $qr_size;
        $qr_y = $margins['top'];
        $pdf->write2DBarcode($qr_url, 'QRCODE,H', $qr_x, $qr_y, $qr_size, $qr_size, $style, 'N');

        $pdf->SetFont('dejavusans','B',12);
        $pdf->SetXY($margins['left'], $margins['top']);
        $pdf->MultiCell(0,5,"The Bear Traxs\nWork Order #".$order_number,0,'L',false,1);
        $pdf->SetFont('dejavusans','',10);
        if ($po_label !== '') {
            $pdf->MultiCell(0,5,'Vendor POs: '.$po_label,0,'L',false,1);
        }
        if ($date_label !== '') {
            $pdf->MultiCell(0,5,'Date: '.$date_label,0,'L',false,1);
        }

        if ($logo_url) {
            $logo_x = ($pdf->getPageWidth() - $logo_w) / 2;
            $logo_y = $margins['top'];
            $orig_x = $pdf->GetX();
            $orig_y = $pdf->GetY();
            $pdf->SetXY($logo_x, $logo_y);
            self::render_image($pdf, $logo_url, $logo_w, $logo_w);
            $pdf->SetXY($orig_x, max($orig_y, $logo_y + $logo_w + 2));
        }

        $tableY = max($pdf->GetY() + 4, $qr_y + $qr_size + 6);
        $pdf->SetY($tableY);
        $tableHeight = self::render_customer_table($pdf, $context, $margins);
        $bodyMinY = $qr_y + $qr_size + 4;
        $bodyY = max($tableY + $tableHeight + 4, $bodyMinY);
        $pdf->SetY($bodyY);
        Traxs_Workorder_TCPDF::setHeaderBottom($bodyY);
    }

    private static function get_logo_url(): string {
        $logo_id = get_theme_mod('custom_logo');
        $resolved = self::DEFAULT_LOGO_URL;
        if ($logo_id) {
            $src = wp_get_attachment_image_src($logo_id, 'full');
            if ($src && !empty($src[0])) {
                $resolved = $src[0];
            }
        }
        return $resolved;
    }

    private static function render_image(TCPDF $pdf, string $url, float $width = 60, float $forced_height = 0, bool $center = false, float $scale = 1.0, string &$error = ''): bool {
        $error = '';
        $temp_files = [];
        $size = null;
        $path = $url;

        if (stripos($url, 'http://') === 0 || stripos($url, 'https://') === 0) {
            $resp = wp_remote_get($url, ['timeout' => 15]);
            if (is_wp_error($resp)) {
                $error = 'Network error';
                self::cleanup_temp_images($temp_files);
                return false;
            }
            $code = wp_remote_retrieve_response_code($resp);
            if ($code !== 200) {
                $error = 'HTTP ' . $code;
                self::cleanup_temp_images($temp_files);
                return false;
            }
            $body = wp_remote_retrieve_body($resp);
            if ($body === '') {
                $error = 'Empty response';
                self::cleanup_temp_images($temp_files);
                return false;
            }
            $content_type = wp_remote_retrieve_header($resp, 'content-type');
            if (is_array($content_type)) {
                $content_type = reset($content_type);
            }
            $ext = 'jpg';
            if (is_string($content_type)) {
                $map = ['png' => 'png', 'gif' => 'gif', 'jpeg' => 'jpg', 'webp' => 'webp'];
                foreach ($map as $needle => $candidate_ext) {
                    if (stripos($content_type, $needle) !== false) {
                        $ext = $candidate_ext;
                        break;
                    }
                }
            }
            $temp = tempnam(sys_get_temp_dir(), 'eject_img_') . '.' . $ext;
            @file_put_contents($temp, $body);
            $temp_files[] = $temp;
            $path = $temp;
            $size = @getimagesize($path);
            if (!$size) {
                $img = @imagecreatefromstring($body);
                if ($img) {
                    $converted = tempnam(sys_get_temp_dir(), 'eject_img_') . '.png';
                    imagepng($img, $converted);
                    imagedestroy($img);
                    $temp_files[] = $converted;
                    $path = $converted;
                    $size = @getimagesize($path);
                }
            }
        } else {
            $size = @getimagesize($path);
        }

        if (!$size || !isset($size[0], $size[1]) || $size[0] <= 0 || $size[1] <= 0) {
            $error = 'Unsupported image';
            self::cleanup_temp_images($temp_files);
            $converted = self::convert_image_with_python($path, $temp_files, $error);
            if ($converted) {
                $path = $converted;
                $size = @getimagesize($path);
            }
            if (!$size || !isset($size[0], $size[1]) || $size[0] <= 0 || $size[1] <= 0) {
                return false;
            }
        }

        $source_width = max(1.0, (float) $size[0]);
        $source_height = max(1.0, (float) $size[1]);
        $aspect_ratio = $source_width / $source_height;
        $max_width = $width * $scale;
        $max_height = ($forced_height > 0) ? $forced_height * $scale : PHP_FLOAT_MAX;

        $draw_width = $max_width;
        $draw_height = $draw_width / $aspect_ratio;
        if ($draw_height > $max_height) {
            $draw_height = $max_height;
            $draw_width = $draw_height * $aspect_ratio;
        }
        if ($draw_width > $max_width) {
            $draw_width = $max_width;
            $draw_height = $draw_width / $aspect_ratio;
        }
        $draw_height = min($draw_height, $max_height);

        $x = $pdf->GetX();
        $y = $pdf->GetY();
        if ($center) {
            $x += max(0, ($width - $draw_width) / 2);
        }

        try {
            $pdf->Image($path, $x, $y, $draw_width, $draw_height, '', '', 'T', false, 300, '', false, false, 0, false, false, false);
        } catch (\Throwable $e) {
            $error = 'Rendering failed';
            self::cleanup_temp_images($temp_files);
            return false;
        }

        $pdf->SetXY($x, $y + max($draw_height, 1));
        self::cleanup_temp_images($temp_files);
        return true;
    }

    private static function cleanup_temp_images(array &$files): void {
        foreach ($files as $file) {
            if ($file && file_exists($file)) {
                @unlink($file);
            }
        }
    }

    private static function convert_image_with_python(string $source, array &$temp_files, string &$error): ?string {
        static $script = null;
        if ($script === null) {
            $script = <<<'PY'
import sys
from pathlib import Path

try:
    from PIL import Image
except ImportError as exc:
    print(f'Pillow missing: {exc}', file=sys.stderr)
    sys.exit(2)

if len(sys.argv) != 3:
    print('Usage: convert_image.py <source> <dest>', file=sys.stderr)
    sys.exit(1)

src = Path(sys.argv[1])
dst = Path(sys.argv[2])

if not src.exists():
    print('Source not found', file=sys.stderr)
    sys.exit(1)

try:
    with Image.open(src) as img:
        img = img.convert('RGB')
        img.save(dst, format='JPEG', quality=85)
except Exception as exc:
    print(str(exc), file=sys.stderr)
    sys.exit(1)
PY;
        }

        $python = self::find_python_binary();
        if ($python === null) {
            $error = 'Python3 missing';
            return null;
        }

        $script_file = tempnam(sys_get_temp_dir(), 'traxs_convert_') . '.py';
        if ($script_file === false) {
            $error = 'Cannot create script file';
            return null;
        }
        file_put_contents($script_file, $script);
        $temp_files[] = $script_file;

        $dest = tempnam(sys_get_temp_dir(), 'traxs_img_conv_') . '.jpg';
        if ($dest === false) {
            $error = 'Cannot create temp image';
            return null;
        }
        $temp_files[] = $dest;

        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script_file) . ' ' .
            escapeshellarg($source) . ' ' . escapeshellarg($dest);
        exec($cmd . ' 2>&1', $output, $code);
        if ($code !== 0) {
            $error = implode('; ', $output) ?: 'Python conversion failed';
            return null;
        }

        return $dest;
    }

    private static function find_python_binary(): ?string {
        static $cached = false;
        if ($cached !== false) {
            return $cached;
        }
        $candidates = ['python3', 'python'];
        foreach ($candidates as $bin) {
            $path = trim(@shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
            if ($path !== '') {
                $cached = $path;
                return $path;
            }
        }
        $cached = null;
        return null;
    }

    private static function render_order_footer(TCPDF $pdf, string $order_number, int $page_number, int $total_pages): void {
        $pdf->SetY(-10);
        $pdf->SetFont('dejavusans','',9);
        $footer = 'Work Order #' . $order_number . ' Page ' . $page_number . ' of ' . $total_pages;
        $pdf->Cell(0, 6, $footer, 0, 0, 'C');
    }

    private static function render_customer_table(TCPDF $pdf, array $context, array $margins): float {
        $bodyWidth = $pdf->getPageWidth() - $margins['left'] - $margins['right'];
        $colWidth = $bodyWidth / 2;
        $headerHeight = 8;
        $pdf->SetFont('dejavusans','B',10);
        $pdf->Cell($colWidth, $headerHeight, 'Billing', 1, 0, 'C', false);
        $pdf->Cell($colWidth, $headerHeight, 'Shipping', 1, 1, 'C', false);

        $y_content = $pdf->GetY();
        $billing_text = self::build_address_block($context, 'billing');
        $shipping_text = self::build_address_block($context, 'shipping');
        if ($billing_text === '') $billing_text = '-';
        if ($shipping_text === '') $shipping_text = '-';

        $contentHeight = max(
            $pdf->getStringHeight($colWidth, $billing_text),
            $pdf->getStringHeight($colWidth, $shipping_text)
        );
        $contentHeight = max($contentHeight, 18);

        $pdf->Rect($margins['left'], $y_content, $colWidth, $contentHeight);
        $pdf->Rect($margins['left'] + $colWidth, $y_content, $colWidth, $contentHeight);

        $pdf->SetFont('dejavusans','',9);
        $content_padding = 10 / 2.54; // 10px in mm (~10px ≈ 2.8mm)
        $content_width = $colWidth - ($content_padding * 2);
        $content_y = $y_content + $content_padding / 2;
        $pdf->SetXY($margins['left'] + $content_padding, $content_y);
        $pdf->MultiCell($content_width, 5, $billing_text, 0, 'L', false, 0);
        $pdf->SetXY($margins['left'] + $colWidth + $content_padding, $content_y);
        $pdf->MultiCell($content_width, 5, $shipping_text, 0, 'L', false, 1);
        $pdf->Ln(2);
        return $headerHeight + $contentHeight + 4;
    }

    private static function build_address_block(array $context, string $prefix): string {
        $lines = [];
        $addressKey = $prefix . '_address';
        $address = self::normalize_address_text($context[$addressKey] ?? '');
        if ($address !== '') {
            $lines[] = $address;
        }
        if ($prefix === 'billing') {
            $phone = trim($context['billing_phone'] ?? '');
            if ($phone !== '') {
                $lines[] = 'Phone: ' . $phone;
            }
            $email = trim($context['billing_email'] ?? '');
            if ($email !== '') {
                $lines[] = 'Email: ' . $email;
            }
        }
        return implode("\n", $lines);
    }

    private static function normalize_address_text(string $value): string {
        if ($value === '') return '';
        $value = str_replace(["\r\n", "\r", '<br/>', '<br>', '<br />'], "\n", $value);
        $value = strip_tags($value);
        return trim($value);
    }
}

class Traxs_Workorder_TCPDF extends TCPDF {
    private const DEFAULT_HEADER_CONTEXT = [
        'order_number'     => '',
        'po_label'         => '',
        'date_label'       => '',
        'logo_url'         => '',
        'billing_name'     => '',
        'billing_address'  => '',
        'billing_phone'    => '',
        'billing_email'    => '',
        'shipping_name'    => '',
        'shipping_address' => '',
    ];

    /**
     * @var array|null
     */
    private static $header_context = null;

    /**
     * Lowest y coordinate captured after rendering the latest header.
     * @var float
     */
    private static $header_bottom = 0.0;

    public static function setWorkorderHeaderContext(array $context): void {
        self::$header_context = array_merge(self::DEFAULT_HEADER_CONTEXT, $context);
    }

    public static function resetWorkorderHeaderContext(): void {
        self::$header_context = self::DEFAULT_HEADER_CONTEXT;
        self::$header_bottom  = 0.0;
    }

    public function Header(): void {
        if (self::$header_context === null) {
            self::resetWorkorderHeaderContext();
        }
        $context = self::$header_context;
        if (empty($context['order_number'])) {
            return;
        }
        Eject_Workorders::render_header(
            $this,
            $context['order_number'],
            $context['po_label'],
            $context['date_label'],
            $context['logo_url'],
            $context
        );
    }

    public function AddPage($orientation = '', $format = '', $keepmargins = false, $tocpage = false): void {
        parent::AddPage($orientation, $format, $keepmargins, $tocpage);
        self::ensureContentBelowHeader($this);
    }

    public static function setHeaderBottom(float $y): void {
        self::$header_bottom = $y;
    }

    public static function ensureContentBelowHeader(TCPDF $pdf): void {
        if (self::$header_bottom > 0 && $pdf->GetY() < self::$header_bottom) {
            $pdf->SetY(self::$header_bottom);
        }
    }

    public static function getHeaderBottom(): float {
        return self::$header_bottom;
    }

}
