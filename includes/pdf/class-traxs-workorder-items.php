<?php
namespace Traxs;

use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

if (!defined('ABSPATH')) exit;

trait WorkOrder_Items {

        protected function renderLineItemsTable(): void {
                if (!$this->order instanceof WC_Order) {
                        return;
                }

                $sizeCols        = $this->getSizeColumns();
                $lineHeight      = 6;
                $assetLineHeight = 5;

                $this->SetFont('Arial', 'B', 9);
                $w = [
                        'production' => 20,
                        'vendor'     => 28,
                        'color'      => 18,
                        'desc'       => 55,
                        'S'          => 9,
                        'M'          => 9,
                        'L'          => 9,
                        'XL'         => 9,
                        '2XL'        => 10,
                        '3XL'        => 10,
                        '4XL'        => 10,
                        'items'      => 12,
                ];
                $tableWidth = array_sum($w);

                $this->renderLineItemsTableHeader($w);
                $this->SetFont('Arial', '', 9);

                $groups = $this->groupLineItemsByVendorAndColor($sizeCols);

                foreach ($groups as $group) {
                        $rowHeight    = $this->calculateRowHeight($group['description'], $w['desc'], $lineHeight);
                        $assetsHeight = $this->calculateAssetsHeight($group['assets'], $tableWidth, $assetLineHeight);
                        $this->ensureLineItemSpace($rowHeight + $assetsHeight + 2, $w);

                        $this->renderLineItemRow($w, $group, $lineHeight);
                        if (!empty($group['assets'])) {
                                $this->renderGroupedAssets($group['assets'], $tableWidth, $assetLineHeight);
                        }
                }
        }

        protected function renderLineItemsTableHeader(array $w): void {
                $this->SetX(10);
                $this->Cell($w['production'], 7, 'Production', 1, 0, 'C');
                $this->Cell($w['vendor'], 7, 'Vendor Code', 1, 0, 'C');
                $this->Cell($w['color'], 7, 'Color', 1, 0, 'C');
                $this->Cell($w['desc'], 7, 'Description', 1, 0, 'C');
                foreach ($this->getSizeColumns() as $s) {
                        $this->Cell($w[$s], 7, $s, 1, 0, 'C');
                }
                $this->Cell($w['items'], 7, 'Items', 1, 0, 'C');
                $this->Ln();
        }

        protected function renderLineItemRow(array $w, array $group, float $lineHeight): void {
                $rowHeight = $this->calculateRowHeight($group['description'], $w['desc'], $lineHeight);
                $startX    = 10;
                $y         = $this->GetY();

                $this->SetXY($startX, $y);
                $this->Cell($w['production'], $rowHeight, $group['production'], 1);
                $this->Cell($w['vendor'], $rowHeight, $group['vendor_code'], 1);
                $this->Cell($w['color'], $rowHeight, $group['color'], 1);

                $descX = $this->GetX();
                $this->SetXY($descX, $y);
                $this->MultiCell($w['desc'], $lineHeight, $group['description'], 1);
                $afterDescX = $descX + $w['desc'];
                $this->SetXY($afterDescX, $y);

                foreach ($this->getSizeColumns() as $size) {
                        $value = (int) ($group['sizes'][$size] ?? 0);
                        $this->Cell($w[$size], $rowHeight, $value > 0 ? (string) $value : '', 1, 0, 'C');
                }

                $itemsText = $group['items_total'] > 0 ? (string) $group['items_total'] : '';
                $this->Cell($w['items'], $rowHeight, $itemsText, 1, 0, 'C');

                $this->SetY($y + $rowHeight);
        }

        protected function renderGroupedAssets(array $assets, float $tableWidth, float $lineHeight): void {
                $thumbSize = 16;
                $padding   = 2;
                $textWidth = $tableWidth - ($thumbSize + $padding * 3);

                foreach ($assets as $asset) {
                        $text        = $this->buildAssetText($asset);
                        $blockHeight = max(
                                $thumbSize + $padding * 2,
                                $this->calculateRowHeight($text, $textWidth, $lineHeight) + $padding
                        );

                        $x = 10;
                        $y = $this->GetY();
                        $this->Rect($x, $y, $tableWidth, $blockHeight);

                        if (!empty($asset['thumbnail'])) {
                                try {
                                        $this->Image($asset['thumbnail'], $x + $padding, $y + $padding, $thumbSize);
                                } catch (\Throwable $e) {
                                        // Ignore broken image URLs but continue rendering text.
                                }
                        }

                        $this->SetXY($x + $thumbSize + $padding * 2, $y + $padding);
                        $this->MultiCell($textWidth, $lineHeight, $text);
                        $this->SetY($y + $blockHeight);
                }

                $this->Ln(1);
        }

        protected function ensureLineItemSpace(float $requiredHeight, array $w): void {
                $limit = 260;
                if ($this->GetY() + $requiredHeight <= $limit) {
                        return;
                }

                $this->AddPage();
                $this->renderHeaderSection();
                $this->SetFont('Arial', 'B', 9);
                $this->renderLineItemsTableHeader($w);
                $this->SetFont('Arial', '', 9);
        }

