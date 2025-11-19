<?php
namespace Traxs;

if (!defined('ABSPATH')) exit;

trait WorkOrder_Items_Util {

    protected function calculateRowHeight(string $text, float $width, float $lineHeight): float {
        $text  = $text !== '' ? $text : ' ';
        $lines = $this->countLines($width, $text);
        return max($lineHeight, $lineHeight * $lines);
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
}
