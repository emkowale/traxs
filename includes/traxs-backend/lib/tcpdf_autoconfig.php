<?php
// Shim autoconfig to load bundled config.
if (!defined('K_TCPDF_EXTERNAL_CONFIG')) {
    define('K_TCPDF_EXTERNAL_CONFIG', true);
}
if (!defined('K_PATH_MAIN')) {
    define('K_PATH_MAIN', __DIR__.'/');
}
require_once __DIR__ . '/config/tcpdf_config.php';
