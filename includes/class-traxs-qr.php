<?php
/*
 * File: includes/class-traxs-qr.php
 * Description: Local QR generation wrapper (phpqrcode) + Traxs deeplink helpers.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-12 EDT
 */
namespace Traxs;

if (!defined('ABSPATH')) exit;

class QR
{
    /** Ensure phpqrcode is loaded (expects includes/lib/phpqrcode/qrlib.php) */
    protected static function ensure_lib(): void
    {
        if (!class_exists('\\QRcode')) {
            $lib = TRAXS_PATH . 'includes/lib/phpqrcode/qrlib.php';
            if (file_exists($lib)) {
                require_once $lib;
            }
        }
        if (!class_exists('\\QRcode')) {
            // Hard fail with context — library missing.
            throw new \RuntimeException('phpqrcode library not found at includes/lib/phpqrcode/qrlib.php');
        }
    }

    /** Build the Traxs work-order deeplink used inside the QR code */
    public static function url(int $order_id): string
    {
        $order_id = max(0, (int)$order_id);
        // Hash route to allow SPA to pick it up immediately
        return home_url('/traxs/#/workorder?order=' . $order_id);
    }

    /**
     * Generate a QR PNG to a specific absolute file path.
     * @param string $data   Data encoded in QR
     * @param string $file   Absolute path to PNG destination
     * @param string $ecc    Error correction level: L, M, Q, H
     * @param int    $size   Matrix size (1..10 typical)
     * @param int    $margin Quiet zone pixels
     */
    public static function makeToFile(string $data, string $file, string $ecc = 'L', int $size = 4, int $margin = 2): string
    {
        self::ensure_lib();

        $dir = dirname($file);
        if (!is_dir($dir)) {
            if (function_exists('wp_mkdir_p')) {
                \wp_mkdir_p($dir);
            } else {
                @mkdir($dir, 0755, true);
            }
        }

        // Generate PNG
        \QRcode::png($data, $file, $ecc, $size, $margin);

        if (!file_exists($file)) {
            throw new \RuntimeException('Failed to create QR PNG at ' . $file);
        }
        return $file;
    }

    /**
     * Generate a QR PNG for a specific order into a temp uploads directory
     * and return the absolute path. Caller may unlink() after embedding.
     */
    public static function makeTempPngForOrder(int $order_id, string $ecc = 'L', int $size = 4, int $margin = 2): string
    {
        $data = self::url($order_id);

        // Prefer WP uploads; fall back to system temp if needed
        $base = null;
        if (function_exists('wp_upload_dir')) {
            $u = \wp_upload_dir();
            if (!empty($u['basedir'])) {
                $base = rtrim($u['basedir'], '/\\') . '/traxs_qr_tmp';
            }
        }
        if (!$base) {
            $base = rtrim(sys_get_temp_dir(), '/\\') . '/traxs_qr_tmp';
        }

        if (!is_dir($base)) {
            if (function_exists('wp_mkdir_p')) {
                \wp_mkdir_p($base);
            } else {
                @mkdir($base, 0755, true);
            }
        }

        $file = $base . '/wo_' . (int)$order_id . '_' . uniqid('', true) . '.png';
        return self::makeToFile($data, $file, $ecc, $size, $margin);
    }

    /** Safe cleanup helper (ignore errors) */
    public static function cleanup(?string $file): void
    {
        if ($file && is_string($file) && is_file($file)) {
            @unlink($file);
        }
    }
}
