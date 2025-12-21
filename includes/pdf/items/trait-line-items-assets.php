<?php
namespace Traxs;

if (!defined('ABSPATH')) exit;

trait WorkOrder_Items_Assets {

    /** Mockup + all Original Art thumbnails in one horizontal row. */
    protected function renderGroupedAssets(array $assets, float $tableWidth, float $lineHeight): void {
        $thumbH = 50.8; // 2" in mm
        $pad    = 2;
        $x      = 10;

        // Collect one mockup + all unique art URLs for this SKU group.
        $mockupUrl = '';
        $artUrls   = [];
        foreach ($assets as $asset) {
            $t = trim((string) ($asset['thumbnail'] ?? ''));
            $a = trim((string) ($asset['art'] ?? ''));
            if ($mockupUrl === '' && $t !== '') {
                $mockupUrl = $t;
            }
            if ($a !== '' && !in_array($a, $artUrls, true)) {
                $artUrls[] = $a;
            }
        }
        if ($mockupUrl === '' && empty($artUrls)) {
            return;
        }

        // Convert all images to local JPG/PNG that FPDF can read.
        [$mockupFile, $mockupTmp] = $this->prepareImageForFpdf($mockupUrl);
        $artFiles = [];
        $artTmp   = [];
        foreach ($artUrls as $i => $url) {
            [$f, $isTmp]   = $this->prepareImageForFpdf($url);
            $artFiles[$i]  = $f;
            $artTmp[$i]    = $isTmp;
        }

        $blockH = $thumbH + 2 * $pad;
        $y      = $this->GetY();
        $this->Rect($x, $y, $tableWidth, $blockH);

        $cursorX = $x + $pad;
        $slotW   = 60;

        $draw = function (string $file, string $label) use ($thumbH, $pad, $y, $slotW, &$cursorX) {
            if ($file !== '') {
                try {
                    $this->Image($file, $cursorX, $y + $pad, 0, $thumbH);
                } catch (\Throwable $e) {
                    $this->SetFont('Arial', '', 6);
                    $this->Text($cursorX, $y + $pad + 3, 'DEBUG IMG ERR: ' . $label);
                    error_log('TRAXS IMG ERR: FPDF draw ' . $label . ' :: ' . $e->getMessage());
                }
            } else {
                $this->SetFont('Arial', '', 6);
                $this->Text($cursorX, $y + $pad + 3, 'DEBUG IMG ERR: ' . $label);
            }
            $cursorX += $slotW;
        };

        if ($mockupUrl !== '') {
            $draw($mockupFile, $mockupUrl);
        }
        foreach ($artUrls as $i => $url) {
            $draw($artFiles[$i] ?? '', $url);
        }

        // Cleanup temps.
        if ($mockupTmp && $mockupFile && file_exists($mockupFile)) {
            @unlink($mockupFile);
        }
        foreach ($artFiles as $i => $file) {
            if (($artTmp[$i] ?? false) && $file && file_exists($file)) {
                @unlink($file);
            }
        }

        $this->SetY($y + $blockH);
        $this->Ln(1);
    }

    /** Single row height (mockup + art) so paginator can reserve space. */
    protected function calculateAssetsHeight(array $assets, float $tableWidth, float $lineHeight): float {
        $thumbH = 50.8;
        $pad    = 2;
        foreach ($assets as $a) {
            if (!empty($a['thumbnail']) || !empty($a['art'])) {
                return $thumbH + 2 * $pad + 1;
            }
        }
        return 0.0;
    }

    /**
     * Make a URL/path safe for FPDF::Image():
     *  - jpg/jpeg/png: used as-is
     *  - webp/gif: GD -> JPG
     *  - svg/eps/ai: Imagick(readImageBlob) -> JPG (flattened)
     * Returns [localPath, isTempFile].
     */
    protected function prepareImageForFpdf(?string $path): array {
        $path = trim((string) $path);
        if ($path === '') return ['', false];

        $urlPath = \parse_url($path, PHP_URL_PATH) ?: $path;
        $ext     = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));

        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return [$path, false];
        }

        // WEBP / GIF => JPG via GD.
        if (in_array($ext, ['webp', 'gif'], true)) {
            if (!\function_exists('imagecreatefromstring')) return ['', false];
            $data = @file_get_contents($path);
            if ($data === false) {
                error_log('TRAXS IMG ERR: cannot download raster ' . $path);
                return ['', false];
            }
            $im = @imagecreatefromstring($data);
            if (!$im) {
                error_log('TRAXS IMG ERR: GD decode failed ' . $path);
                return ['', false];
            }
            $tmp = tempnam(sys_get_temp_dir(), 'traxs_img_') . '.jpg';
            imagejpeg($im, $tmp, 85);
            imagedestroy($im);
            error_log('TRAXS IMG OK: raster->jpg ' . $path . ' => ' . $tmp);
            return [$tmp, true];
        }

        // SVG / EPS / AI => JPG via Imagick blob (works for your tested SVGs).
        if (in_array($ext, ['svg', 'eps', 'ai'], true) && \class_exists('\Imagick')) {
            try {
                $data = @file_get_contents($path);
                if ($data === false) {
                    error_log('TRAXS IMG ERR: cannot download vec ' . $path);
                    return ['', false];
                }
                $img = new \Imagick();
                $img->setBackgroundColor('white');
                $img->readImageBlob($data);
                if ($img->getNumberImages() > 1) {
                    $img->setIteratorIndex(0);
                }
                if (defined('\Imagick::ALPHACHANNEL_REMOVE')) {
                    $img->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                }
                $img = $img->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                $img->setImageFormat('jpeg');
                $img->setImageCompressionQuality(85);

                $tmp = tempnam(sys_get_temp_dir(), 'traxs_vec_') . '.jpg';
                $img->writeImage($tmp);
                $img->clear();
                $img->destroy();

                error_log('TRAXS IMG OK: vec->jpg ' . $path . ' => ' . $tmp);
                return [$tmp, true];
            } catch (\Throwable $e) {
                error_log('TRAXS IMG ERR: vec convert ' . $path . ' :: ' . $e->getMessage());
                return ['', false];
            }
        }

        error_log('TRAXS IMG ERR: unsupported ext ' . $ext . ' for ' . $path);
        return ['', false];
    }
}
