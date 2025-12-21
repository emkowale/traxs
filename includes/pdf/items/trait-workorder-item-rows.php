<?php
namespace Traxs;

if (!defined('ABSPATH')) exit;

trait WorkOrder_ItemRows {
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
        $this->MultiCell($w['desc'], $lineHeight, $group['description'], 1, "L");
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
        $limit = $this->GetPageHeight() - $this->footerHeight - $this->bMargin;
        if ($this->GetY() + $requiredHeight <= $limit) {
            return;
        }

        $this->AddPage();
        $this->SetFont('Arial', 'B', 9);
        $this->ensureLineItemsTableStartPosition();
        $this->renderLineItemsTableHeader($w);
        $this->SetFont('Arial', '', 9);
    }
}