        protected function groupLineItemsByVendorAndColor(array $sizeCols): array {
                $groups    = [];
                $orderKeys = [];

                foreach ($this->order->get_items('line_item') as $item) {
                        if (!$item instanceof WC_Order_Item_Product) {
                                continue;
                        }

                        $production = trim((string) $item->get_meta('Production', true));
                        $vendorCode = trim((string) ($item->get_meta('Vendor Code', true) ?: $item->get_meta('vendor_code', true)));
                        $color      = trim((string) ($item->get_meta('Color', true) ?: $item->get_meta('pa_color', true)));
                        $key        = $vendorCode . '|' . $color;

                        if (!isset($groups[$key])) {
                                $groups[$key] = [
                                        'production'   => $production,
                                        'vendor_code'  => $vendorCode,
                                        'color'        => $color,
                                        'descriptions' => [],
                                        'description'  => '',
                                        'sizes'        => array_fill_keys($sizeCols, 0),
                                        'items_total'  => 0,
                                        'assets'       => [],
                                ];
                                $orderKeys[] = $key;
                        } elseif ($groups[$key]['production'] === '' && $production !== '') {
                                $groups[$key]['production'] = $production;
                        }

                        $desc    = $item->get_name();
                        $sizeRaw = (string) ($item->get_meta('Size', true) ?: $item->get_meta('pa_size', true));
                        $sizeKey = strtoupper(trim($sizeRaw));
                        $qty     = (int) $item->get_quantity();

                        if ($qty < 0) {
                                $qty = 0;
                        }

                        if (isset($groups[$key]['sizes'][$sizeKey])) {
                                $groups[$key]['sizes'][$sizeKey] += $qty;
                                $groups[$key]['items_total']     += $qty;
                        } elseif ($sizeRaw !== '') {
                                $desc .= sprintf(' (%s x %d)', $sizeRaw, $qty);
                        }

                        if (!in_array($desc, $groups[$key]['descriptions'], true)) {
                                $groups[$key]['descriptions'][] = $desc;
                        }
                        $groups[$key]['description'] = implode("\n", $groups[$key]['descriptions']);

                        $groups[$key]['assets'][] = [
                                'name'      => $item->get_name(),
                                'thumbnail' => $this->resolveItemThumbnailUrl($item),
                                'art'       => $this->resolveItemArtLocation($item),
                        ];
                }

                $ordered = [];
                foreach ($orderKeys as $orderKey) {
                        $ordered[] = $groups[$orderKey];
                }

                return $ordered;
        }

        protected function calculateRowHeight(string $text, float $width, float $lineHeight): float {
                $text  = $text !== '' ? $text : ' ';
                $lines = $this->countLines($width, $text);
                return max($lineHeight, $lineHeight * $lines);
        }

        protected function calculateAssetsHeight(array $assets, float $tableWidth, float $lineHeight): float {
                if (empty($assets)) {
                        return 0.0;
                }

                $thumbSize = 16;
                $padding   = 2;
                $textWidth = $tableWidth - ($thumbSize + $padding * 3);
                $height    = 0.0;

                foreach ($assets as $asset) {
                        $text       = $this->buildAssetText($asset);
                        $textHeight = $this->calculateRowHeight($text, $textWidth, $lineHeight);
                        $height    += max($thumbSize + $padding * 2, $textHeight + $padding);
                }

                return $height + 1;
        }

        protected function countLines(float $width, string $text): int {
                if ($width <= 0) {
                        return 1;
                }

                $cw   = $this->CurrentFont['cw'] ?? [];
                $wmax = ($width - 2 * $this->cMargin) * 1000 / $this->FontSize;
                $s    = str_replace("\r", '', $text);
                $nb   = strlen($s);
                if ($nb > 0 && $s[$nb - 1] === "\n") {
                        $nb--;
                }

                $sep = -1;
                $i   = 0;
                $j   = 0;
                $l   = 0;
                $nl  = 1;

                while ($i < $nb) {
                        $c = $s[$i];
                        if ($c === "\n") {
                                $i++;
                                $sep = -1;
                                $j   = $i;
                                $l   = 0;
                                $nl++;
                                continue;
                        }
                        if ($c === ' ') {
                                $sep = $i;
                        }
                        $l += $cw[$c] ?? 0;
                        if ($l > $wmax) {
                                if ($sep === -1) {
                                        if ($i === $j) {
                                                $i++;
                                        }
                                } else {
                                        $i = $sep + 1;
                                }
                                $sep = -1;
                                $j   = $i;
                                $l   = 0;
                                $nl++;
                        } else {
                                $i++;
                        }
                }

                return $nl;
        }

        protected function buildAssetText(array $asset): string {
                $name  = trim((string) ($asset['name'] ?? ''));
                $art   = trim((string) ($asset['art'] ?? ''));
                $lines = $name !== '' ? $name : 'Item';
                $lines .= "\nOriginal Art: " . ($art !== '' ? $art : '—');

                return $lines;
        }

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
                $preferredKeys = [
                        'Original Art {Print Location}',
                        'Original Art',
                        'Print Location',
                        'original_art_print_location',
                ];

                foreach ($preferredKeys as $key) {
                        $value = $this->getMetaString($item, $key);
                        if ($value !== '') {
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

                        if (stripos($metaKey, 'original') === false && stripos($metaKey, 'print') === false) {
                                continue;
                        }

                        $value = is_scalar($metaValue) ? trim((string) $metaValue) : '';
                        if ($value !== '') {
                                return $value;
                        }
                }

                $product = $item->get_product();
                if ($product instanceof WC_Product && \function_exists('get_post_meta')) {
                        $fromProduct = trim((string) \get_post_meta($product->get_id(), 'Original Art {Print Location}', true));
                        if ($fromProduct !== '') {
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

        protected function getSizeColumns(): array {
                return ['S', 'M', 'L', 'XL', '2XL', '3XL', '4XL'];
        }

        protected function renderImagesSection(): void {
                $w = 63.5; $x1 = 10; $x2 = $x1 + $w + 5; $y = $this->GetY() + 3;
                if ($this->mockup_url) $this->Image($this->mockup_url,$x1,$y,$w);
                if ($this->art_url)    $this->Image($this->art_url,$x2,$y,$w);
        }
}
