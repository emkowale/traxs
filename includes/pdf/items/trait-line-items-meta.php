<?php
namespace Traxs;

use WC_Order_Item_Product;
use WC_Product;

if (!defined('ABSPATH')) exit;

trait WorkOrder_Items_Meta {

    protected function resolveItemThumbnailUrl(WC_Order_Item_Product $item): string {
        $preferredKeys = [
            'Thumbnail URL',
            'thumbnail_url',
            'Item Thumbnail',
            'item_thumbnail',
            'Mockup Thumbnail',
            'mockup_thumbnail',
            'Mockup Image',
            'mockup_image',
        ];

        foreach ($preferredKeys as $key) {
            $value = $this->getMetaString($item, $key);
            if ($this->isImagePath($value)) {
                return $value;
            }
        }

        foreach ($item->get_meta_data() as $meta) {
            $data      = is_object($meta) && method_exists($meta, 'get_data') ? $meta->get_data() : null;
            $metaKey   = $data['key'] ?? ($meta->key ?? '');
            $metaValue = $data['value'] ?? ($meta->value ?? '');

            if (!is_string($metaKey)) {
                $metaKey = (string) $metaKey;
            }
            if ($metaKey === '') {
                continue;
            }

            if (stripos($metaKey, 'thumb') === false && stripos($metaKey, 'image') === false) {
                continue;
            }

            $value = is_scalar($metaValue) ? trim((string) $metaValue) : '';
            if ($this->isImagePath($value)) {
                return $value;
            }
        }

        $product = $item->get_product();
        if ($product instanceof WC_Product) {
            $imageId = $product->get_image_id();
            if ($imageId) {
                $src = '';
                if (\function_exists('wp_get_attachment_image_url')) {
                    $src = (string) \wp_get_attachment_image_url($imageId, 'thumbnail');
                }
                if (!$src && \function_exists('wp_get_attachment_url')) {
                    $src = (string) \wp_get_attachment_url($imageId);
                }
                if ($src) {
                    return $src;
                }
            }
        }

        return '';
    }

    protected function resolveItemArtLocation(WC_Order_Item_Product $item): string {
        // 1) Scan all meta for any "Original Art" style key whose VALUE looks like an image path.
        foreach ($item->get_meta_data() as $meta) {
            $data      = is_object($meta) && method_exists($meta, 'get_data') ? $meta->get_data() : null;
            $metaKey   = $data['key'] ?? ($meta->key ?? '');
            $metaValue = $data['value'] ?? ($meta->value ?? '');

            if (!is_string($metaKey)) {
                $metaKey = (string) $metaKey;
            }
            if ($metaKey === '' || !is_scalar($metaValue)) {
                continue;
            }

            $val = trim((string) $metaValue);

            // Only consider keys that look like "Original Art ..."
            if (stripos($metaKey, 'original') === false && stripos($metaKey, 'art') === false) {
                continue;
            }

            // We only want real paths / URLs, not "Front"/"Back" labels.
            if ($this->isImagePath($val)) {
                return $val;
            }
        }

        // 2) Fallback: specific known keys that might directly store a URL.
        $preferredKeys = [
            'Original Art {Print Location}',
            'Original Art',
            'original_art_print_location',
        ];

        foreach ($preferredKeys as $key) {
            $val = $this->getMetaString($item, $key);
            if ($this->isImagePath($val)) {
                return $val;
            }
        }

        // 3) Product-level fallback.
        $product = $item->get_product();
        if ($product instanceof WC_Product && \function_exists('get_post_meta')) {
            $fromProduct = trim((string) \get_post_meta($product->get_id(), 'Original Art {Print Location}', true));
            if ($this->isImagePath($fromProduct)) {
                return $fromProduct;
            }
        }

        return '';
    }


    protected function getMetaString(WC_Order_Item_Product $item, string $key): string {
        $value = $item->get_meta($key, true);
        return is_scalar($value) ? trim((string) $value) : '';
    }

    protected function isImagePath(string $value): bool {
        if ($value === '') {
            return false;
        }

        if (stripos($value, 'data:image') === 0) {
            return true;
        }

        return (bool) \filter_var($value, FILTER_VALIDATE_URL);
    }
}
