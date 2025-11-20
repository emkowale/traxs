<?php
namespace Traxs;

use WC_Order;

if (!defined('ABSPATH')) exit;

trait WorkOrder_Items_Table {

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

        $this->renderLineItemsTableHeader($w);
        $this->SetFont('Arial', '', 9);

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

    protected function renderLineItemsTableHeader(array $w): void {
        $this->SetX($this->lMargin);
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
        $startX    = $this->lMargin;
        $y         = $this->GetY();

        $this->SetXY($startX, $y);
        $this->Cell($w['production'], $rowHeight, $group['production'], 1);
        $this->Cell($w['vendor'], $rowHeight, $group['vendor_code'], 1);
        $this->Cell($w['color'], $rowHeight, $group['color'], 1);

        $descX = $this->GetX();
        $this->SetXY($descX, $y);
        $this->MultiCell($w['desc'], $lineHeight, $group['description'], 1,"L");
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

    protected function ensureLineItemSpace(float $requiredHeight, array $w): void {
        $limit = $this->GetPageHeight() - $this->bMargin;
        if ($this->GetY() + $requiredHeight <= $limit) {
            return;
        }

        $this->AddPage();
        $this->renderHeaderSection();
        $this->SetFont('Arial', 'B', 9);
        $this->renderLineItemsTableHeader($w);
        $this->SetFont('Arial', '', 9);
    }

    protected function getSizeColumns(): array {
        return ['S', 'M', 'L', 'XL', '2XL', '3XL', '4XL'];
    }

    protected function renderImagesSection(): void {
        $gap = 6;
        $usableWidth = $this->GetPageWidth() - $this->lMargin - $this->rMargin;
        $boxWidth = ($usableWidth - $gap) / 2;
        $x1 = $this->lMargin;
        $x2 = $x1 + $boxWidth + $gap;
        $y  = $this->GetY() + 3;

        if ($this->mockup_url) {
            $this->Image($this->mockup_url, $x1, $y, $boxWidth);
        }
        if ($this->art_url) {
            $this->Image($this->art_url, $x2, $y, $boxWidth);
        }
    }
}
