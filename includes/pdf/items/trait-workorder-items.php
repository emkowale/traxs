<?php
// includes/pdf/items/trait-workorder-items.php
namespace Traxs;

use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

if (!defined('ABSPATH')) exit;

trait WorkOrder_Items {

    use WorkOrder_Items_Render;
    use WorkOrder_Items_Grouping;
    use WorkOrder_Items_Assets;
    use WorkOrder_Items_Resolve;
    use WorkOrder_Items_Utils;

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

        // NEW: group by main SKU prefix, not vendor code
        $groups = $this->groupLineItemsBySkuPrefix($sizeCols);

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

    protected function getSizeColumns(): array {
        return ['S', 'M', 'L', 'XL', '2XL', '3XL', '4XL'];
    }
}
