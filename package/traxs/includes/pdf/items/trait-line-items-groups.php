<?php
namespace Traxs;

use WC_Order_Item_Product;
use WC_Product;

if (!defined('ABSPATH')) exit;

trait WorkOrder_Items_Groups {

    /**
     * Build grouped line items for the line-items table.
     * Groups by parent/base SKU only (same design across colors/sizes).
     */
    protected function groupLineItems(array $sizeCols): array {
        $groups    = [];
        $orderKeys = [];
        $assetSeen = []; // [baseSku][assetKey] => true

        foreach ($this->order->get_items('line_item') as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            // --- Group key: parent/base SKU only ---
            $baseSku = $this->getBaseSkuFromItem($item);
            if ($baseSku === '') {
                // Fallback so items without SKU still group deterministically
                $baseSku = 'NO-SKU-' . $item->get_id();
            }
            $key = $baseSku;

            $production = trim((string) $item->get_meta('Production', true));
            $vendorCode = trim((string) ($item->get_meta('Vendor Code', true) ?: $item->get_meta('vendor_code', true)));
            $color      = trim((string) ($item->get_meta('Color', true) ?: $item->get_meta('pa_color', true)));

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'base_sku'     => $baseSku,
                    'production'   => $production,
                    'vendor_code'  => $vendorCode,
                    'color'        => $color,
                    'descriptions' => [],
                    'description'  => '',
                    'sizes'        => array_fill_keys($sizeCols, 0),
                    'items_total'  => 0,
                    'assets'       => [],
                ];
                $assetSeen[$baseSku] = [];
                $orderKeys[]         = $key;
            } else {
                // Fill in missing header fields from later items
                if ($groups[$key]['production'] === '' && $production !== '') {
                    $groups[$key]['production'] = $production;
                }
                if ($groups[$key]['vendor_code'] === '' && $vendorCode !== '') {
                    $groups[$key]['vendor_code'] = $vendorCode;
                }
                if ($groups[$key]['color'] === '' && $color !== '') {
                    $groups[$key]['color'] = $color;
                }
            }

            // ---------- Size / quantity aggregation ----------
            $desc    = $item->get_name();
            $sizeRaw = (string) ($item->get_meta('Size', true) ?: $item->get_meta('pa_size', true));
            $sizeKey = strtoupper(trim($sizeRaw));
            $qty     = (int) $item->get_quantity();
            if ($qty < 0) {
                $qty = 0;
            }

            if ($sizeKey !== '' && isset($groups[$key]['sizes'][$sizeKey])) {
                $groups[$key]['sizes'][$sizeKey] += $qty;
                $groups[$key]['items_total']     += $qty;
            } elseif ($sizeRaw !== '') {
                // Size doesn't map to a column; append to description
                $desc .= sprintf(' (%s x %d)', $sizeRaw, $qty);
            }

            // De-duplicate description lines within this base SKU
            if (!in_array($desc, $groups[$key]['descriptions'], true)) {
                $groups[$key]['descriptions'][] = $desc;
            }
            $groups[$key]['description'] = implode("\n", $groups[$key]['descriptions']);

            // ---------- Asset collection ----------
            $thumb = $this->resolveItemThumbnailUrl($item);
            $arts  = $this->resolveItemArtLocations($item); // array of URLs (can be SVG/EPS/etc)

            // If there is no art at all, still keep a thumb-only asset once.
            if (empty($arts)) {
                $assetKey = $thumb;
                if ($assetKey !== '' && isset($assetSeen[$baseSku][$assetKey])) {
                    continue;
                }
                if ($assetKey !== '') {
                    $assetSeen[$baseSku][$assetKey] = true;
                }

                $groups[$key]['assets'][] = [
                    'name'      => $item->get_name(),
                    'thumbnail' => $thumb,
                    'art'       => '',
                ];
                continue;
            }

