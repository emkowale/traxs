<?php
/*
 * File: includes/class-traxs-settings.php
 * Description: Admin settings: General, Emails, Logs (menu only).
 */
namespace Traxs;
if (!defined('ABSPATH')) exit;

class Settings {
    public static function register() { register_setting('traxs','traxs_settings'); }
    public static function menu() {
        add_menu_page('Traxs','Traxs','traxs_manage','traxs-settings',[__CLASS__,'render'],'dashicons-analytics',58);
    }
    public static function render() {
        echo '<div class="wrap"><h1>Traxs Settings</h1><p>General, Emails, and Logs tabs coming online as modules load.</p></div>';
    }
}
