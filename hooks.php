<?php
/**
 * WHMCS Addon Module: Client Services & Expiry Monitor Hooks
 *
 * @package    WHMCS
 * @author     Developer
 * @copyright  Copyright (c) 2026
 * @version    1.0.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\View\Menu\Item as MenuItem;

/**
 * Add shortcut navigation link to WHMCS Admin Clients menu
 */
add_hook('AdminNavPrimary', 1, function ($vars) {
    // Check if user is logged in as admin
    if (!isset($_SESSION['adminid'])) {
        return;
    }

    // You can also add custom dashboard widgets or quick links here
});

/**
 * Add shortcut link to Admin Sidebar when viewing clients / services
 */
add_hook('AdminAreaSidebar', 1, function ($vars) {
    // Optional sidebar integration
});
