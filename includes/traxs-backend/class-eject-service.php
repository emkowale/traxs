<?php
/*
 * File: includes/class-eject-service.php
 * Description: Core business logic for scanning on-hold orders and building vendor POs.
 */

if (!defined('ABSPATH')) exit;

class Eject_Service {
    private const SIZE_ORDER = ['NB','06M','12M','18M','24M','XS','S','M','L','XL','2XL','3XL','4XL','5XL'];

    private static function removed_meta_key(string $vendor_id): string {
        $vendor_id = preg_replace('/[^A-Za-z0-9_\-]/', '', $vendor_id);
        return '_eject_removed_' . strtolower($vendor_id);
    }

    private static function item_key(string $vendor_item, string $color, string $size): string {
        return strtolower(trim($vendor_item)) . '|' . strtolower(trim($color)) . '|' . strtolower(trim($size));
    }

    private static $vendor_item_cache = [];
    private static function vendor_line_exists_in_po(string $vendor_id, string $line_key): bool {
        $vendor_id = trim($vendor_id);
        if ($vendor_id === '') return false;
        if (!isset(self::$vendor_item_cache[$vendor_id])) {
            self::$vendor_item_cache[$vendor_id] = [];
            $pos = get_posts([
                'post_type'   => 'eject_po',
                'post_status' => ['publish','draft'],
                'numberposts' => -1,
                'fields'      => 'ids',
                'meta_query'  => [
                    ['key' => '_vendor_id', 'value' => $vendor_id],
                ],
            ]);
            foreach ($pos as $pid) {
                $items_raw = get_post_meta($pid, '_items', true);
                $items     = $items_raw ? json_decode($items_raw, true) : [];
                if (!is_array($items)) continue;
                foreach ($items as $it) {
                    $k = self::item_key($it['vendor_item'] ?? '', $it['color'] ?? '', $it['size'] ?? '');
                    if ($k !== '||') {
                        self::$vendor_item_cache[$vendor_id][$k] = true;
                    }
                }
            }
        }
        return !empty(self::$vendor_item_cache[$vendor_id][$line_key]);
    }

    /** Debug snapshot for admin UI. */
    public static function debug_snapshot(): array {
        $scan = self::scan_on_hold();
        $vendors = [];
        foreach ($scan['vendors'] as $vendor_id => $lines) {
            $list = [];
            foreach ($lines as $ln) {
                $list[] = [
                    'order' => $ln['order_id'] ?? '',
                    'item'  => self::item_key($ln['vendor_item_code'] ?? ($ln['product_name'] ?? ''), $ln['color'] ?? '', $ln['size'] ?? ''),
                    'vendor'=> $vendor_id,
                    'line'  => $ln,
                ];
            }
            $vendors[$vendor_id] = $list;
        }

        // Vendor cache contents
        $cache = [];
        $vendor_ids = array_keys($vendors);
        foreach ($vendor_ids as $vid) {
            // force build cache
            self::vendor_line_exists_in_po($vid, '__noop__');
            $cache[$vid] = array_keys(self::$vendor_item_cache[$vid] ?? []);
        }

        // Removed per order
        $removed = [];
        $orders = wc_get_orders([
            'status' => 'any',
            'limit'  => 50,
            'orderby'=> 'date',
            'order'  => 'DESC',
            'return' => 'objects',
        ]);
        foreach ($orders as $order) {
            $meta = [];
            foreach ($order->get_meta_data() as $md) {
                $k = $md->get_data()['key'];
                if (strpos($k, '_eject_removed_') === 0) {
                    $meta[$k] = $md->get_data()['value'];
                }
            }
            if (!empty($meta)) {
                $removed[$order->get_id()] = $meta;
            }
        }

        return [
            'scan'      => $scan,
            'vendors'   => $vendors,
            'po_cache'  => $cache,
            'removed'   => $removed,
            'pos'       => self::debug_pos(),
            'orders'    => self::debug_orders_state(),
            'missing'   => self::debug_missing_lines(),
        ];
    }

    private static function debug_pos(): array {
        $out = [];
        $pos = get_posts([
            'post_type'   => 'eject_po',
            'post_status' => ['publish','draft'],
            'numberposts' => -1,
        ]);
        foreach ($pos as $po) {
            $vid   = get_post_meta($po->ID, '_vendor_id', true);
            $num   = get_post_meta($po->ID, '_po_number', true);
            $items = get_post_meta($po->ID, '_items', true);
            $items = $items ? json_decode($items, true) : [];
            $lines = [];
            if (is_array($items)) {
                foreach ($items as $it) {
                    $lines[] = [
                        'key'   => self::item_key($it['vendor_item'] ?? '', $it['color'] ?? '', $it['size'] ?? ''),
                        'item'  => $it['vendor_item'] ?? '',
                        'color' => $it['color'] ?? '',
                        'size'  => $it['size'] ?? '',
                        'qty'   => $it['qty'] ?? 0,
                        'orders'=> $it['order_ids'] ?? [],
                    ];
                }
            }
            $out[] = [
                'id'     => $po->ID,
                'number' => $num,
                'vendor' => $vid,
                'ordered'=> get_post_meta($po->ID, '_ordered', true) ? true : false,
                'lines'  => $lines,
            ];
        }
        return $out;
    }

    private static function debug_orders_state(): array {
        $orders = wc_get_orders([
            'status' => ['on-hold','on-order','processing','completed','pending','cancelled','refunded','failed'],
            'limit'  => 50,
            'orderby'=> 'date',
            'order'  => 'DESC',
            'return' => 'objects',
        ]);
        $out = [];
        foreach ($orders as $o) {
            $meta = [];
            foreach ($o->get_meta_data() as $md) {
                $k = $md->get_data()['key'];
                if (strpos($k, '_eject_') === 0) {
                    $meta[$k] = $md->get_data()['value'];
                }
            }
            $out[] = [
                'id'     => $o->get_id(),
                'status' => $o->get_status(),
                'meta'   => $meta,
            ];
        }
        return $out;
    }

