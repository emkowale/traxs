<?php
/*
 * File: includes/class-traxs-roles.php
 * Description: Adds Traxs roles and ensures admins have caps.
 */
namespace Traxs;
if (!defined('ABSPATH')) exit;

class Roles {
    public static function register() {
        add_role('traxs_operator','Traxs Operator',[
            'read' => true, 'traxs_access' => true,
        ]);
        add_role('traxs_manager','Traxs Manager',[
            'read' => true, 'traxs_access' => true, 'traxs_manage' => true,
        ]);
        // Guarantee admins have Traxs caps
        if ($role = get_role('administrator')) {
            $role->add_cap('traxs_access');
            $role->add_cap('traxs_manage');
        }
    }
}
