<?php
namespace Traxs;

use WC_Order;

if (!defined('ABSPATH')) exit;

trait WorkOrder_Items_Table {

    protected function ensureLineItemsTableStartPosition(): void {
        $minStartY = max($this->tMargin + 70, $this->lastSectionBottom + 5);
        if ($this->GetY() < $minStartY) {
            $this->SetY($minStartY);
        }
    }

    protected function renderLineItemsTable(): void {
        if (!$this->order instanceof WC_Order) {
            return;
        }

        $sizeCols        = $this->getSizeColumns();
        $lineHeight      = 6;
        $assetLineHeight = 5;

        $this->SetFont('Arial', 'B', 8);
        $w = [
            'production' => 20,
            'vendor'     => 28,
            'color'      => 18,
            'desc'       => 58,
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

        $this->ensureLineItemsTableStartPosition();
        $this->renderLineItemsTableHeader($w);
        $this->SetFont('Arial', '', 8);

        $groups         = $this->groupLineItems($sizeCols);
        $printedForBase = [];

        foreach ($groups as $group) {
            $rowHeight    = $this->calculateRowHeight($group['description'], $w['desc'], $lineHeight);
            $assetsHeight = $this->calculateAssetsHeight($group['assets'], $tableWidth, $assetLineHeight);
            $required     = $rowHeight;

            if (!isset($printedForBase[$group['base_sku']]) && !empty($group['assets'])) {
                $required += $assetsHeight + 2;
            }

            $this->ensureLineItemSpace($required, $w);

            $this->renderLineItemRow($w, $group, $lineHeight);

            if (!isset($printedForBase[$group['base_sku']]) && !empty($group['assets'])) {
                $this->renderGroupedAssets($group['assets'], $tableWidth, $assetLineHeight);
                $printedForBase[$group['base_sku']] = true;
            }
        }
    }

    protected function getSizeColumns(): array {
        return ['S', 'M', 'L', 'XL', '2XL', '3XL', '4XL'];
    }
}
