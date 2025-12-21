<?php
/*
 * File: includes/ajax/class-eject-ajax-run.php
 * Description: Loader that composes Eject_Ajax_Run from smaller traits.
 * Plugin: Eject
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-05 EDT
 */
if (!defined('ABSPATH')) exit;

// Pull in trait split files
require_once __DIR__ . '/traits/trait-eject-ajax-run-add.php';
require_once __DIR__ . '/traits/trait-eject-ajax-run-status.php';
require_once __DIR__ . '/traits/trait-eject-ajax-run-lines.php';

class Eject_Ajax_Run {
    use Eject_Ajax_Run_Add;
    use Eject_Ajax_Run_Status;
    use Eject_Ajax_Run_Lines;
}