    private static function debug_missing_lines(): array {
        $orders = wc_get_orders([
            'status' => ['on-hold','on-order','processing','completed','pending','cancelled','refunded','failed'],
            'limit'  => 200,
            'orderby'=> 'date',
            'order'  => 'DESC',
            'return' => 'objects',
        ]);
        $missing = [];
        foreach ($orders as $order) {
            $oid = $order->get_id();
            foreach ($order->get_items('line_item') as $item_id => $item) {
                $product = $item->get_product();
                if ($product === false) $product = null;
                $vendor  = self::vendor_from_item($item, $product);
                $vendor_item = self::vendor_item_from_item($item, $product);
                if ($vendor === '' || $vendor_item === '') continue;
                $color   = self::color_from_item($item, $product);
                $size    = self::size_from_item($item, $product);
                $key     = self::item_key($vendor_item, $color, $size);
                $exists  = self::vendor_line_exists_in_po($vendor, $key);
                if (!$exists) {
                    $missing[] = [
                        'order'  => $oid,
                        'status' => $order->get_status(),
                        'vendor' => $vendor,
                        'item'   => $vendor_item,
                        'color'  => $color,
                        'size'   => $size,
                        'key'    => $key,
                        'has_vendor_meta' => $order->get_meta(self::vendor_order_meta_key($vendor)) ? true : false,
                    ];
                }
            }
        }
        return $missing;
    }

    /** Scan all on-hold orders and group line items by vendor. */
    public static function scan_on_hold(): array {
        $result = [
            'vendors' => [], // vendor_id => [line, line, ...]
            'skipped' => [], // items without vendor or already assigned
        ];

        $orders = wc_get_orders([
            'status'      => ['on-hold'],
            'limit'       => -1,
            'orderby'     => 'date',
            'order'       => 'ASC',
            'return'      => 'objects',
        ]);

        foreach ($orders as $order) {
            /** @var WC_Order $order */
            foreach ($order->get_items('line_item') as $item_id => $item) {
                $line = self::order_item_to_line($order, $item, $item_id);
                if (empty($line['vendor_id'])) {
                    $result['skipped'][] = $line + ['reason' => 'Missing vendor code'];
                    continue;
                }

                $vendor_key  = self::vendor_order_meta_key($line['vendor_id']);
                $removed_key = self::removed_meta_key($line['vendor_id']);
                $removed_list = (array) $order->get_meta($removed_key, true);

                $line_key = self::item_key($line['vendor_item_code'] ?: $line['product_name'], $line['color'], $line['size']);
                $allow_because_removed = !empty($removed_list) && in_array($line_key, $removed_list, true);

                if ($order->get_meta($vendor_key) && !$allow_because_removed) {
                    // If this exact line already exists in a PO for this vendor, skip. Otherwise allow.
                    if (self::vendor_line_exists_in_po($line['vendor_id'], $line_key)) {
                        $result['skipped'][] = $line + ['reason' => 'Already has a PO for this vendor'];
                        continue;
                    }
                }

                if (!isset($result['vendors'][$line['vendor_id']])) {
                    $result['vendors'][$line['vendor_id']] = [];
                }
                $result['vendors'][$line['vendor_id']][] = $line;
            }
        }

        return $result;
    }

    /** Build POs for all vendors found in on-hold orders. */
    public static function create_pos_from_on_hold(): array {
        $scan = self::scan_on_hold();
        $created = [];
        $run_id = 'run-' . date_i18n('Ymd-His') . '-' . wp_generate_password(4, false, false);

        foreach ($scan['vendors'] as $vendor_id => $lines) {
            if (empty($lines)) continue;

            $po_number = self::next_po_number($vendor_id);
            $po_title  = sprintf('Vendor %s PO %s', $vendor_id, date_i18n('M j, Y'));

            $po_id = wp_insert_post([
                'post_type'   => 'eject_po',
                'post_status' => 'publish',
                'post_title'  => $po_title,
            ], true);

            if (is_wp_error($po_id)) {
                $scan['skipped'][] = ['vendor_id' => $vendor_id, 'reason' => $po_id->get_error_message()];
                continue;
            }

            $grouped   = self::group_items($lines);
            $order_ids = array_values(array_unique(array_column($lines, 'order_id')));
            $total     = array_sum(array_map(function ($i) { return (float) $i['line_total']; }, $grouped));

            update_post_meta($po_id, '_vendor_id', $vendor_id);
            update_post_meta($po_id, '_po_number', $po_number);
            update_post_meta($po_id, '_po_date', date_i18n('Y-m-d'));
            update_post_meta($po_id, '_order_ids', $order_ids);
            update_post_meta($po_id, '_items', wp_json_encode($grouped));
            update_post_meta($po_id, '_total_cost', $total);
            update_post_meta($po_id, '_run_id', $run_id);

            self::attach_orders_to_po($order_ids, $vendor_id, $po_number, $po_id);

            $created[] = [
                'po_id'      => $po_id,
                'po_number'  => $po_number,
                'vendor'     => $vendor_id,
                'total'      => $total,
                'order_ids'  => $order_ids,
                'items'      => $grouped,
                'run_id'     => $run_id,
            ];
        }

        return ['created' => $created, 'skipped' => $scan['skipped']];
    }