            // One asset per art URL so each print location can render
            foreach ($arts as $artUrl) {
                $assetKey = $artUrl !== '' ? $artUrl : $thumb;
                if ($assetKey !== '' && isset($assetSeen[$baseSku][$assetKey])) {
                    continue;
                }
                if ($assetKey !== '') {
                    $assetSeen[$baseSku][$assetKey] = true;
                }

                $groups[$key]['assets'][] = [
                    'name'      => $item->get_name(),
                    'thumbnail' => $thumb,
                    'art'       => $artUrl,
                ];
            }
        }

        // Preserve original encounter order of base SKUs
        $ordered = [];
        foreach ($orderKeys as $k) {
            $ordered[] = $groups[$k];
        }

        return $ordered;
    }

    /**
     * Parent/base SKU:
     *   "blahblah-123-123"  => "blahblah-123"
     *   otherwise returns full SKU.
     */
    protected function getBaseSkuFromItem(WC_Order_Item_Product $item): string {
        $product = $item->get_product();
        if (!$product instanceof WC_Product) {
            return '';
        }

        $sku = trim((string) $product->get_sku());
        if ($sku === '') {
            return '';
        }

        $parts = explode('-', $sku);
        return (count($parts) < 3)
            ? $sku
            : ($parts[0] . '-' . $parts[1]);
    }

    /**
     * Collect all art URLs for this item:
     * explicit print-location keys + generic "Original Art" style keys + meta scan.
     */
    protected function resolveItemArtLocations(WC_Order_Item_Product $item): array {
        $paths = [];

        // Explicit print locations
        $fixedKeys = [
            'Original Art Front',
            'Original Art Back',
            'Original Art Left Chest',
            'Original Art Right Chest',
            'Original Art Left Sleeve',
            'Original Art Right Sleeve',
        ];
        foreach ($fixedKeys as $key) {
            $v = $this->getMetaString($item, $key);
            if ($v !== '' && $this->looksLikeArtPath($v)) {
                $paths[] = $v;
            }
        }

        // Generic / legacy keys
        $genericKeys = [
            'Original Art {Print Location}',
            'Original Art',
            'original_art_print_location',
        ];
        foreach ($genericKeys as $key) {
            $v = $this->getMetaString($item, $key);
            if ($v !== '' && $this->looksLikeArtPath($v)) {
                $paths[] = $v;
            }
        }

        // Meta scan for anything that looks like an art path
        foreach ($item->get_meta_data() as $meta) {
            $data = \is_object($meta) && \method_exists($meta, 'get_data') ? $meta->get_data() : null;
            $mKey = (string) ($data['key'] ?? ($meta->key ?? ''));
            $mVal = $data['value'] ?? ($meta->value ?? '');

            if ($mKey === '') {
                continue;
            }
            $label = strtolower($mKey);
            if (
                strpos($label, 'original') === false &&
                strpos($label, 'art') === false &&
                strpos($label, 'print') === false
            ) {
                continue;
            }

            if (\is_array($mVal)) {
                foreach ($mVal as $v) {
                    if (!\is_scalar($v)) continue;
                    $v = trim((string) $v);
                    if ($v !== '' && $this->looksLikeArtPath($v)) {
                        $paths[] = $v;
                    }
                }
            } elseif (\is_scalar($mVal)) {
                $v = trim((string) $mVal);
                if ($v !== '' && $this->looksLikeArtPath($v)) {
                    $paths[] = $v;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /** Loose detector for art URLs/paths (SVG/EPS/AI/PNG/JPG/WEBP/GIF). */
    protected function looksLikeArtPath(string $v): bool {
        if ($v === '') {
            return false;
        }

        // Full URLs
        if (stripos($v, 'http://') === 0 || stripos($v, 'https://') === 0) {
            return (bool) preg_match('~\.(svg|eps|ai|png|jpe?g|webp|gif)(\?|$)~i', $v);
        }

        // Direct wp-content paths
        if (strpos($v, '/wp-content/uploads/') !== false) {
            return (bool) preg_match('~\.(svg|eps|ai|png|jpe?g|webp|gif)(\?|$)~i', $v);
        }

        return false;
    }
}
