<?php
/*
 * File: includes/class-traxs-events.php
 * Description: State machine for Art/Production scans; logging + emails.
 */
namespace Traxs;
if (!defined('ABSPATH')) exit;

class Events { public static function scan($wo, $type, $direction) { /* validate, log, advance */ } }