    /** Move a PO to trash and clear its order markers. */
    public static function delete_po(int $po_id): array {
        $post = get_post($po_id);
        if (!$post || $post->post_type !== 'eject_po') {
            return ['success' => false, 'message' => 'Not a valid Eject PO'];
        }

        $vendor    = get_post_meta($po_id, '_vendor_id', true);
        $po_number = get_post_meta($po_id, '_po_number', true);
        $order_ids = (array) get_post_meta($po_id, '_order_ids', true);
        $ordered   = (bool) get_post_meta($po_id, '_ordered', true);

        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) continue;
            $meta_key = self::vendor_order_meta_key($vendor);
            $removed_key = self::removed_meta_key($vendor);
            $order->delete_meta_data($meta_key);
            $order->delete_meta_data($removed_key);
            if ($ordered) {
                $order->add_order_note(sprintf('Traxs: ordered PO %s deleted', $po_number ?: $po_id), false, true);
            }
            $order->save();
        }

        wp_trash_post($po_id);
        return ['success' => true, 'message' => 'PO removed'];
    }

    /** Build the next PO number in format BT-{vendorId}-{MMDDYYYY}-{###}. */
    public static function next_po_number(string $vendor_id): string {
        $vendor_id   = trim($vendor_id);
        $safe_vendor = preg_replace('/[^A-Za-z0-9]/', '', $vendor_id);
        $safe_vendor = $safe_vendor !== '' ? $safe_vendor : 'VENDOR';

        $date      = current_time('timestamp');
        $mmddyyyy  = date_i18n('mdY', $date);
        $po_date   = date_i18n('Y-m-d', $date);

        $existing = get_posts([
            'post_type'   => 'eject_po',
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                ['key' => '_vendor_id', 'value' => $vendor_id],
                ['key' => '_po_date',   'value' => $po_date],
            ],
        ]);

        $suffix = str_pad((string) (count($existing) + 1), 3, '0', STR_PAD_LEFT);
        return sprintf('BT-%s-%s-%s', $safe_vendor, $mmddyyyy, $suffix);
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------*/

    private static function order_item_to_line(WC_Order $order, WC_Order_Item_Product $item, int $item_id): array {
        $product = $item->get_product();

        $vendor_id   = self::vendor_from_item($item, $product);
        $vendor_item = self::vendor_item_from_item($item, $product);
        $color       = self::color_from_item($item, $product);
        $size        = self::size_from_item($item, $product);
        $cost        = self::cost_from_item($item, $product);
        $qty         = max(1, (int) $item->get_quantity());

        return [
            'order_id'        => (int) $order->get_id(),
            'order_number'    => (string) $order->get_order_number(),
            'order_edit_url'  => admin_url('post.php?post=' . $order->get_id() . '&action=edit'),
            'item_id'         => $item_id,
            'product_name'    => $item->get_name(),
            'vendor_id'       => $vendor_id,
            'vendor_item_code'=> $vendor_item,
            'vendor_code'     => self::vendor_code_from_item($item, $product),
            'sku'             => $product ? $product->get_sku() : '',
            'color'           => $color,
            'size'            => $size,
            'qty'             => $qty,
            'cost'            => $cost,
            'production'      => self::production_from_item($item, $product),
            'line_total'      => $cost * $qty,
        ];
    }

    public static function vendor_from_item(WC_Order_Item_Product $item, ?WC_Product $product): string {
        $from_filter = apply_filters('eject_vendor_from_item', '', $item, $product);
        if ($from_filter !== '') return (string) $from_filter;

        $keys = [
            'vendor_id',
            'vendor',
            'vendor_code',
            'vendor code',
            'vendorcode',
            'vendor id',
            'vendor number',
            'quality',        // legacy field name
            'quality code',   // legacy variant
        ];
        $vendor = self::first_meta_match($item, $product, $keys);
        if ($vendor !== '') {
            $parsed = self::parse_vendor_code((string) $vendor);
            return $parsed['vendor'] !== '' ? $parsed['vendor'] : (string) $vendor;
        }

        return '';
    }

    public static function vendor_item_from_item(WC_Order_Item_Product $item, ?WC_Product $product): string {
        $from_filter = apply_filters('eject_vendor_item_from_item', '', $item, $product);
        if ($from_filter !== '') return (string) $from_filter;

        $keys = ['vendor_item', 'vendor_item_code', 'vendor sku', 'vendor_sku', 'vendor_code', 'vendor code'];
        $vendor_item = self::first_meta_match($item, $product, $keys);

        if ($vendor_item !== '') {
            $parsed = self::parse_vendor_code((string) $vendor_item);
            if ($parsed['item'] !== '') return $parsed['item'];
            return (string) $vendor_item;
        }

        // If vendor code is stored in the vendor field, parse the item portion.
        $vendor_code = self::first_meta_match($item, $product, ['vendor_id','vendor','vendor_code','vendor code','quality','quality code','vendorcode']);
        if ($vendor_code !== '') {
            $parsed = self::parse_vendor_code((string) $vendor_code);
            if ($parsed['item'] !== '') return $parsed['item'];
        }

        if ($product && $product->get_sku()) return (string) $product->get_sku();
        return $item->get_name();
    }

    public static function vendor_code_from_item(WC_Order_Item_Product $item, ?WC_Product $product): string {
        $order_keys = ['Vendor Code', 'vendor_code'];
        foreach ($order_keys as $key) {
            $value = $item->get_meta($key, true);
            $value = self::normalize_vendor_code_value($value);
                if ($value !== '') {
                    return $value;
                }
            }

        if ($product instanceof WC_Product) {
            foreach ($order_keys as $key) {
                $value = $product->get_meta($key, true);
                $value = self::normalize_vendor_code_value($value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private static function normalize_vendor_code_value($value): string {
        if (!is_scalar($value)) {
            return '';
        }
        return trim((string) $value);
    }

    private static function color_from_item(WC_Order_Item_Product $item, ?WC_Product $product): string {
        // Prefer order item meta (variation) over product attributes.
        $keys = ['attribute_pa_color','pa_color','color','Color','attribute_color'];
        $meta_color = self::first_meta_match($item, $product, $keys);
        if ($meta_color !== '') return (string) $meta_color;

        // Search any meta key containing "color"
        $meta_color = self::first_meta_contains($item, $product, 'color');
        if ($meta_color !== '') return (string) $meta_color;

        // Last resort: product attributes, but avoid returning comma-joined options.
        if ($product && method_exists($product, 'get_attributes')) {
            foreach ($product->get_attributes() as $key => $val) {
                $label = is_object($val) && method_exists($val, 'get_name') ? $val->get_name() : $key;
                if (stripos((string) $label, 'color') !== false) {
                    $value = self::attr_value_to_string($val, $product);
                    if ($value !== '' && strpos($value, ',') === false) return $value;
                }
            }
        }

        return '';
    }

    private static function size_from_item(WC_Order_Item_Product $item, ?WC_Product $product): string {
        // Prefer order item meta (variation) over product attributes.
        $keys = ['attribute_pa_size','pa_size','size','Size','attribute_size'];
        $meta_size = self::first_meta_match($item, $product, $keys);
        if ($meta_size !== '') return (string) $meta_size;

        // Search any meta key containing "size"
        $meta_size = self::first_meta_contains($item, $product, 'size');
        if ($meta_size !== '') return (string) $meta_size;

        // Last resort: product attributes, but avoid returning comma-joined options.
        if ($product && method_exists($product, 'get_attributes')) {
            foreach ($product->get_attributes() as $key => $val) {
                $label = is_object($val) && method_exists($val, 'get_name') ? $val->get_name() : $key;
                if (stripos((string) $label, 'size') !== false) {
                    $value = self::attr_value_to_string($val, $product);
                    if ($value !== '' && strpos($value, ',') === false) return $value;
                }
            }
        }

        return '';
    }

    private static function production_from_item(WC_Order_Item_Product $item, ?WC_Product $product): string {
        $keys = ['Production', 'production', 'production_type'];
        $value = self::first_meta_match($item, $product, $keys);
        if ($value !== '') {
            return (string) $value;
        }
        return '';
    }

    /** Normalize attribute value objects/arrays to a readable string. */
    private static function attr_value_to_string($attr, ?WC_Product $product): string {
        if (is_object($attr) && method_exists($attr, 'get_options')) {
            $opts = $attr->get_options();

            if (method_exists($attr, 'is_taxonomy') && $attr->is_taxonomy() && $product) {
                $names = wc_get_product_terms($product->get_id(), $attr->get_name(), ['fields' => 'names']);
                if (!empty($names)) return implode(', ', $names);
            }

            return is_array($opts) ? implode(', ', array_map('strval', $opts)) : (string) $opts;
        }

        if (is_array($attr)) return implode(', ', array_map('strval', $attr));
        if ($attr === null) return '';
        return (string) $attr;
    }

    /** Find first meta where key contains needle (case-insensitive). */
    private static function first_meta_contains(WC_Order_Item_Product $item, ?WC_Product $product, string $needle) {
        $needle = strtolower($needle);

        foreach ($item->get_meta_data() as $meta) {
            $data = $meta->get_data();
            $k = strtolower($data['key'] ?? '');
            if ($k !== '' && strpos($k, $needle) !== false && $data['value'] !== '') {
                return $data['value'];
            }
        }

        if ($product) {
            foreach ($product->get_meta_data() as $meta) {
                $data = $meta->get_data();
                $k = strtolower($data['key'] ?? '');
                if ($k !== '' && strpos($k, $needle) !== false && $data['value'] !== '') {
                    return $data['value'];
                }
            }
        }

        return '';
    }

    /** Numeric version of first_meta_contains (returns numeric-ish string or empty). */
    private static function first_meta_contains_numeric(WC_Order_Item_Product $item, ?WC_Product $product, array $needles): string {
        $metas = [];

        foreach ($item->get_meta_data() as $meta) {
            $data = $meta->get_data();
            $metas[strtolower($data['key'] ?? '')] = $data['value'] ?? '';
        }
        if ($product) {
            foreach ($product->get_meta_data() as $meta) {
                $data = $meta->get_data();
                $metas[strtolower($data['key'] ?? '')] = $data['value'] ?? '';
            }
        }

        foreach ($needles as $n) {
            $n = strtolower($n);
            foreach ($metas as $k => $v) {
                if ($k !== '' && strpos($k, $n) !== false && $v !== '') {
                    if (is_array($v) || is_object($v)) continue;
                    return (string)$v;
                }
            }
        }
        return '';
    }

    /**
     * Parse vendor code strings like "SanMar(PC54)" into vendor + item parts.
     */
    private static function parse_vendor_code(string $raw): array {
        $vendor = trim((string) $raw);
        $item   = '';

        if (preg_match('/^([^()]+)\(([^)]+)\)/', $vendor, $m)) {
            $vendor = trim($m[1]);
            $item   = trim($m[2]);
        }

        return ['vendor' => $vendor, 'item' => $item];
    }

    private static function cost_from_item(WC_Order_Item_Product $item, ?WC_Product $product): float {
        $trace = [];
        $cost = self::cost_from_item_internal($item, $product, $trace);
        return $cost;
    }

    /**
     * Debug helper: return cost plus trace of how it was derived.
     */
    public static function cost_from_item_debug(WC_Order_Item_Product $item, ?WC_Product $product): array {
        $trace = [];
        $cost = self::cost_from_item_internal($item, $product, $trace);
        return ['cost' => $cost, 'trace' => $trace];
    }

    private static function cost_from_item_internal(WC_Order_Item_Product $item, ?WC_Product $product, array &$trace): float {
        $from_filter = apply_filters('eject_cost_from_item', null, $item, $product);
        if ($from_filter !== null) {
            $trace[] = 'filter:' . $from_filter;
            return (float) $from_filter;
        }

        // Exact key lookups on order item and product meta
        $keys = ['vendor_cost', '_vendor_cost', 'vendor_price', '_vendor_price', 'cost'];
        $cost = self::first_meta_match($item, $product, $keys);
        if ($cost !== '') {
            $trace[] = 'meta_exact:' . $cost;
            return self::normalize_price($cost);
        }

        // Any meta key containing these fragments (both item and product meta)
        $cost = self::first_meta_contains_numeric($item, $product, [
            'vendor_cost', 'vendor cost', 'vendor price',
            'huffer', 'huffer_cost', 'huffer price', 'huffer_price',
            'cost', 'price',
        ]);
        if ($cost !== '') {
            $trace[] = 'meta_contains:' . $cost;
            return self::normalize_price($cost);
        }

        // Pull from Huffer material costs table if available.
        if (function_exists('huffer_material_cost')) {
            $trace[] = 'huffer_func:yes';
            $quality = $item->get_meta('vendor_code', true);
            if (!$quality) $quality = $item->get_meta('Vendor Code', true);
            if (!$quality) $quality = $item->get_meta('vendor code', true);
            if (!$quality && $product) {
                $quality = $product->get_meta('vendor_code', true);
                if (!$quality) $quality = $product->get_meta('Vendor Code', true);
            }
            // If we still don't have a quality string, build one from vendor + item.
            $vendor_id   = self::vendor_from_item($item, $product);
            $vendor_item = self::vendor_item_from_item($item, $product);
            if (!$quality && $vendor_id && $vendor_item) {
                $quality = sprintf('%s(%s)', $vendor_id, $vendor_item);
            }
            $trace[] = 'huffer_quality:' . ($quality ?: 'none');
            if ($quality) {
                $h_cost = huffer_material_cost($quality);
                $trace[] = 'huffer_cost:' . $h_cost;
                if ($h_cost > 0) return (float) $h_cost;
            }
        } else {
            $trace[] = 'huffer_func:no';
        }

        // Fallback to product prices (regular/sale/current)
        if ($product) {
            $p_costs = [
                $product->get_meta('vendor_cost', true),
                $product->get_meta('huffer_cost', true),
                $product->get_price(),
                $product->get_regular_price(),
                $product->get_sale_price(),
            ];
            foreach ($p_costs as $p) {
                if ($p !== '' && $p !== null) {
                    $n = self::normalize_price($p);
                    if ($n > 0) {
                        $trace[] = 'product_price:' . $n;
                        return $n;
                    }
                }
            }
        }

        // Last resort: order item subtotal/total ÷ qty
        $qty = max(1, (int)$item->get_quantity());
        $subtotal = method_exists($item, 'get_subtotal') ? (float)$item->get_subtotal() : 0.0;
        $total    = method_exists($item, 'get_total') ? (float)$item->get_total() : 0.0;
        if ($subtotal > 0 && $qty > 0) {
            $trace[] = 'subtotal:' . $subtotal;
            return $subtotal / $qty;
        }
        if ($total > 0 && $qty > 0) {
            $trace[] = 'total:' . $total;
            return $total / $qty;
        }

        $trace[] = 'cost:0';
        return 0.0;
    }

    /** Public helper to expose current cost calculation (used for debug/live totals). */
    public static function recalc_cost_for_item(WC_Order_Item_Product $item, ?WC_Product $product): float {
        return self::cost_from_item($item, $product);
    }

    private static function normalize_price($value): float {
        if (is_array($value) || is_object($value)) return 0.0;
        if (is_numeric($value)) return (float) $value;
        $clean = preg_replace('/[^0-9\.\-]/', '', (string) $value);
        return (float) $clean;
    }

    private static function first_meta_match(WC_Order_Item_Product $item, ?WC_Product $product, array $keys) {
        $lower_keys = array_map('strtolower', $keys);

        foreach ($lower_keys as $key) {
            $val = $item->get_meta($key, true);
            if ($val !== '') return $val;
        }

        $meta_map = [];
        foreach ($item->get_meta_data() as $meta) {
            $data = $meta->get_data();
            $meta_map[strtolower($data['key'])] = $data['value'];
        }
        foreach ($lower_keys as $key) {
            if (isset($meta_map[$key]) && $meta_map[$key] !== '') return $meta_map[$key];
        }

        if ($product) {
            foreach ($lower_keys as $key) {
                $val = $product->get_meta($key, true);
                if ($val !== '') return $val;
            }
        }

        return '';
    }

    /** Attach meta to link orders to a PO (notes added only when ordered/deleted). */
    private static function attach_orders_to_po(array $order_ids, string $vendor_id, string $po_number, int $po_id): void {
        $meta_key    = self::vendor_order_meta_key($vendor_id);
        $removed_key = self::removed_meta_key($vendor_id);

        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) continue;
            if (!$order->get_meta($meta_key)) {
                $order->update_meta_data($meta_key, $po_number);
            }
            $order->delete_meta_data($removed_key); // consumed removed items
            $order->save();
        }
    }

    /**
     * Mark a PO as ordered and update order statuses if all POs for an order are ordered.
     */
    public static function mark_po_ordered(int $po_id, bool $has_unordered = false, array $keep_items = []): array {
        $post = get_post($po_id);
        if (!$post || $post->post_type !== 'eject_po') {
            return ['success' => false, 'message' => 'Not a valid Eject PO'];
        }

        // If a subset of items should be kept, prune the rest back to On-Hold.
        if (!empty($keep_items)) {
            $items_raw = get_post_meta($po_id, '_items', true);
            $items     = $items_raw ? json_decode($items_raw, true) : [];
            if (!is_array($items)) $items = [];

            $keep_keys = [];
            foreach ($keep_items as $it) {
                $code  = strtolower(trim($it['code'] ?? ''));
                $color = strtolower(trim($it['color'] ?? ''));
                $size  = strtolower(trim($it['size'] ?? ''));
                if ($code === '' || $color === '' || $size === '') continue;
                $keep_keys[] = $code . '|' . $color . '|' . $size;
            }
            $keep_keys = array_values(array_unique($keep_keys));

            $to_remove = [];
            foreach ($items as $it) {
                $code  = strtolower(trim($it['vendor_item'] ?? ''));
                $color = strtolower(trim($it['color'] ?? ''));
                $size  = strtolower(trim($it['size'] ?? ''));
                $key   = $code . '|' . $color . '|' . $size;
                if (!in_array($key, $keep_keys, true)) {
                    $to_remove[] = ['code' => $code, 'color' => $color, 'size' => $size];
                }
            }

            if (count($to_remove) === count($items)) {
                return ['success' => false, 'message' => 'No items selected to order'];
            }

            if (!empty($to_remove)) {
                $prune = self::prune_po_items($po_id, $to_remove);
                if (empty($prune['success'])) {
                    return ['success' => false, 'message' => $prune['message'] ?? 'Failed to remove unchecked items'];
                }
                $has_unordered = true; // there were unchecked items sent back
            }
        }

        update_post_meta($po_id, '_ordered', 1);
        update_post_meta($po_id, '_ordered_at', current_time('mysql'));
        update_post_meta($po_id, '_has_unordered', $has_unordered ? 1 : 0);

        $order_ids = (array) get_post_meta($po_id, '_order_ids', true);
        $order_ids = array_filter(array_map('intval', $order_ids));
        $po_number = get_post_meta($po_id, '_po_number', true) ?: $po_id;
        $vendor    = get_post_meta($po_id, '_vendor_id', true);
        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if ($order) {
                $order->add_order_note(sprintf('Traxs: PO %s ordered%s', $po_number, $vendor ? (' for '.$vendor) : ''), false, true);
                $order->save();
            }
            self::maybe_set_order_on_order($oid);
        }

        return ['success' => true, 'po_id' => $po_id];
    }

    private static function maybe_set_order_on_order(int $order_id): void {
            $order = wc_get_order($order_id);
            if (!$order) return;

            // Find all POs that reference this order.
            $pos = get_posts([
                'post_type'   => 'eject_po',
            'post_status' => ['publish','draft'],
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                [
                    'key'     => '_order_ids',
                    'value'   => '"' . $order_id . '"',
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        $all_ordered = true;
        foreach ($pos as $pid) {
            $ordered = get_post_meta($pid, '_ordered', true);
            $has_unordered = get_post_meta($pid, '_has_unordered', true);
            if (!$ordered || $has_unordered) { $all_ordered = false; break; }
        }

            if ($all_ordered) {
                // Move to custom "on-order" status.
                if ($order->get_status() !== 'on-order') {
                    $order->update_status('on-order', 'Traxs: all vendor POs ordered.');
                }
            } else {
                // Leave as-is (likely on-hold). Do not change status.
            }
    }

    /**
     * Remove specific items (vendor_item/color/size combos) from a PO and update linked orders.
     */
    public static function prune_po_items(int $po_id, array $to_remove): array {
        $post = get_post($po_id);
        if (!$post || $post->post_type !== 'eject_po') {
            return ['success' => false, 'message' => 'Not a valid PO'];
        }
        $items_raw = get_post_meta($po_id, '_items', true);
        $items     = $items_raw ? json_decode($items_raw, true) : [];
        if (!is_array($items)) $items = [];
        $ordered   = (bool) get_post_meta($po_id, '_ordered', true);
        $po_number = get_post_meta($po_id, '_po_number', true) ?: $po_id;
        $vendor    = get_post_meta($po_id, '_vendor_id', true);

        $remove_keys = [];
        foreach ($to_remove as $r) {
            $code  = strtolower(trim($r['code'] ?? ''));
            $color = strtolower(trim($r['color'] ?? ''));
            $size  = strtolower(trim($r['size'] ?? ''));
            if ($code === '' || $color === '' || $size === '') continue;
            $remove_keys[] = $code . '|' . $color . '|' . $size;
        }
        if (empty($remove_keys)) return ['success' => false, 'message' => 'Nothing to remove'];

        $kept = [];
        $removed_by_order = [];
        foreach ($items as $it) {
            $code  = strtolower(trim($it['vendor_item'] ?? ''));
            $color = strtolower(trim($it['color'] ?? ''));
            $size  = strtolower(trim($it['size'] ?? ''));
            $key   = $code . '|' . $color . '|' . $size;
            if (in_array($key, $remove_keys, true)) {
                if (!empty($it['order_qty']) && is_array($it['order_qty'])) {
                    foreach ($it['order_qty'] as $oid => $oq) {
                        $oid = (int)$oid;
                        if (!isset($removed_by_order[$oid])) $removed_by_order[$oid] = [];
                        $removed_by_order[$oid][] = $key;
                    }
                } elseif (!empty($it['order_ids']) && is_array($it['order_ids'])) {
                    foreach ($it['order_ids'] as $oid) {
                        $oid = (int)$oid;
                        if (!isset($removed_by_order[$oid])) $removed_by_order[$oid] = [];
                        $removed_by_order[$oid][] = $key;
                    }
                }
                continue; // drop it
            }
            $kept[] = $it;
        }

        // Recompute totals and order associations
        $total_cost = 0.0;
        $order_usage = [];
        foreach ($kept as $it) {
            $qty   = isset($it['qty']) ? (int)$it['qty'] : 0;
            $unit  = isset($it['unit_cost']) ? (float)$it['unit_cost'] : 0.0;
            $total_cost += $qty * $unit;
            if (!empty($it['order_qty']) && is_array($it['order_qty'])) {
                foreach ($it['order_qty'] as $oid => $oqty) {
                    $oid = (int)$oid;
                    $order_usage[$oid] = ($order_usage[$oid] ?? 0) + (int)$oqty;
                }
            } elseif (!empty($it['order_ids']) && is_array($it['order_ids'])) {
                foreach ($it['order_ids'] as $oid) {
                    $order_usage[(int)$oid] = ($order_usage[(int)$oid] ?? 0) + $qty;
                }
            }
        }
        $order_ids = array_keys($order_usage);

        update_post_meta($po_id, '_items', wp_json_encode(array_values($kept)));
        update_post_meta($po_id, '_total_cost', $total_cost);
        update_post_meta($po_id, '_order_ids', $order_ids);

        // Remove meta from orders no longer covered by this PO and set to on-hold
        $vendor_id = get_post_meta($po_id, '_vendor_id', true);
        $po_number = get_post_meta($po_id, '_po_number', true) ?: $po_id;
        $meta_key  = self::vendor_order_meta_key($vendor_id);

        // Find previously linked orders
        $prev_orders = (array) get_post_meta($po_id, '_order_ids', true);
        // But we just overwrote _order_ids; grab previously stored via items_raw
        if (is_array($items)) {
            foreach ($items as $it) {
                if (!empty($it['order_ids']) && is_array($it['order_ids'])) {
                    foreach ($it['order_ids'] as $oid) {
                        $prev_orders[] = (int)$oid;
                    }
                }
                if (!empty($it['order_qty']) && is_array($it['order_qty'])) {
                    foreach ($it['order_qty'] as $oid => $oq) {
                        $prev_orders[] = (int)$oid;
                    }
                }
            }
        }
        $prev_orders = array_values(array_unique($prev_orders));

        foreach ($prev_orders as $oid) {
            $order = wc_get_order($oid);
            if (!$order) continue;
            $still_used = isset($order_usage[$oid]) && $order_usage[$oid] > 0;
            $removed_key = self::removed_meta_key($vendor_id);
            if (!$still_used) {
                $order->delete_meta_data($meta_key);
                $order->delete_meta_data($removed_key);
                if ($order->get_status() !== 'on-hold') {
                    if ($ordered) {
                        $order->update_status('on-hold', 'Traxs: items removed from PO '.$po_number);
                    } else {
                        $order->set_status('on-hold');
                    }
                }
                if ($ordered) {
                    $order->add_order_note(sprintf('Traxs: items removed from ordered PO %s', $po_number), false, true);
                }
                $order->save();
            } else {
                if (!empty($removed_by_order[$oid])) {
                    $current_removed = (array) $order->get_meta($removed_key, true);
                    $current_removed = array_values(array_unique(array_merge($current_removed, $removed_by_order[$oid])));
                    $order->update_meta_data($removed_key, $current_removed);
                }
                if ($order->get_status() !== 'on-hold') {
                    if ($ordered) {
                        $order->update_status('on-hold', 'Traxs: some items removed from PO '.$po_number);
                    } else {
                        $order->set_status('on-hold');
                    }
                }
                if ($ordered) {
                    $order->add_order_note(sprintf('Traxs: some items removed from ordered PO %s', $po_number), false, true);
                }
                $order->save();
            }
        }

        return [
            'success'      => true,
            'po_id'        => $po_id,
            'total_cost'   => $total_cost,
            'total_items'  => array_sum(array_map(function($it){ return (int)($it['qty'] ?? 0); }, $kept)),
            'remaining'    => count($kept),
        ];
    }

    /** Group items by vendor_item|color|size and apply size ordering. */
    private static function group_items(array $lines): array {
        $bucket = [];

        foreach ($lines as $line) {
            $code  = $line['vendor_item_code'] ?: $line['product_name'];
            $color = $line['color'] ?: 'N/A';
            $size  = $line['size']  ?: 'N/A';
            $key   = strtolower($code . '|' . $color . '|' . $size);

            if (!isset($bucket[$key])) {
            $bucket[$key] = [
                    'vendor_item' => $code,
                    'product'     => $line['product_name'],
                    'color'       => $color,
                    'size'        => $size,
                    'qty'         => 0,
                    'unit_cost'   => $line['cost'],
                    'line_total'  => 0,
                    'order_ids'   => [],
                    'order_qty'   => [],
                ];
            }

            $bucket[$key]['qty']       += (int) $line['qty'];
            $bucket[$key]['line_total'] = $bucket[$key]['qty'] * (float) $bucket[$key]['unit_cost'];
            $bucket[$key]['order_ids'][] = (int) $line['order_id'];
            $oid = (int) $line['order_id'];
            $bucket[$key]['order_qty'][$oid] = ($bucket[$key]['order_qty'][$oid] ?? 0) + (int) $line['qty'];
        }

        $items = array_values($bucket);
        foreach ($items as &$item) {
            $item['order_ids'] = array_values(array_unique($item['order_ids']));
        }

        usort($items, function ($a, $b) {
            $code = strcmp(strtolower($a['vendor_item']), strtolower($b['vendor_item']));
            if ($code !== 0) return $code;

            $color = strcmp(strtolower($a['color']), strtolower($b['color']));
            if ($color !== 0) return $color;

            return self::size_sort_value($a['size']) <=> self::size_sort_value($b['size']);
        });

        return $items;
    }

    private static function size_sort_value(string $size): int {
        $size = strtoupper(trim($size));
        $index = array_search($size, self::SIZE_ORDER, true);
        return $index === false ? 999 : $index;
    }

    /**
     * Recalculate a PO's items and total using current vendor logic and Huffer costs.
     */
    public static function recalc_po(int $po_id): ?array {
        $post = get_post($po_id);
        if (!$post || $post->post_type !== 'eject_po') return null;

        $vendor_id = get_post_meta($po_id, '_vendor_id', true);
        $order_ids = (array) get_post_meta($po_id, '_order_ids', true);
        $order_ids = array_filter(array_map('intval', $order_ids));

        $lines = [];
        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) continue;
            foreach ($order->get_items('line_item') as $item_id => $item) {
                $product = $item->get_product();
                $v = self::vendor_from_item($item, $product);
                if ($vendor_id && $v !== $vendor_id) continue;
                $lines[] = self::order_item_to_line($order, $item, $item_id);
            }
        }

        $grouped = self::group_items($lines);
        $total   = array_sum(array_map(function ($i) { return (float) ($i['line_total'] ?? 0); }, $grouped));
        $total_items = array_sum(array_map(function ($i) { return (int) ($i['qty'] ?? 0); }, $grouped));

        update_post_meta($po_id, '_items', wp_json_encode($grouped));
        update_post_meta($po_id, '_total_cost', $total);

        return [
            'po_id'       => $po_id,
            'po_number'   => get_post_meta($po_id, '_po_number', true) ?: $po_id,
            'vendor'      => $vendor_id,
            'total_cost'  => $total,
            'total_items' => $total_items,
        ];
    }

    /** Return parsed lines for a specific order, optionally filtered by vendors */
    public static function lines_for_order(WC_Order $order, array $allowed_vendors = []): array {
        $out = [];
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $product = $item->get_product();
            $vendor_id = self::vendor_from_item($item, $product);
            if ($allowed_vendors && !in_array($vendor_id, $allowed_vendors, true)) continue;

            $vendor_item = self::vendor_item_from_item($item, $product);
            $color = self::color_from_item($item, $product);
            $size  = self::size_from_item($item, $product);
            $qty   = max(1, (int) $item->get_quantity());

            $out[] = [
                'order_id'        => (int) $order->get_id(),
                'order_number'    => (string) $order->get_order_number(),
                'item_id'         => $item_id,
                'product_name'    => $item->get_name(),
                'vendor_id'       => $vendor_id,
                'vendor_item_code'=> $vendor_item,
                'vendor_code'     => self::vendor_code_from_item($item, $product),
                'production'      => self::production_from_item($item, $product),
                'color'           => $color,
                'size'            => $size,
                'qty'             => $qty,
            ];
        }
        return $out;
    }

    private static function vendor_order_meta_key(string $vendor_id): string {
        return '_eject_vendor_po_' . sanitize_key($vendor_id ?: 'vendor');
    }

    /**
     * Emergency cleanup: remove duplicate/empty POs and reset their orders back to On Hold.
     * Targeted for the current production incident where items were duplicated into "-002" POs.
     */
    public static function emergency_cleanup(): array {
        $deleted_pos   = [];
        $orders_reset  = [];

        $pos = get_posts([
            'post_type'   => 'eject_po',
            'post_status' => ['publish','draft'],
            'numberposts' => -1,
        ]);

        foreach ($pos as $po) {
            $po_id     = $po->ID;
            $po_number = get_post_meta($po_id, '_po_number', true);
            $vendor    = get_post_meta($po_id, '_vendor_id', true);
            $ordered   = get_post_meta($po_id, '_ordered', true) ? true : false;
            $items_raw = get_post_meta($po_id, '_items', true);
            $items     = $items_raw ? json_decode($items_raw, true) : [];
            $items     = is_array($items) ? $items : [];

            // Delete any un-ordered PO that is empty or is one of the duplicate "-002" runs for 12/05/2025.
            $should_delete = !$ordered && (empty($items) || (is_string($po_number) && strpos($po_number, '12052025-002') !== false));
            if (!$should_delete) continue;

            $order_ids = (array) get_post_meta($po_id, '_order_ids', true);
            foreach ($order_ids as $oid) {
                $order = wc_get_order((int) $oid);
                if (!$order) continue;
                $meta_key    = self::vendor_order_meta_key($vendor);
                $removed_key = self::removed_meta_key($vendor);

                $order->delete_meta_data($meta_key);
                $order->delete_meta_data($removed_key);
                if ($order->get_status() !== 'on-hold') {
                    $order->set_status('on-hold');
                }
                $order->save();
                $orders_reset[] = (int) $order->get_id();
            }

            wp_trash_post($po_id);
            $deleted_pos[] = ['id' => $po_id, 'number' => $po_number ?: (string) $po_id];
        }

        return [
            'success'       => true,
            'deleted_pos'   => $deleted_pos,
            'orders_reset'  => array_values(array_unique($orders_reset)),
            'message'       => sprintf('Deleted %d POs and reset %d orders to on-hold', count($deleted_pos), count(array_unique($orders_reset))),
        ];
    }
}
