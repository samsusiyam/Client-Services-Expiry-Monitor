<?php
/**
 * WHMCS Addon Module: Client Services & Expiry Monitor
 *
 * Real-time monitoring of client active products, services and domains sorted by next due date,
 * Custom Customer Management with Due Ledger & Notes, and Custom Overdue Grace Suspension Manager.
 *
 * @package    WHMCS
 * @author     Bahari IT
 * @copyright  Copyright (c) 2026 Bahari IT
 * @version    2.0.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

if (defined('CSM_CLIENT_SERVICES_MONITOR_LOADED')) {
    return;
}

define('CSM_CLIENT_SERVICES_MONITOR_LOADED', true);

use WHMCS\Database\Capsule;

// Helper: Ensure module database tables exist
if (!function_exists('csm_ensure_tables')) {
    function csm_ensure_tables()
    {
        try {
            // 1. Settings Table
            if (!Capsule::schema()->hasTable('mod_csm_settings')) {
                Capsule::schema()->create('mod_csm_settings', function ($table) {
                    $table->increments('id');
                    $table->string('setting', 191)->unique();
                    $table->text('value')->nullable();
                    $table->dateTime('created_at')->nullable();
                    $table->dateTime('updated_at')->nullable();
                });
            }

            // 2. Custom Customers / Services Table
            if (!Capsule::schema()->hasTable('mod_csm_custom_customers')) {
                Capsule::schema()->create('mod_csm_custom_customers', function ($table) {
                    $table->increments('id');
                    $table->unsignedInteger('userid')->nullable();
                    $table->string('customer_name', 255);
                    $table->string('company_name', 255)->nullable();
                    $table->string('email', 255)->nullable();
                    $table->string('phone', 100)->nullable();
                    $table->string('service_name', 255);
                    $table->string('domain', 255)->nullable();
                    $table->decimal('amount', 10, 2)->default(0.00);
                    $table->string('billing_cycle', 50)->default('Monthly');
                    $table->date('next_due_date')->nullable();
                    $table->string('status', 50)->default('Active'); // Active, Suspended, Paid, Unpaid
                    $table->text('notes')->nullable();
                    $table->date('custom_grace_date')->nullable();
                    $table->dateTime('created_at')->nullable();
                    $table->dateTime('updated_at')->nullable();
                });
            } else {
                if (!Capsule::schema()->hasColumn('mod_csm_custom_customers', 'userid')) {
                    Capsule::schema()->table('mod_csm_custom_customers', function ($table) {
                        $table->unsignedInteger('userid')->nullable()->after('id');
                    });
                }
            }

            // 3. Service & Customer Due Notes / Ledger Table
            if (!Capsule::schema()->hasTable('mod_csm_service_notes')) {
                Capsule::schema()->create('mod_csm_service_notes', function ($table) {
                    $table->increments('id');
                    $table->string('rel_type', 32); // 'service', 'domain', 'custom_customer'
                    $table->unsignedInteger('rel_id');
                    $table->text('note');
                    $table->decimal('due_amount', 10, 2)->nullable();
                    $table->decimal('paid_amount', 10, 2)->nullable();
                    $table->date('promised_date')->nullable();
                    $table->unsignedInteger('admin_id')->nullable();
                    $table->string('admin_name', 100)->nullable();
                    $table->dateTime('created_at')->nullable();
                    $table->dateTime('updated_at')->nullable();
                });
            } else {
                if (!Capsule::schema()->hasColumn('mod_csm_service_notes', 'updated_at')) {
                    Capsule::schema()->table('mod_csm_service_notes', function ($table) {
                        $table->dateTime('updated_at')->nullable();
                    });
                }
            }

            // 4. Custom Suspension / Overdue Grace Overrides Table
            if (!Capsule::schema()->hasTable('mod_csm_suspension_overrides')) {
                Capsule::schema()->create('mod_csm_suspension_overrides', function ($table) {
                    $table->increments('id');
                    $table->unsignedInteger('service_id')->unique();
                    $table->unsignedInteger('userid');
                    $table->date('original_due_date')->nullable();
                    $table->date('grace_suspend_date');
                    $table->text('reason')->nullable();
                    $table->string('status', 32)->default('active'); // active, expired, suspended, cancelled
                    $table->unsignedInteger('admin_id')->nullable();
                    $table->dateTime('created_at')->nullable();
                    $table->dateTime('updated_at')->nullable();
                });
            }

            // 5. Monitored / Selected VIP Clients Table
            if (!Capsule::schema()->hasTable('mod_csm_monitored_clients')) {
                Capsule::schema()->create('mod_csm_monitored_clients', function ($table) {
                    $table->increments('id');
                    $table->unsignedInteger('userid')->unique();
                    $table->text('notes')->nullable();
                    $table->dateTime('created_at')->nullable();
                    $table->dateTime('updated_at')->nullable();
                });
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

// Helper: Get module setting
if (!function_exists('csm_get_setting')) {
    function csm_get_setting($setting, $default = '')
    {
        $setting = (string) $setting;

        try {
            csm_ensure_tables();
            $row = Capsule::table('mod_csm_settings')
                ->where('setting', $setting)
                ->first(['value']);

            if ($row) {
                return (string) ($row->value ?? $default);
            }
        } catch (\Exception $e) {
        }

        try {
            $row = Capsule::table('tbladdonmodules')
                ->where('module', 'client_services_monitor')
                ->where('setting', $setting)
                ->first(['value']);

            if ($row) {
                $val = (string) ($row->value ?? $default);
                csm_save_setting($setting, $val);
                return $val;
            }
        } catch (\Exception $e) {
        }

        return $default;
    }
}

// Helper: Save module setting
if (!function_exists('csm_save_setting')) {
    function csm_save_setting($setting, $value)
    {
        $setting = (string) $setting;
        $value = (string) $value;
        $now = date('Y-m-d H:i:s');

        csm_ensure_tables();

        try {
            $exists = Capsule::table('mod_csm_settings')
                ->where('setting', $setting)
                ->exists();

            if ($exists) {
                Capsule::table('mod_csm_settings')
                    ->where('setting', $setting)
                    ->update([
                        'value' => $value,
                        'updated_at' => $now,
                    ]);
            } else {
                Capsule::table('mod_csm_settings')->insert([
                    'setting' => $setting,
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $tblExists = Capsule::table('tbladdonmodules')
                ->where('module', 'client_services_monitor')
                ->where('setting', $setting)
                ->exists();

            if ($tblExists) {
                Capsule::table('tbladdonmodules')
                    ->where('module', 'client_services_monitor')
                    ->where('setting', $setting)
                    ->update(['value' => $value]);
            } else {
                Capsule::table('tbladdonmodules')->insert([
                    'module' => 'client_services_monitor',
                    'setting' => $setting,
                    'value' => $value,
                ]);
            }
        } catch (\Exception $e) {
        }
    }
}

// Helper: Seed default settings
if (!function_exists('csm_seed_settings')) {
    function csm_seed_settings()
    {
        csm_ensure_tables();

        $defaults = [
            'default_status'              => 'Active',
            'records_per_page'            => '50',
            'highlight_days'              => '7',
            'default_country_code'        => '880',
            'auto_suspend_grace_enabled'  => 'on',
            'display_currency_id'         => '0',
            'wa_template'                 => "Dear {client_name},\nThis is a gentle reminder that your service *{service_name}* ({domain}) is due for renewal on *{due_date}*. Amount Due: *{amount}*.\nPlease renew timely to avoid service disruption.\nThank you!",
            'custom_admin_folder'         => '',
        ];

        foreach ($defaults as $k => $v) {
            $current = csm_get_setting($k, null);
            if ($current === null || $current === '') {
                csm_save_setting($k, $v);
            }
        }
    }
}

// Helper: Get active / chosen currency
if (!function_exists('csm_get_active_currency')) {
    function csm_get_active_currency($clientCurrencyId = 0)
    {
        try {
            $chosenCurrId = (int) csm_get_setting('display_currency_id', '0');
            if ($chosenCurrId > 0) {
                $curr = Capsule::table('tblcurrencies')->where('id', $chosenCurrId)->first();
                if ($curr) {
                    return $curr;
                }
            }
            if ($clientCurrencyId > 0) {
                $curr = Capsule::table('tblcurrencies')->where('id', $clientCurrencyId)->first();
                if ($curr) {
                    return $curr;
                }
            }
            return Capsule::table('tblcurrencies')->where('default', 1)->first() ?: Capsule::table('tblcurrencies')->first();
        } catch (\Exception $e) {
            return null;
        }
    }
}

// Helper: HTML escape
if (!function_exists('csm_h')) {
    function csm_h($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Define addon module configuration parameters.
 *
 * @return array
 */
if (!function_exists('client_services_monitor_config')) {
    function client_services_monitor_config()
    {
        return [
            'name'        => 'Client Services & Expiry Monitor',
            'description' => '<div style="margin-top:10px;padding:14px;background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;border-left:4px solid #1e3a5f;font-size:13.5px;line-height:1.6;color:#334155;"><div style="font-weight:700;color:#1e293b;margin-bottom:6px;"><i class="fas fa-desktop" style="color:#1e3a5f;margin-right:6px;"></i>Live Services, Custom Customer Due Ledger & Overdue Manager</div>Live real-time monitoring of client active services sorted by next due date with client phone/WhatsApp, custom customer ledger, inline due notes & custom overdue grace suspension control.</div>',
            'version'     => '2.0.0',
            'author'      => '<a href="https://client.bahariit.com" target="_blank" style="color:#1e3a5f;font-weight:700;text-decoration:none;"><i class="fas fa-shield-alt" style="margin-right:5px;font-size:12px;"></i> BahariIT</a>',
            'language'    => 'english',
            'fields'      => [
                'option1' => [
                    'FriendlyName' => 'Module Status',
                    'Type'         => 'yesno',
                    'Description'  => 'Tick to enable the module (All settings managed inside Addons -> Client Services & Expiry Monitor)',
                    'Default'      => 'yes',
                ],
            ],
        ];
    }
}

/**
 * Activate addon module.
 */
if (!function_exists('client_services_monitor_activate')) {
    function client_services_monitor_activate()
    {
        csm_ensure_tables();
        csm_seed_settings();

        return [
            'status' => 'success',
            'description' => 'Client Services & Expiry Monitor module activated successfully.'
        ];
    }
}

/**
 * Deactivate addon module.
 */
if (!function_exists('client_services_monitor_deactivate')) {
    function client_services_monitor_deactivate()
    {
        return [
            'status' => 'success',
            'description' => 'Client Services & Expiry Monitor module deactivated successfully. All database tables preserved.'
        ];
    }
}

/**
 * Upgrade addon module.
 */
if (!function_exists('client_services_monitor_upgrade')) {
    function client_services_monitor_upgrade($vars)
    {
        csm_ensure_tables();
        csm_seed_settings();
    }
}

/**
 * Shared CSS for the modern addon interface
 */
if (!function_exists('csm_shared_css')) {
    function csm_shared_css()
    {
        return '<style>
            .csm-module-wrap {
                background: #eef3f8;
                border: 0;
                border-radius: 8px;
                box-shadow: 0 18px 42px rgba(15,23,42,0.12);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                margin-bottom: 30px;
                overflow: hidden;
            }
            .csm-module-header {
                background: #12589b;
                padding: 24px 28px;
                border-bottom: 1px solid rgba(255,255,255,0.18);
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 15px;
            }
            .csm-header-brand {
                display: flex;
                align-items: center;
                gap: 16px;
            }
            .csm-header-icon {
                width: 52px;
                height: 52px;
                background: rgba(255,255,255,0.15);
                border: 1px solid rgba(255,255,255,0.28);
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: inset 0 1px 0 rgba(255,255,255,0.2);
                color: #fff;
                font-size: 24px;
            }
            .csm-header-title {
                font-size: 22px;
                font-weight: 800;
                color: #ffffff;
                margin: 0;
                line-height: 1.2;
            }
            .csm-header-subtitle {
                color: rgba(255,255,255,0.85);
                font-size: 13px;
                margin-top: 4px;
            }
            .csm-header-version {
                background: rgba(255,255,255,0.15);
                border: 1px solid rgba(255,255,255,0.3);
                color: #ffffff;
                font-size: 12px;
                font-weight: 800;
                padding: 6px 14px;
                border-radius: 20px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .csm-nav-wrapper {
                background: #ffffff;
                padding: 14px 20px;
                border-bottom: 1px solid #dbe5f1;
                box-shadow: 0 1px 0 rgba(15,23,42,0.03);
            }
            .csm-nav-container {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }
            .csm-nav-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                min-height: 42px;
                padding: 10px 16px;
                background: #f8fafc;
                border: 1px solid #d6e0ec;
                border-radius: 8px;
                color: #334155 !important;
                font-size: 13px;
                font-weight: 700;
                text-decoration: none !important;
                transition: all 0.15s ease;
                box-shadow: 0 1px 2px rgba(15,23,42,0.04);
            }
            .csm-nav-btn:hover {
                transform: translateY(-1px);
                background: #edf6ff;
                border-color: #9bc8ee;
                color: #0f5ea8 !important;
                box-shadow: 0 8px 18px rgba(18,88,155,0.12);
            }
            .csm-nav-btn.active {
                background: #1267b3;
                border-color: #1267b3;
                color: #ffffff !important;
                box-shadow: 0 10px 22px rgba(18,103,179,0.22);
            }
            .csm-module-body {
                padding: 24px;
                background: #eef3f8;
            }
            .csm-card, .csm-table-card {
                background: #ffffff;
                border: 1px solid #dce6f2;
                border-radius: 8px;
                box-shadow: 0 10px 26px rgba(15,23,42,0.07);
                margin-bottom: 20px;
                overflow: hidden;
            }
            .csm-card-header, .csm-table-header {
                padding: 18px 22px;
                background: #ffffff;
                border-bottom: 1px solid #e3ebf5;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 10px;
            }
            .csm-card-header h3, .csm-table-header h3 {
                margin: 0;
                color: #172033;
                font-size: 18px;
                font-weight: 800;
                letter-spacing: 0;
            }
            .csm-card-body {
                padding: 22px;
            }
            .csm-muted {
                color: #64748b !important;
                font-size: 13px;
                margin-top: 4px;
            }
            .csm-stats {
                display: grid;
                grid-template-columns: repeat(5, minmax(0, 1fr));
                gap: 14px;
                margin-bottom: 22px;
            }
            .csm-stat {
                background: #ffffff;
                position: relative;
                min-height: 94px;
                padding: 16px 18px;
                border-radius: 8px;
                border: 1px solid #dce6f2;
                box-shadow: 0 10px 24px rgba(15,23,42,0.06);
                overflow: hidden;
                cursor: pointer;
                transition: transform 0.15s ease;
            }
            .csm-stat:hover { transform: translateY(-2px); }
            .csm-stat:before {
                content: "";
                position: absolute;
                left: 0;
                top: 0;
                bottom: 0;
                width: 4px;
                background: #1267b3;
            }
            .csm-stat:nth-child(2):before { background: #f59e0b; }
            .csm-stat:nth-child(3):before { background: #ea580c; }
            .csm-stat:nth-child(4):before { background: #8b5cf6; }
            .csm-stat:nth-child(5):before { background: #dc2626; }
            .csm-stat-label {
                color: #64748b;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .csm-stat-value {
                margin-top: 6px;
                color: #12589b;
                font-size: 24px;
                font-weight: 800;
            }
            .csm-table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 0;
                font-size: 13px;
            }
            .csm-table th {
                background: #f8fafc;
                border-bottom: 2px solid #e2e8f0;
                color: #475569;
                font-weight: 700;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                padding: 12px 14px;
            }
            .csm-table td {
                padding: 12px 14px;
                border-bottom: 1px solid #f1f5f9;
                color: #1e293b;
                vertical-align: middle;
            }
            .csm-table tbody tr:hover {
                background: #f8fbff;
            }
            .csm-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
            }
            .csm-badge-active { background: #dcfce7; color: #15803d; }
            .csm-badge-suspended { background: #fee2e2; color: #991b1b; }
            .csm-badge-overdue { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
            .csm-badge-warning { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
            .csm-badge-grace { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
            .csm-module-wrap .btn {
                border-radius: 8px !important;
                font-weight: 700;
                padding: 8px 16px;
                font-size: 13px;
            }
            .csm-module-wrap .btn-primary { background: #1267b3; border-color: #1267b3; color: #fff; }
            .csm-module-wrap .btn-primary:hover { background: #0f5ea8; border-color: #0f5ea8; }
            .csm-module-wrap .btn-default { background: #fff; border-color: #cfdbe8; color: #334155; }
            .csm-module-wrap .btn-default:hover { background: #f5f9fd; }
            .csm-module-wrap .btn-success { background: #16a34a; border-color: #16a34a; color: #fff; }
            .csm-module-wrap .btn-warning { background: #f59e0b; border-color: #f59e0b; color: #fff; }
            .csm-module-wrap .btn-danger { background: #dc2626; border-color: #dc2626; color: #fff; }
            .csm-module-footer {
                background: #ffffff;
                border-top: 1px solid #dce6f2;
                padding: 16px 24px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                color: #64748b;
                font-size: 13px;
            }
            .csm-footer-badge {
                background: #eaf4ff;
                color: #0f5ea8;
                padding: 4px 12px;
                border-radius: 20px;
                font-weight: 700;
                font-size: 12px;
            }
            .csm-actions-toolbar {
                padding: 16px 20px;
                background: #ffffff;
                border: 1px solid #dce6f2;
                border-radius: 8px;
                box-shadow: 0 8px 22px rgba(15,23,42,0.06);
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 12px;
                margin-top: 20px;
            }
            .csm-check-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }
            .csm-check-row {
                display: flex;
                align-items: flex-start;
                gap: 12px;
                border: 1px solid #dce6f2;
                border-radius: 8px;
                background: #f8fafc;
                padding: 12px 14px;
                margin: 0;
                cursor: pointer;
            }
            @media (max-width: 980px) {
                .csm-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            }
            @media (max-width: 680px) {
                .csm-stats, .csm-check-grid { grid-template-columns: 1fr; }
                .csm-nav-btn { width: 100%; justify-content: center; }
            }
            .select2-container--default .select2-selection--multiple {
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                min-height: 38px;
                padding: 2px 6px;
            }
            .select2-container--default.select2-container--focus .select2-selection--multiple {
                border-color: #1267b3;
            }
            .select2-container--default .select2-selection--multiple .select2-selection__choice {
                background-color: #12589b;
                border: 1px solid #0f4b85;
                color: #fff;
                border-radius: 4px;
                padding: 2px 8px;
                font-size: 12px;
                font-weight: 600;
            }
            .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
                color: #fff;
                margin-right: 5px;
            }
            .select2-dropdown {
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.1);
                z-index: 999999;
            }
        </style>
        <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script>
        function csmConfirmDelete(url, text) {
            if (typeof Swal !== "undefined") {
                Swal.fire({
                    title: "Are you sure?",
                    text: text || "You will not be able to revert this!",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonColor: "#dc2626",
                    cancelButtonColor: "#64748b",
                    confirmButtonText: "Yes, delete it!"
                }).then(function(result) {
                    if (result.isConfirmed) {
                        window.location.href = url;
                    }
                });
                return false;
            }
            return confirm(text || "Are you sure you want to delete this?");
        }
        </script>';
    }
}

/**
 * Header Renderer
 */
if (!function_exists('csm_render_header')) {
    function csm_render_header($vars, $action)
    {
        $moduleLink = $vars['modulelink'];
        $version = $vars['version'] ?: '2.0.0';

        return csm_shared_css() . '
        <div class="csm-module-wrap">
            <div class="csm-module-header">
                <div class="csm-header-brand">
                    <div class="csm-header-icon">
                        <i class="fas fa-desktop"></i>
                    </div>
                    <div>
                        <div class="csm-header-title">Client Services &amp; Expiry Monitor</div>
                        <div class="csm-header-subtitle">Live Service Monitor, Custom Customer Due Ledger &amp; Overdue Suspension Control</div>
                    </div>
                </div>
                <span class="csm-header-version">v' . csm_h($version) . '</span>
            </div>
            <div class="csm-nav-wrapper">
                <div class="csm-nav-container">
                    <a href="' . csm_h($moduleLink) . '&action=live_monitor" class="csm-nav-btn' . ($action === 'live_monitor' ? ' active' : '') . '">
                        <i class="fas fa-desktop"></i> Live Monitor
                    </a>
                    <a href="' . csm_h($moduleLink) . '&action=custom_customers" class="csm-nav-btn' . ($action === 'custom_customers' ? ' active' : '') . '">
                        <i class="fas fa-users-gear"></i> Custom Customers &amp; Ledger
                    </a>
                    <a href="' . csm_h($moduleLink) . '&action=suspension_manager" class="csm-nav-btn' . ($action === 'suspension_manager' ? ' active' : '') . '">
                        <i class="fas fa-clock-rotate-left"></i> Custom Suspend Overdue
                    </a>
                    <a href="' . csm_h($moduleLink) . '&action=due_notes" class="csm-nav-btn' . ($action === 'due_notes' ? ' active' : '') . '">
                        <i class="fas fa-book-bookmark"></i> Due Notes &amp; Ledger
                    </a>
                    <a href="' . csm_h($moduleLink) . '&action=module_setup" class="csm-nav-btn' . ($action === 'module_setup' ? ' active' : '') . '">
                        <i class="fas fa-cogs"></i> Module Setup
                    </a>
                    <a href="' . csm_h($moduleLink) . '&action=changelog" class="csm-nav-btn' . ($action === 'changelog' ? ' active' : '') . '">
                        <i class="fas fa-list-alt"></i> Changelog
                    </a>
                    <a href="' . csm_h($moduleLink) . '&action=developer_info" class="csm-nav-btn' . ($action === 'developer_info' ? ' active' : '') . '">
                        <i class="fas fa-code"></i> Developer Info
                    </a>
                </div>
            </div>
            <div class="csm-module-body">';
    }
}

/**
 * Footer Renderer
 */
if (!function_exists('csm_render_footer')) {
    function csm_render_footer($action)
    {
        $pageLabels = [
            'live_monitor'        => 'Live Monitor',
            'custom_customers'    => 'Custom Customers & Ledger',
            'suspension_manager'  => 'Custom Suspend Overdue',
            'due_notes'           => 'Due Notes & Ledger',
            'module_setup'        => 'Module Setup',
            'changelog'           => 'Changelog',
            'developer_info'      => 'Developer Info',
        ];

        $pageLabel = $pageLabels[$action] ?? 'Live Monitor';

        return '</div>
            <div class="csm-module-footer">
                <span>&copy; ' . date('Y') . ' <strong>Bahari IT</strong> &bull; Client Services &amp; Expiry Monitor</span>
                <span class="csm-footer-badge"><i class="fas fa-map-marker-alt"></i> ' . csm_h($pageLabel) . '</span>
            </div>
        </div>';
    }
}

/**
 * Page: Custom / Selected Clients Live Monitor
 */
if (!function_exists('csm_render_custom_customers_page')) {
    function csm_render_custom_customers_page($vars)
    {
        csm_ensure_tables();
        $moduleLink = $vars['modulelink'];

        $html = '';
        if (isset($_GET['saved'])) {
            $html .= '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Monitored client(s) updated successfully.</div>';
        }
        if (isset($_GET['removed'])) {
            $html .= '<div class="alert alert-info"><i class="fas fa-info-circle"></i> Client removed from custom monitor.</div>';
        }
        if (isset($_GET['deleted'])) {
            $html .= '<div class="alert alert-info"><i class="fas fa-info-circle"></i> Custom service deleted.</div>';
        }

        $activeCurrency = csm_get_active_currency();
        $currPrefix = ($activeCurrency && !empty($activeCurrency->prefix)) ? $activeCurrency->prefix : '৳ ';
        $currSuffix = ($activeCurrency && !empty($activeCurrency->suffix)) ? $activeCurrency->suffix : '';

        $warningDays = (int) csm_get_setting('highlight_days', '7');
        $defaultCountryCode = preg_replace('/[^0-9]/', '', csm_get_setting('default_country_code', '880'));
        $todayStr = date('Y-m-d');
        $todayTs = strtotime($todayStr);

        // Fetch all WHMCS clients for Select2 picker
        $allClients = [];
        try {
            $allClients = Capsule::table('tblclients')
                ->select('id', 'firstname', 'lastname', 'companyname', 'email', 'phonenumber')
                ->orderBy('firstname', 'ASC')
                ->get();
        } catch (\Exception $e) {}

        // Fetch Monitored Client IDs
        $monitoredRows = Capsule::table('mod_csm_monitored_clients')->get();
        $monitoredUserIds = $monitoredRows->pluck('userid')->toArray();

        // Auto-seed from custom customers if table is new
        if (empty($monitoredUserIds)) {
            $legacyUserIds = Capsule::table('mod_csm_custom_customers')->whereNotNull('userid')->where('userid', '>', 0)->pluck('userid')->unique()->toArray();
            if (!empty($legacyUserIds)) {
                foreach ($legacyUserIds as $uId) {
                    Capsule::table('mod_csm_monitored_clients')->updateOrInsert(['userid' => $uId], ['created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                }
                $monitoredUserIds = $legacyUserIds;
            }
        }

        $monitoredClients = !empty($monitoredUserIds) ? Capsule::table('tblclients')->whereIn('id', $monitoredUserIds)->orderBy('firstname', 'ASC')->get()->keyBy('id') : collect([]);

        // Fetch WHMCS Services for Monitored Clients
        $services = collect([]);
        if (!empty($monitoredUserIds)) {
            $services = Capsule::table('tblhosting')
                ->join('tblclients', 'tblhosting.userid', '=', 'tblclients.id')
                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->leftJoin('tblproductgroups', 'tblproducts.gid', '=', 'tblproductgroups.id')
                ->leftJoin('tblservers', 'tblhosting.server', '=', 'tblservers.id')
                ->whereIn('tblhosting.userid', $monitoredUserIds)
                ->select(
                    'tblhosting.id as item_id',
                    Capsule::raw("'service' as record_type"),
                    'tblhosting.userid',
                    'tblhosting.domain',
                    'tblhosting.amount as price',
                    'tblhosting.billingcycle',
                    'tblhosting.nextduedate',
                    'tblhosting.domainstatus as status',
                    'tblhosting.username',
                    'tblhosting.dedicatedip',
                    'tblproducts.name as product_name',
                    'tblproducts.type as product_type',
                    'tblproductgroups.name as group_name',
                    'tblservers.name as server_name',
                    'tblclients.firstname',
                    'tblclients.lastname',
                    'tblclients.companyname',
                    'tblclients.email',
                    'tblclients.phonenumber',
                    'tblclients.currency as client_currency'
                )
                ->get();
        }

        // Fetch WHMCS Domains for Monitored Clients
        $domains = collect([]);
        if (!empty($monitoredUserIds)) {
            $domains = Capsule::table('tbldomains')
                ->join('tblclients', 'tbldomains.userid', '=', 'tblclients.id')
                ->whereIn('tbldomains.userid', $monitoredUserIds)
                ->select(
                    'tbldomains.id as item_id',
                    Capsule::raw("'domain' as record_type"),
                    'tbldomains.userid',
                    'tbldomains.domain',
                    'tbldomains.recurringamount as price',
                    'tbldomains.registrationperiod as billingcycle',
                    'tbldomains.nextduedate',
                    'tbldomains.status',
                    Capsule::raw("'' as username"),
                    Capsule::raw("'' as dedicatedip"),
                    Capsule::raw("'Domain Registration' as product_name"),
                    Capsule::raw("'domain' as product_type"),
                    Capsule::raw("'' as group_name"),
                    Capsule::raw("'' as server_name"),
                    'tblclients.firstname',
                    'tblclients.lastname',
                    'tblclients.companyname',
                    'tblclients.email',
                    'tblclients.phonenumber',
                    'tblclients.currency as client_currency'
                )
                ->get();
        }

        // Fetch Custom / Offline Items
        $customServices = Capsule::table('mod_csm_custom_customers')
            ->where(function ($q) use ($monitoredUserIds) {
                if (!empty($monitoredUserIds)) {
                    $q->whereIn('userid', $monitoredUserIds)->orWhereNull('userid')->orWhere('userid', 0);
                }
            })
            ->get();

        // Fetch Overrides and Notes in Bulk
        $allItemIds = $services->pluck('item_id')->merge($domains->pluck('item_id'))->merge($customServices->pluck('id'))->toArray();

        $overrides = Capsule::table('mod_csm_suspension_overrides')
            ->whereIn('service_id', $services->pluck('item_id')->toArray())
            ->where('status', 'active')
            ->get()
            ->keyBy('service_id');

        $notes = Capsule::table('mod_csm_service_notes')
            ->whereIn('rel_id', $allItemIds)
            ->orderBy('id', 'DESC')
            ->get()
            ->groupBy(function ($item) {
                return $item->rel_type . '_' . $item->rel_id;
            });

        // Unify All Records
        $unifiedRecords = [];

        // 1. WHMCS Services
        foreach ($services as $srv) {
            $unifiedRecords[] = [
                'id'           => $srv->item_id,
                'record_type'  => 'service',
                'userid'       => $srv->userid,
                'client_name'  => trim($srv->firstname . ' ' . $srv->lastname),
                'company'      => $srv->companyname ?: '',
                'email'        => $srv->email ?: '',
                'phone'        => $srv->phonenumber ?: '',
                'product_name' => $srv->product_name,
                'product_type' => $srv->product_type,
                'group_name'   => $srv->group_name ?: '',
                'server_name'  => $srv->server_name ?: '',
                'domain'       => $srv->domain ?: '',
                'price'        => (float)$srv->price,
                'billing_cycle'=> $srv->billingcycle,
                'next_due_date'=> $srv->nextduedate,
                'status'       => $srv->status,
                'is_custom'    => false,
            ];
        }

        // 2. WHMCS Domains
        foreach ($domains as $dom) {
            $unifiedRecords[] = [
                'id'           => $dom->item_id,
                'record_type'  => 'domain',
                'userid'       => $dom->userid,
                'client_name'  => trim($dom->firstname . ' ' . $dom->lastname),
                'company'      => $dom->companyname ?: '',
                'email'        => $dom->email ?: '',
                'phone'        => $dom->phonenumber ?: '',
                'product_name' => 'Domain Registration',
                'product_type' => 'domain',
                'group_name'   => '',
                'server_name'  => '',
                'domain'       => $dom->domain ?: '',
                'price'        => (float)$dom->price,
                'billing_cycle'=> $dom->billingcycle,
                'next_due_date'=> $dom->nextduedate,
                'status'       => $dom->status,
                'is_custom'    => false,
            ];
        }

        // 3. Custom / Offline Services
        foreach ($customServices as $cust) {
            $unifiedRecords[] = [
                'id'           => $cust->id,
                'record_type'  => 'custom_customer',
                'userid'       => $cust->userid,
                'client_name'  => $cust->customer_name ?: 'Custom Client',
                'company'      => $cust->company_name ?: '',
                'email'        => $cust->email ?: '',
                'phone'        => $cust->phone ?: '',
                'product_name' => $cust->service_name,
                'product_type' => 'custom',
                'group_name'   => 'Offline / Custom Service',
                'server_name'  => '',
                'domain'       => $cust->domain ?: '',
                'price'        => (float)$cust->amount,
                'billing_cycle'=> $cust->billing_cycle,
                'next_due_date'=> $cust->next_due_date,
                'status'       => $cust->status,
                'is_custom'    => true,
                'raw_cust_obj' => $cust,
            ];
        }

        // Sort by next_due_date ASC
        usort($unifiedRecords, function ($a, $b) {
            $dateA = !empty($a['next_due_date']) && $a['next_due_date'] !== '0000-00-00' ? $a['next_due_date'] : '9999-12-31';
            $dateB = !empty($b['next_due_date']) && $b['next_due_date'] !== '0000-00-00' ? $b['next_due_date'] : '9999-12-31';
            return strcmp($dateA, $dateB);
        });

        // Compute Statistics
        $totalClientsCount = count($monitoredUserIds);
        $totalActiveCount = 0;
        $dueTodayCount = 0;
        $due7DaysCount = 0;
        $overdueCount = 0;
        $totalDueAmount = 0.00;

        foreach ($unifiedRecords as $r) {
            $st = strtolower($r['status']);
            if ($st === 'active') {
                $totalActiveCount++;
            }
            if ($st === 'unpaid') {
                $totalDueAmount += (float)$r['price'];
            }

            if (!empty($r['next_due_date']) && $r['next_due_date'] !== '0000-00-00') {
                $dTs = strtotime($r['next_due_date']);
                $diff = (int)round(($dTs - $todayTs) / 86400);

                if ($diff < 0 && $st !== 'paid') {
                    $overdueCount++;
                    if ($st === 'active') {
                        $totalDueAmount += (float)$r['price'];
                    }
                } elseif ($diff === 0) {
                    $dueTodayCount++;
                } elseif ($diff > 0 && $diff <= $warningDays) {
                    $due7DaysCount++;
                }
            }
        }

        // Monitored Clients Chips Bar
        $clientChipsHtml = '';
        if (!empty($monitoredClients) && $monitoredClients->count() > 0) {
            foreach ($monitoredClients as $mc) {
                $cName = trim($mc->firstname . ' ' . $mc->lastname);
                $cComp = $mc->companyname ? ' (' . $mc->companyname . ')' : '';
                $removeUrl = $moduleLink . '&action=remove_monitored_client&userid=' . $mc->id;
                $clientChipsHtml .= '<span class="csm-client-chip" style="display:inline-flex;align-items:center;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:700;margin:3px 4px 3px 0;">'
                    . '<i class="fas fa-user-check text-primary" style="margin-right:5px;"></i>'
                    . '<a href="clientssummary.php?userid=' . $mc->id . '" target="_blank" style="color:#0369a1;text-decoration:none;">#' . $mc->id . ' ' . csm_h($cName) . csm_h($cComp) . '</a>'
                    . '<a href="' . csm_h($removeUrl) . '" onclick="return csmConfirmDelete(this.href, \'Remove #' . $mc->id . ' ' . csm_h(addslashes($cName)) . ' from Custom Monitor?\')" style="color:#dc2626;margin-left:8px;font-weight:900;text-decoration:none;" title="Remove from Monitor">&times;</a>'
                    . '</span>';
            }
        } else {
            $clientChipsHtml = '<span class="text-muted" style="font-size:12.5px;"><i class="fas fa-info-circle"></i> No specific clients added yet. Click <strong>"Add Clients to Monitor"</strong> to select your high-value / VIP clients.</span>';
        }

        // 6 Summary Stats Cards
        $html .= '<div class="csm-stats" style="grid-template-columns: repeat(6, minmax(0, 1fr));">
            <div class="csm-stat" onclick="csmFilterCustom(\'\')" title="Show All Records">
                <div class="csm-stat-label">Monitored Clients</div>
                <div class="csm-stat-value">' . $totalClientsCount . '</div>
            </div>
            <div class="csm-stat" onclick="csmFilterCustom(\'active\')" title="Show Active Services">
                <div class="csm-stat-label">Active Services</div>
                <div class="csm-stat-value" style="color:#16a34a;">' . $totalActiveCount . '</div>
            </div>
            <div class="csm-stat" onclick="csmFilterCustom(\'due7days\')" title="Show Due in ' . $warningDays . ' Days">
                <div class="csm-stat-label">Due in ' . $warningDays . ' Days</div>
                <div class="csm-stat-value" style="color:#f59e0b;">' . $due7DaysCount . '</div>
            </div>
            <div class="csm-stat" onclick="csmFilterCustom(\'today\')" title="Show Due Today">
                <div class="csm-stat-label">Due Today</div>
                <div class="csm-stat-value" style="color:#ea580c;">' . $dueTodayCount . '</div>
            </div>
            <div class="csm-stat" onclick="csmFilterCustom(\'overdue\')" title="Show Overdue">
                <div class="csm-stat-label">Overdue</div>
                <div class="csm-stat-value" style="color:#dc2626;">' . $overdueCount . '</div>
            </div>
            <div class="csm-stat" onclick="csmFilterCustom(\'unpaid\')" title="Show Total Unpaid Dues">
                <div class="csm-stat-label">Total Unpaid Due</div>
                <div class="csm-stat-value" style="color:#dc2626;font-size:18px;">' . $currPrefix . number_format((float)$totalDueAmount, 2) . $currSuffix . '</div>
            </div>
        </div>';

        // Header Action Card with Monitored Clients List
        $html .= '<div class="csm-card" style="margin-bottom:18px;">
            <div style="padding:16px 20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;border-bottom:1px solid #eef3f8;">
                <div>
                    <h4 style="margin:0;font-weight:800;color:#1e293b;font-size:16px;">
                        <i class="fas fa-users-gear text-primary"></i> Monitored VIP / Selected Clients
                    </h4>
                    <div class="csm-muted">Real-time dedicated monitoring of services, domains, dues, and payment ledgers for your selected clients.</div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button class="btn btn-primary btn-sm" onclick="openManageMonitoredClientsModal()"><i class="fas fa-user-plus"></i> Add Clients to Monitor</button>
                    <button class="btn btn-default btn-sm" onclick="openAddCustomerModal()"><i class="fas fa-plus"></i> Add Offline / Custom Service</button>
                </div>
            </div>
            <div style="padding:14px 20px;background:#f8fafc;display:flex;flex-wrap:wrap;align-items:center;gap:6px;">
                <span style="font-weight:700;font-size:12px;color:#475569;margin-right:6px;"><i class="fas fa-tags"></i> Active Monitored List:</span>
                ' . $clientChipsHtml . '
            </div>
        </div>';

        // Main Services Table Card
        $html .= '<div class="csm-table-card">
            <div class="csm-table-header" style="padding:16px 20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                <div>
                    <h3 style="margin:0;font-size:18px;font-weight:800;color:#1e293b;">
                        <i class="fas fa-desktop text-primary"></i> Monitored Clients Live Service Board
                    </h3>
                    <div class="csm-muted">Showing all services, domains &amp; custom dues for the selected clients, sorted by next due date.</div>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <div class="input-group" style="width:240px;">
                        <input type="text" id="customMonitorSearchInput" class="form-control" placeholder="Search service, client, domain...">
                        <span class="input-group-btn">
                            <button class="btn btn-default" type="button" id="customMonitorSearchClear" title="Clear"><i class="fas fa-times"></i></button>
                        </span>
                    </div>
                    <select id="customMonitorStatusFilter" class="form-control" style="width:135px;display:inline-block;">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="unpaid">Unpaid</option>
                        <option value="due7days">Due Soon (' . $warningDays . 'd)</option>
                        <option value="today">Due Today</option>
                        <option value="overdue">Overdue</option>
                        <option value="suspended">Suspended</option>
                        <option value="paid">Paid</option>
                    </select>
                </div>
            </div>
            <div style="overflow-x:auto;">
                <table class="csm-table" id="csmCustomMonitorTable">
                    <thead>
                        <tr>
                            <th width="65">ID</th>
                            <th>Product / Service</th>
                            <th>Domain</th>
                            <th>Client &amp; Contact</th>
                            <th>Phone / WhatsApp</th>
                            <th>Billing</th>
                            <th>Next Due Date <i class="fas fa-arrow-down-short-wide text-primary"></i></th>
                            <th>Grace Suspend</th>
                            <th>Due Note / Remarks</th>
                            <th>Status</th>
                            <th width="90" class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="csmCustomMonitorTableBody">';

        if (empty($unifiedRecords)) {
            $html .= '<tr id="csmCustomEmptyRow"><td colspan="11" style="text-align:center;padding:50px;color:#64748b;">
                <i class="fas fa-users-gear" style="font-size:38px;margin-bottom:12px;display:block;opacity:0.4;"></i>
                <p style="font-size:15px;font-weight:700;color:#334155;margin-bottom:6px;">No services to display</p>
                <p style="margin-bottom:14px;">Click <strong>"Add Clients to Monitor"</strong> above to select the specific WHMCS clients you want to track.</p>
                <button class="btn btn-primary" onclick="openManageMonitoredClientsModal()"><i class="fas fa-user-plus"></i> Select Clients Now</button>
            </td></tr>';
        } else {
            foreach ($unifiedRecords as $idx => $r) {
                $statusLower = strtolower($r['status']);
                $statusClass = $statusLower === 'active' ? 'active' : ($statusLower === 'suspended' ? 'suspended' : ($statusLower === 'unpaid' ? 'overdue' : 'warning'));

                // Phone cleaning & action buttons (no dots)
                $rawPhone = trim($r['phone'] ?: '');
                $cleanDisplayPhone = str_replace('.', ' ', $rawPhone);
                $cleanDisplayPhone = trim(preg_replace('/\s+/', ' ', $cleanDisplayPhone));
                $digitsPhone = preg_replace('/[^0-9]/', '', str_replace('.', '', $rawPhone));

                $intlPhone = '';
                if (!empty($digitsPhone)) {
                    if (substr($digitsPhone, 0, strlen($defaultCountryCode)) === $defaultCountryCode) {
                        $intlPhone = $digitsPhone;
                    } elseif (substr($digitsPhone, 0, 1) === '0') {
                        $intlPhone = $defaultCountryCode . substr($digitsPhone, 1);
                    } else {
                        $intlPhone = $defaultCountryCode . $digitsPhone;
                    }
                }
                $dialPhone = !empty($intlPhone) ? '+' . $intlPhone : (!empty($digitsPhone) ? '+' . $digitsPhone : '');

                $waTemplate = csm_get_setting('wa_template', '');
                $waMessage = str_replace(
                    ['{client_name}', '{service_name}', '{domain}', '{due_date}', '{amount}'],
                    [$r['client_name'], $r['product_name'], $r['domain'] ?: 'N/A', (!empty($r['next_due_date']) && $r['next_due_date'] !== '0000-00-00') ? date('d/m/Y', strtotime($r['next_due_date'])) : 'N/A', $currPrefix . number_format((float)$r['price'], 2) . $currSuffix],
                    $waTemplate
                );
                $waUrl = !empty($intlPhone) ? 'https://wa.me/' . $intlPhone . '?text=' . urlencode($waMessage) : '';

                $callBtn = !empty($dialPhone) ? '<a href="tel:' . csm_h($dialPhone) . '" class="btn btn-primary btn-xs" title="Direct Call ' . csm_h($dialPhone) . '" style="background:#0284c7;border-color:#0284c7;color:#fff;"><i class="fas fa-phone"></i></a>' : '';
                $waBtn = !empty($waUrl) ? '<a href="' . csm_h($waUrl) . '" target="_blank" class="btn btn-success btn-xs" title="WhatsApp Reminder"><i class="fab fa-whatsapp"></i></a>' : '';
                $copyBtn = !empty($cleanDisplayPhone) ? '<button type="button" class="btn btn-default btn-xs" onclick="csmCopyText(\'' . csm_h(addslashes($cleanDisplayPhone)) . '\')" title="Copy Phone"><i class="far fa-copy"></i></button>' : '';

                $clientLink = '';
                if (!empty($r['userid'])) {
                    $clientLink = '<a href="clientssummary.php?userid=' . (int)$r['userid'] . '" target="_blank" style="font-weight:700;color:#0f5ea8;">' . csm_h($r['client_name']) . '</a> <a href="clientssummary.php?userid=' . (int)$r['userid'] . '" target="_blank" class="btn btn-default btn-xs" style="margin-left:4px;padding:1px 6px;font-size:10px;" title="WHMCS Client Profile"><i class="fas fa-user-check text-primary"></i> #' . (int)$r['userid'] . '</a>';
                } else {
                    $clientLink = '<strong style="color:#1e293b;">' . csm_h($r['client_name']) . '</strong> <span class="label label-default" style="font-size:10px;">Offline</span>';
                }

                // Due date countdown
                $dueBadge = '';
                $dueCategory = 'normal';
                if (!empty($r['next_due_date']) && $r['next_due_date'] !== '0000-00-00') {
                    $dTs = strtotime($r['next_due_date']);
                    $diff = (int)round(($dTs - $todayTs) / 86400);

                    if ($diff < 0) {
                        $dueCategory = 'overdue';
                        $dueBadge = '<span class="csm-badge csm-badge-overdue">' . abs($diff) . 'd Overdue</span>';
                    } elseif ($diff === 0) {
                        $dueCategory = 'today';
                        $dueBadge = '<span class="csm-badge csm-badge-warning">Due Today</span>';
                    } elseif ($diff <= $warningDays) {
                        $dueCategory = 'due7days';
                        $dueBadge = '<span class="csm-badge csm-badge-warning">' . $diff . 'd Left</span>';
                    } else {
                        $dueBadge = '<small class="text-muted">' . $diff . 'd left</small>';
                    }
                }

                // Grace override
                $overrideKey = $r['record_type'] === 'service' ? $r['id'] : 0;
                $override = $overrideKey && isset($overrides[$overrideKey]) ? $overrides[$overrideKey] : null;
                $graceHtml = '';
                if ($override) {
                    $graceHtml = '<span class="label label-primary" style="font-size:11px;" title="' . csm_h($override->reason ?: 'Extension granted') . '"><i class="fas fa-clock"></i> ' . date('d/m/Y', strtotime($override->grace_suspend_date)) . '</span> ' .
                        '<button class="btn btn-default btn-xs" onclick="openLiveGraceModal(' . $r['id'] . ', \'' . csm_h(addslashes($r['product_name'] . ' - ' . ($r['domain'] ?: $r['client_name']))) . '\', \'' . ($r['next_due_date'] ? date('d/m/Y', strtotime($r['next_due_date'])) : 'N/A') . '\')" title="Edit Deadline"><i class="fas fa-edit"></i></button>';
                } elseif ($r['record_type'] === 'service') {
                    $graceHtml = '<button class="btn btn-default btn-xs" onclick="openLiveGraceModal(' . $r['id'] . ', \'' . csm_h(addslashes($r['product_name'] . ' - ' . ($r['domain'] ?: $r['client_name']))) . '\', \'' . ($r['next_due_date'] ? date('d/m/Y', strtotime($r['next_due_date'])) : 'N/A') . '\')" title="Set Custom Grace Suspend Date"><i class="fas fa-plus"></i> Grace</button>';
                } else {
                    $graceHtml = '<span class="text-muted">—</span>';
                }

                // Notes
                $noteKey = $r['record_type'] . '_' . $r['id'];
                $itemNotes = isset($notes[$noteKey]) ? $notes[$noteKey] : null;
                $latestNote = $itemNotes ? $itemNotes->first() : null;
                $notePreview = $latestNote ? '<span class="label label-info" style="font-size:11px;" title="' . csm_h($latestNote->note) . '"><i class="fas fa-note-sticky"></i> ' . csm_h(substr($latestNote->note, 0, 16)) . '...</span>' : '<span style="color:#94a3b8;font-size:11px;">No note</span>';
                $noteBtn = '<button class="btn btn-default btn-xs" onclick="openNoteModal(\'' . $r['record_type'] . '\', ' . $r['id'] . ', \'' . csm_h(addslashes($r['product_name'] . ' (#' . $r['id'] . ')')) . '\', ' . (float)$r['price'] . ')" title="Add / View Note"><i class="fas fa-edit"></i></button>';

                // Domain
                $domainHtml = $r['domain'] ? '<a href="http://' . csm_h($r['domain']) . '" target="_blank" style="color:#1d4ed8;font-weight:600;"><i class="fas fa-globe"></i> ' . csm_h($r['domain']) . '</a>' : '<span class="text-muted">—</span>';

                // Manage Links
                $whmcsServiceLink = '';
                if ($r['record_type'] === 'service') {
                    $whmcsServiceLink = '<a href="clientsservices.php?id=' . $r['id'] . '" target="_blank" class="btn btn-default btn-xs" title="Manage Service in WHMCS"><i class="fas fa-external-link-alt"></i></a> ';
                } elseif ($r['record_type'] === 'domain') {
                    $whmcsServiceLink = '<a href="clientsdomains.php?id=' . $r['id'] . '" target="_blank" class="btn btn-default btn-xs" title="Manage Domain in WHMCS"><i class="fas fa-external-link-alt"></i></a> ';
                }

                $loginClientBtn = !empty($r['userid']) ? '<a href="dologin.php?userid=' . (int)$r['userid'] . '" target="_blank" class="btn btn-default btn-xs" title="Login as Client"><i class="fas fa-right-to-bracket"></i></a> ' : '';

                $customActionBtns = '';
                if ($r['is_custom']) {
                    $customActionBtns = '<button class="btn btn-default btn-xs" onclick=\'editCustomer(' . json_encode($r['raw_cust_obj']) . ')\' title="Edit Custom Service"><i class="fas fa-pen"></i></button> ' .
                        '<a href="' . csm_h($moduleLink) . '&action=delete_custom_customer&id=' . $r['id'] . '" class="btn btn-danger btn-xs" onclick="return csmConfirmDelete(this.href, \'Delete this custom service record?\')" title="Delete"><i class="fas fa-trash"></i></a>';
                }

                $searchCorpus = strtolower($r['id'] . ' ' . $r['client_name'] . ' ' . $r['company'] . ' ' . $r['email'] . ' ' . $cleanDisplayPhone . ' ' . $r['product_name'] . ' ' . $r['domain'] . ' ' . $r['status'] . ' ' . ($latestNote ? $latestNote->note : ''));

                $html .= '<tr class="csm-custom-row" data-search="' . csm_h($searchCorpus) . '" data-status="' . csm_h($statusLower) . '" data-due="' . csm_h($dueCategory) . '">
                    <td><strong>#' . $r['id'] . '</strong>' . ($r['is_custom'] ? '<br><span class="label label-default" style="font-size:9px;">Custom</span>' : '') . '</td>
                    <td>
                        <strong>' . csm_h($r['product_name']) . '</strong>
                        ' . ($r['server_name'] ? '<br><small class="text-muted"><i class="fas fa-server"></i> ' . csm_h($r['server_name']) . '</small>' : '') . '
                    </td>
                    <td>' . $domainHtml . '</td>
                    <td>
                        ' . $clientLink . '
                        ' . ($r['company'] ? '<br><small class="text-muted"><i class="fas fa-building"></i> ' . csm_h($r['company']) . '</small>' : '') . '
                        ' . ($r['email'] ? '<br><small><a href="mailto:' . csm_h($r['email']) . '" style="color:#64748b;"><i class="fas fa-envelope"></i> ' . csm_h($r['email']) . '</a></small>' : '') . '
                    </td>
                    <td>
                        <div style="font-size:12px;font-weight:700;color:#1e293b;">' . csm_h($cleanDisplayPhone ?: 'N/A') . '</div>
                        <div style="margin-top:3px;display:flex;gap:4px;">' . $callBtn . ' ' . $waBtn . ' ' . $copyBtn . '</div>
                    </td>
                    <td><strong>' . $currPrefix . number_format((float)$r['price'], 2) . $currSuffix . '</strong><br><small class="text-muted">' . csm_h($r['billing_cycle']) . '</small></td>
                    <td>
                        <strong>' . ((!empty($r['next_due_date']) && $r['next_due_date'] !== '0000-00-00') ? date('d/m/Y', strtotime($r['next_due_date'])) : 'N/A') . '</strong>
                        ' . ($dueBadge ? '<br>' . $dueBadge : '') . '
                    </td>
                    <td>' . $graceHtml . '</td>
                    <td>' . $notePreview . ' ' . $noteBtn . '</td>
                    <td><span class="csm-badge csm-badge-' . $statusClass . '">' . csm_h($r['status']) . '</span></td>
                    <td class="text-center" style="white-space:nowrap;">
                        ' . $whmcsServiceLink . '
                        ' . $loginClientBtn . '
                        ' . $customActionBtns . '
                    </td>
                </tr>';
            }
        }

        $html .= '</tbody></table></div></div>';

        // Modal 1: Add Monitored Clients Modal
        $clientPickerOptionsHtml = '';
        if (!empty($allClients)) {
            foreach ($allClients as $cl) {
                $phoneClean = str_replace('.', ' ', $cl->phonenumber ?: '');
                $compClean = $cl->companyname ? ' (' . $cl->companyname . ')' : '';
                $isAlreadyMonitored = in_array((int)$cl->id, $monitoredUserIds, true);
                $clientPickerOptionsHtml .= '<option value="' . $cl->id . '"' . ($isAlreadyMonitored ? ' selected' : '') . '>'
                    . '#' . $cl->id . ' - ' . csm_h(trim($cl->firstname . ' ' . $cl->lastname)) . csm_h($compClean) . ' - ' . csm_h($cl->email) . ($phoneClean ? ' | ' . csm_h($phoneClean) : '')
                    . '</option>';
            }
        }

        $html .= '
        <div id="csmAddMonitoredClientsModal" class="modal fade" tabindex="-1" role="dialog" style="display:none;">
            <div class="modal-dialog modal-md" role="document">
                <div class="modal-content" style="border-radius:10px;overflow:hidden;">
                    <form method="post" action="' . csm_h($moduleLink) . '&action=add_monitored_clients">
                        <div class="modal-header" style="background:#12589b;color:#fff;padding:16px 20px;">
                            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:0.9;font-size:24px;">&times;</button>
                            <h4 class="modal-title" style="font-weight:700;"><i class="fas fa-user-plus"></i> Select Clients to Monitor</h4>
                        </div>
                        <div class="modal-body" style="padding:22px;background:#f8fafc;">
                            <div class="form-group">
                                <label style="font-weight:700;color:#0f5ea8;"><i class="fas fa-search"></i> Search &amp; Select WHMCS Clients <span class="text-danger">*</span></label>
                                <select name="client_ids[]" id="csmSelectMonitoredClients" class="form-control" multiple="multiple" style="width:100%;" required>
                                    ' . $clientPickerOptionsHtml . '
                                </select>
                                <small class="help-block" style="margin-top:8px;">
                                    Search clients by Name, Company, Email or Phone. Select one or multiple clients to add to your dedicated live monitor board.
                                </small>
                            </div>
                        </div>
                        <div class="modal-footer" style="background:#f1f5f9;">
                            <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Monitored Clients</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>';

        // Modal 2: Add / Edit Custom Service Modal
        $clientSinglePickerOptions = '<option value="0">-- Offline / Non-WHMCS Client --</option>';
        if (!empty($allClients)) {
            foreach ($allClients as $cl) {
                $phoneClean = str_replace('.', ' ', $cl->phonenumber ?: '');
                $compClean = $cl->companyname ? ' (' . $cl->companyname . ')' : '';
                $fullName = trim($cl->firstname . ' ' . $cl->lastname);
                $clientSinglePickerOptions .= '<option value="' . $cl->id . '"'
                    . ' data-name="' . csm_h($fullName) . '"'
                    . ' data-company="' . csm_h($cl->companyname ?: '') . '"'
                    . ' data-email="' . csm_h($cl->email ?: '') . '"'
                    . ' data-phone="' . csm_h($phoneClean) . '">'
                    . '#' . $cl->id . ' - ' . csm_h($fullName) . csm_h($compClean) . ' - ' . csm_h($cl->email) . ($phoneClean ? ' | ' . csm_h($phoneClean) : '')
                    . '</option>';
            }
        }

        $html .= '
        <div id="csmCustomerModal" class="modal fade" tabindex="-1" role="dialog" style="display:none;">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content" style="border-radius:10px;overflow:hidden;">
                    <form method="post" action="' . csm_h($moduleLink) . '&action=save_custom_customer">
                        <input type="hidden" name="customer_id" id="modalCustomerId" value="0">
                        <input type="hidden" name="userid" id="csmCustUserId" value="0">
                        <div class="modal-header" style="background:#12589b;color:#fff;padding:16px 22px;">
                            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:0.9;font-size:24px;">&times;</button>
                            <h4 class="modal-title" id="modalCustomerTitle" style="font-weight:700;"><i class="fas fa-box-archive"></i> Add Custom / Offline Service</h4>
                        </div>
                        <div class="modal-body" style="padding:22px;background:#f8fafc;">
                            <div class="form-group" style="background:#ffffff;padding:14px;border-radius:8px;border:1px solid #cbd5e1;box-shadow:0 2px 4px rgba(0,0,0,0.02);margin-bottom:18px;">
                                <label style="color:#0f5ea8;font-weight:700;font-size:13.5px;"><i class="fas fa-search"></i> Select WHMCS Client (Optional)</label>
                                <select id="csmSingleClientSelect" class="form-control" style="width:100%;">
                                    ' . $clientSinglePickerOptions . '
                                </select>
                            </div>

                            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:15px;">
                                <h5 style="font-weight:800;color:#0f5ea8;margin-top:0;margin-bottom:14px;border-bottom:1px solid #f1f5f9;padding-bottom:6px;">
                                    <i class="fas fa-user-tag"></i> Client Information
                                </h5>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label>Client Name <span class="text-danger">*</span></label>
                                        <input type="text" name="customer_name" id="csmCustName" class="form-control" required placeholder="e.g. John Doe">
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label>Company Name</label>
                                        <input type="text" name="company_name" id="csmCustCompany" class="form-control" placeholder="e.g. Acme Corp">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label>Phone / WhatsApp Number</label>
                                        <input type="text" name="phone" id="csmCustPhone" class="form-control" placeholder="e.g. 01911-726447">
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label>Email Address</label>
                                        <input type="email" name="email" id="csmCustEmail" class="form-control" placeholder="e.g. client@example.com">
                                    </div>
                                </div>
                            </div>

                            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">
                                <h5 style="font-weight:800;color:#0f5ea8;margin-top:0;margin-bottom:14px;border-bottom:1px solid #f1f5f9;padding-bottom:6px;">
                                    <i class="fas fa-server"></i> Service &amp; Billing Details
                                </h5>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label>Service / Item Title <span class="text-danger">*</span></label>
                                        <input type="text" name="service_name" id="csmCustService" class="form-control" required placeholder="e.g. Dedicated Server Support / SEO Maintenance">
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label>Domain Name (Optional)</label>
                                        <input type="text" name="domain" id="csmCustDomain" class="form-control" placeholder="e.g. clientdomain.com">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 form-group">
                                        <label>Amount / Due (' . csm_h(trim($currPrefix . $currSuffix)) . ') <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" name="amount" id="csmCustAmount" class="form-control" value="0.00" required>
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label>Billing Cycle</label>
                                        <select name="billing_cycle" id="csmCustCycle" class="form-control">
                                            <option value="Monthly">Monthly</option>
                                            <option value="Quarterly">Quarterly</option>
                                            <option value="Semi-Annually">Semi-Annually</option>
                                            <option value="Annually">Annually</option>
                                            <option value="One Time">One Time</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label>Next Due Date</label>
                                        <input type="date" name="next_due_date" id="csmCustDueDate" class="form-control">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label>Status</label>
                                        <select name="status" id="csmCustStatus" class="form-control">
                                            <option value="Active">Active</option>
                                            <option value="Unpaid">Unpaid</option>
                                            <option value="Suspended">Suspended</option>
                                            <option value="Paid">Paid</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label>Initial Due Note / Remarks</label>
                                        <input type="text" name="notes" id="csmCustNotes" class="form-control" placeholder="e.g. Advance 500 Tk received">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer" style="background:#f1f5f9;">
                            <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Custom Service</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>';

        // Modal 3: Universal Due Note Modal
        $html .= '
        <div class="modal fade" id="csmNoteModal" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-md" role="document">
                <div class="modal-content" style="border-radius:8px;">
                    <div class="modal-header" style="background:#12589b;color:#fff;border-radius:7px 7px 0 0;">
                        <button type="button" class="close" data-dismiss="modal" style="color:#fff;">&times;</button>
                        <h4 class="modal-title"><i class="fas fa-book-bookmark"></i> Service Due Note &amp; Ledger</h4>
                    </div>
                    <div class="modal-body" style="padding:20px;">
                        <div class="form-group">
                            <label>Target Service / Client:</label>
                            <input type="text" id="modalNoteTarget" class="form-control" readonly style="background:#f8fafc;font-weight:700;">
                            <input type="hidden" id="modalNoteRelType" value="service">
                            <input type="hidden" id="modalNoteRelId" value="0">
                        </div>

                        <div class="form-group">
                            <label>Add New Note / Payment Remark <span class="text-danger">*</span></label>
                            <textarea id="modalNoteText" class="form-control" rows="2" placeholder="e.g. Paid 500 Tk via bKash, remaining due on 25th"></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label>Paid Amount</label>
                                <input type="number" step="0.01" id="modalNotePaid" class="form-control" placeholder="0.00">
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Remaining Due</label>
                                <input type="number" step="0.01" id="modalNoteDue" class="form-control" placeholder="0.00">
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Promised Date</label>
                                <input type="date" id="modalNotePromised" class="form-control">
                            </div>
                        </div>

                        <button type="button" class="btn btn-primary btn-block" id="btnSaveNoteAjax" onclick="csmSaveCustomNoteAjax()">
                            <i class="fas fa-plus"></i> Save Note / Record Entry
                        </button>

                        <!-- Previous Notes History -->
                        <div style="margin-top:20px;">
                            <h5 style="font-weight:800;color:#1e293b;border-bottom:1px solid #e2e8f0;padding-bottom:6px;">
                                <i class="fas fa-history"></i> Previous Notes History
                            </h5>
                            <div id="modalNotesHistoryList" style="max-height:180px;overflow-y:auto;font-size:12px;margin-top:8px;">
                                <p class="text-muted">Loading history...</p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="background:#f8fafc;">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>';

        // Modal 4: Custom Suspend Deadline (Grace Period)
        $html .= '
        <div class="modal fade" id="csmLiveGraceModal" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-md" role="document">
                <div class="modal-content" style="border-radius:8px;">
                    <div class="modal-header" style="background:#12589b;color:#fff;border-radius:7px 7px 0 0;">
                        <button type="button" class="close" data-dismiss="modal" style="color:#fff;">&times;</button>
                        <h4 class="modal-title"><i class="fas fa-clock-rotate-left"></i> Set Custom Suspend Deadline</h4>
                    </div>
                    <div class="modal-body" style="padding:20px;">
                        <div class="form-group">
                            <label>Target Service / Domain:</label>
                            <input type="text" id="liveGraceTarget" class="form-control" readonly style="background:#f8fafc;font-weight:700;">
                            <input type="hidden" id="liveGraceServiceId" value="0">
                        </div>

                        <div class="form-group">
                            <label>Custom Suspend Date (Grace Deadline) <span class="text-danger">*</span></label>
                            <input type="date" id="liveGraceDate" class="form-control" required>
                            <small class="help-block" style="color:#2563eb;">
                                WHMCS <code>Next Due Date</code> will remain unchanged. Automatic suspension will be postponed until this date.
                            </small>
                        </div>

                        <div class="form-group">
                            <label>Reason / Extension Remark</label>
                            <textarea id="liveGraceReason" class="form-control" rows="2" placeholder="e.g. Granted 7 days extension per client request"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer" style="background:#f8fafc;">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="csmSaveCustomGraceAjax()"><i class="fas fa-save"></i> Save Deadline</button>
                    </div>
                </div>
            </div>
        </div>';

        // JavaScript for Custom Clients Live Monitor
        $html .= '
        <script>
        var CSM_MODULE_LINK = "' . addslashes($moduleLink) . '";
        var CSM_NOTE_URL = CSM_MODULE_LINK + "&ajax=save_note";
        var CSM_EDIT_NOTE_URL = CSM_MODULE_LINK + "&ajax=edit_note";
        var CSM_DELETE_NOTE_URL = CSM_MODULE_LINK + "&ajax=delete_note";
        var CSM_GET_NOTES_URL = CSM_MODULE_LINK + "&ajax=get_notes";
        var currentEditingNoteId = 0;

        $(document).ready(function() {
            if ($.fn.select2) {
                $("#csmSelectMonitoredClients").select2({
                    placeholder: "Search client by name, email, phone, company...",
                    allowClear: true,
                    width: "100%",
                    dropdownParent: $("#csmAddMonitoredClientsModal")
                });

                $("#csmSingleClientSelect").select2({
                    placeholder: "Search WHMCS client...",
                    allowClear: true,
                    width: "100%",
                    dropdownParent: $("#csmCustomerModal")
                });

                $("#csmSingleClientSelect").on("change", function() {
                    var val = $(this).val();
                    if (val && val !== "0") {
                        var $opt = $("#csmSingleClientSelect option[value=\'" + val + "\']");
                        $("#csmCustUserId").val(val);
                        $("#csmCustName").val($opt.data("name") || "");
                        $("#csmCustCompany").val($opt.data("company") || "");
                        $("#csmCustPhone").val($opt.data("phone") || "");
                        $("#csmCustEmail").val($opt.data("email") || "");
                    } else {
                        $("#csmCustUserId").val("0");
                    }
                });
            }

            // Real-Time Table Live Search & Filter
            $("#customMonitorSearchInput").on("input", function() {
                applyCustomMonitorFilters();
            });

            $("#customMonitorSearchClear").on("click", function() {
                $("#customMonitorSearchInput").val("");
                applyCustomMonitorFilters();
            });

            $("#customMonitorStatusFilter").on("change", function() {
                applyCustomMonitorFilters();
            });
        });

        function applyCustomMonitorFilters() {
            var search = ($("#customMonitorSearchInput").val() || "").toLowerCase().trim();
            var statusFilter = ($("#customMonitorStatusFilter").val() || "").toLowerCase().trim();
            var visibleCount = 0;

            $(".csm-custom-row").each(function() {
                var $row = $(this);
                var rowSearch = $row.data("search") || "";
                var rowStatus = ($row.data("status") || "").toString().toLowerCase();
                var rowDue = ($row.data("due") || "").toString().toLowerCase();

                var matchesSearch = !search || rowSearch.indexOf(search) > -1;
                var matchesStatus = true;

                if (statusFilter) {
                    if (statusFilter === "due7days" || statusFilter === "today" || statusFilter === "overdue") {
                        matchesStatus = (rowDue === statusFilter);
                    } else {
                        matchesStatus = (rowStatus === statusFilter);
                    }
                }

                if (matchesSearch && matchesStatus) {
                    $row.show();
                    visibleCount++;
                } else {
                    $row.hide();
                }
            });

            if (visibleCount === 0 && $(".csm-custom-row").length > 0) {
                if ($("#csmCustomNoMatchRow").length === 0) {
                    $("#csmCustomMonitorTableBody").append("<tr id=\'csmCustomNoMatchRow\'><td colspan=\'11\' style=\'text-align:center;padding:30px;color:#64748b;\'><i class=\'fas fa-search\'></i> No services match your filter.</td></tr>");
                }
                $("#csmCustomNoMatchRow").show();
            } else {
                $("#csmCustomNoMatchRow").hide();
            }
        }

        window.csmFilterCustom = function(statusVal) {
            $("#customMonitorStatusFilter").val(statusVal);
            applyCustomMonitorFilters();
        };

        window.openManageMonitoredClientsModal = function() {
            $("#csmAddMonitoredClientsModal").modal("show");
        };

        window.openAddCustomerModal = function() {
            document.getElementById("modalCustomerId").value = "0";
            document.getElementById("csmCustUserId").value = "0";
            document.getElementById("modalCustomerTitle").innerHTML = "<i class=\'fas fa-plus\'></i> Add Custom / Offline Service";
            if ($.fn.select2) {
                $("#csmSingleClientSelect").val("0").trigger("change");
            }
            document.getElementById("csmCustName").value = "";
            document.getElementById("csmCustCompany").value = "";
            document.getElementById("csmCustPhone").value = "";
            document.getElementById("csmCustEmail").value = "";
            document.getElementById("csmCustService").value = "";
            document.getElementById("csmCustDomain").value = "";
            document.getElementById("csmCustAmount").value = "0.00";
            document.getElementById("csmCustCycle").value = "Monthly";
            document.getElementById("csmCustDueDate").value = "";
            document.getElementById("csmCustStatus").value = "Active";
            document.getElementById("csmCustNotes").value = "";
            $("#csmCustomerModal").modal("show");
        };

        window.editCustomer = function(item) {
            document.getElementById("modalCustomerId").value = item.id;
            document.getElementById("csmCustUserId").value = item.userid || "0";
            document.getElementById("modalCustomerTitle").innerHTML = "<i class=\'fas fa-edit\'></i> Edit Custom Service (#" + item.id + ")";
            if ($.fn.select2) {
                $("#csmSingleClientSelect").val(item.userid ? String(item.userid) : "0").trigger("change");
            }
            document.getElementById("csmCustName").value = item.customer_name || "";
            document.getElementById("csmCustCompany").value = item.company_name || "";
            document.getElementById("csmCustPhone").value = item.phone || "";
            document.getElementById("csmCustEmail").value = item.email || "";
            document.getElementById("csmCustService").value = item.service_name || "";
            document.getElementById("csmCustDomain").value = item.domain || "";
            document.getElementById("csmCustAmount").value = item.amount || "0.00";
            document.getElementById("csmCustCycle").value = item.billing_cycle || "Monthly";
            document.getElementById("csmCustDueDate").value = item.next_due_date || "";
            document.getElementById("csmCustStatus").value = item.status || "Active";
            document.getElementById("csmCustNotes").value = item.notes || "";
            $("#csmCustomerModal").modal("show");
        };

        window.csmCopyText = function(text) {
            if (!text) return;
            navigator.clipboard.writeText(text);
            if (typeof Swal !== "undefined") {
                const Toast = Swal.mixin({ toast: true, position: "top-end", showConfirmButton: false, timer: 2000, timerProgressBar: true });
                Toast.fire({ icon: "success", title: "Copied: " + text });
            } else {
                alert("Copied: " + text);
            }
        };

        // Note Handling
        window.openNoteModal = function(relType, relId, targetName, dueAmount) {
            currentEditingNoteId = 0;
            $("#modalNoteRelType").val(relType);
            $("#modalNoteRelId").val(relId);
            $("#modalNoteTarget").val(targetName);
            $("#modalNoteText").val("");
            $("#modalNotePaid").val("");
            $("#modalNoteDue").val(dueAmount || "");
            $("#modalNotePromised").val("");
            $("#btnSaveNoteAjax").html("<i class=\'fas fa-plus\'></i> Save Note / Record Entry");
            $("#modalNotesHistoryList").html("<p class=\'text-muted\'><i class=\'fas fa-spinner fa-spin\'></i> Loading history...</p>");

            loadCustomNotesHistory(relType, relId);
            $("#csmNoteModal").modal("show");
        };

        function loadCustomNotesHistory(relType, relId) {
            $.ajax({
                url: CSM_GET_NOTES_URL + "&rel_type=" + encodeURIComponent(relType) + "&rel_id=" + encodeURIComponent(relId),
                type: "GET",
                dataType: "json",
                success: function(res) {
                    if (res && res.success && res.notes && res.notes.length > 0) {
                        var histHtml = "";
                        res.notes.forEach(function(n) {
                            var nJson = $("<div>").text(JSON.stringify(n)).html();
                            histHtml += "<div style=\'background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px 10px;margin-bottom:8px;\'>" +
                                "<div style=\'display:flex;justify-content:space-between;align-items:flex-start;\'>" +
                                "<div style=\'font-weight:700;color:#1e293b;flex-grow:1;padding-right:10px;\'>" + $("<div>").text(n.note).html() + "</div>" +
                                "<div style=\'white-space:nowrap;display:flex;gap:4px;\'>" +
                                "<button type=\'button\' class=\'btn btn-default btn-xs\' onclick=\'csmInlineEditCustomNote(" + n.id + ", " + nJson + ")\' title=\'Edit Note\'><i class=\'fas fa-edit text-primary\'></i></button>" +
                                "<button type=\'button\' class=\'btn btn-danger btn-xs\' onclick=\'csmInlineDeleteCustomNote(" + n.id + ")\' title=\'Delete Note\'><i class=\'fas fa-trash\'></i></button>" +
                                "</div>" +
                                "</div>" +
                                "<div style=\'font-size:11px;color:#64748b;margin-top:4px;\'>" +
                                (n.paid_amount ? "<span style=\'color:#16a34a;font-weight:700;\'>Paid: " + n.paid_amount + "</span> &bull; " : "") +
                                (n.due_amount ? "<span style=\'color:#dc2626;font-weight:700;\'>Due: " + n.due_amount + "</span> &bull; " : "") +
                                (n.promised_date ? "<span style=\'color:#2563eb;font-weight:600;\'>Promised: " + n.promised_date + "</span> &bull; " : "") +
                                "<span>" + n.created_at + " (" + (n.admin_name || "Admin") + ")</span>" +
                                "</div></div>";
                        });
                        $("#modalNotesHistoryList").html(histHtml);
                    } else {
                        $("#modalNotesHistoryList").html("<p class=\'text-muted\' style=\'margin:0;\'>No previous notes recorded.</p>");
                    }
                }
            });
        }

        window.csmInlineEditCustomNote = function(noteId, noteObj) {
            currentEditingNoteId = noteId;
            $("#modalNoteText").val(noteObj.note || "");
            $("#modalNotePaid").val(noteObj.paid_amount || "");
            $("#modalNoteDue").val(noteObj.due_amount || "");
            $("#modalNotePromised").val(noteObj.promised_date || "");
            $("#btnSaveNoteAjax").html("<i class=\'fas fa-save\'></i> Update Note Entry");
            $("#modalNoteText").focus();
        };

        window.csmInlineDeleteCustomNote = function(noteId) {
            if (typeof Swal !== "undefined") {
                Swal.fire({
                    title: "Delete Note Entry?",
                    text: "Are you sure you want to delete this note permanently?",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonColor: "#dc2626",
                    cancelButtonColor: "#64748b",
                    confirmButtonText: "Yes, Delete"
                }).then(function(result) {
                    if (result.isConfirmed) {
                        performDeleteNoteAjax(noteId);
                    }
                });
            } else {
                if (confirm("Delete this note permanently?")) {
                    performDeleteNoteAjax(noteId);
                }
            }
        };

        function performDeleteNoteAjax(noteId) {
            $.ajax({
                url: CSM_DELETE_NOTE_URL,
                type: "POST",
                data: { note_id: noteId },
                dataType: "json",
                success: function(res) {
                    if (res && res.success) {
                        if (typeof Swal !== "undefined") {
                            const Toast = Swal.mixin({ toast: true, position: "top-end", showConfirmButton: false, timer: 2000 });
                            Toast.fire({ icon: "success", title: "Note deleted successfully" });
                        }
                        var relType = $("#modalNoteRelType").val();
                        var relId = $("#modalNoteRelId").val();
                        loadCustomNotesHistory(relType, relId);
                    }
                }
            });
        }

        window.csmSaveCustomNoteAjax = function() {
            var relType = $("#modalNoteRelType").val();
            var relId = $("#modalNoteRelId").val();
            var note = $("#modalNoteText").val().trim();
            var paid = $("#modalNotePaid").val();
            var due = $("#modalNoteDue").val();
            var promised = $("#modalNotePromised").val();

            if (!note) {
                if (typeof Swal !== "undefined") {
                    Swal.fire({ icon: "warning", title: "Note Required", text: "Please enter a note / remark text before saving." });
                } else {
                    alert("Please enter a note / remark text before saving.");
                }
                return;
            }

            var isEditing = currentEditingNoteId > 0;
            var targetUrl = isEditing ? CSM_EDIT_NOTE_URL : CSM_NOTE_URL;
            var postData = {
                rel_type: relType,
                rel_id: relId,
                note: note,
                paid_amount: paid,
                due_amount: due,
                promised_date: promised
            };
            if (isEditing) {
                postData.note_id = currentEditingNoteId;
            }

            $("#btnSaveNoteAjax").prop("disabled", true).html("<i class=\'fas fa-spinner fa-spin\'></i> Saving...");

            $.ajax({
                url: targetUrl,
                type: "POST",
                data: postData,
                dataType: "json",
                success: function(res) {
                    $("#btnSaveNoteAjax").prop("disabled", false).html("<i class=\'fas fa-plus\'></i> Save Note / Record Entry");
                    if (res && res.success) {
                        if (typeof Swal !== "undefined") {
                            const Toast = Swal.mixin({ toast: true, position: "top-end", showConfirmButton: false, timer: 2000 });
                            Toast.fire({ icon: "success", title: isEditing ? "Note updated successfully" : "Note added successfully" });
                        }
                        currentEditingNoteId = 0;
                        $("#modalNoteText").val("");
                        $("#modalNotePaid").val("");
                        $("#modalNotePromised").val("");
                        loadCustomNotesHistory(relType, relId);
                    } else {
                        if (typeof Swal !== "undefined") {
                            Swal.fire({ icon: "error", title: "Save Failed", text: res.error || "Failed to save note." });
                        }
                    }
                },
                error: function() {
                    $("#btnSaveNoteAjax").prop("disabled", false).html("<i class=\'fas fa-plus\'></i> Save Note / Record Entry");
                }
            });
        };

        // Modal: Grace Period
        window.openLiveGraceModal = function (serviceId, targetName, currentDueDate) {
            $("#liveGraceServiceId").val(serviceId);
            $("#liveGraceTarget").val(targetName + " (Due: " + currentDueDate + ")");
            $("#liveGraceDate").val("");
            $("#liveGraceReason").val("");
            $("#csmLiveGraceModal").modal("show");
        };

        window.csmSaveCustomGraceAjax = function () {
            var serviceId = $("#liveGraceServiceId").val();
            var graceDate = $("#liveGraceDate").val();
            var reason = $("#liveGraceReason").val().trim();

            if (!graceDate) {
                if (typeof Swal !== "undefined") {
                    Swal.fire({ icon: "warning", title: "Date Required", text: "Please select a custom grace suspend date." });
                } else {
                    alert("Please select a custom grace suspend date.");
                }
                return;
            }

            $.ajax({
                url: CSM_MODULE_LINK + "&action=save_grace_suspend",
                type: "POST",
                data: { service_id: serviceId, grace_suspend_date: graceDate, reason: reason },
                success: function () {
                    $("#csmLiveGraceModal").modal("hide");
                    if (typeof Swal !== "undefined") {
                        const Toast = Swal.mixin({ toast: true, position: "top-end", showConfirmButton: false, timer: 2500 });
                        Toast.fire({ icon: "success", title: "Custom grace deadline saved." });
                    }
                    setTimeout(function() { window.location.reload(); }, 1000);
                },
                error: function () {
                    if (typeof Swal !== "undefined") {
                        Swal.fire({ icon: "error", title: "Error", text: "Error saving suspension grace deadline." });
                    }
                }
            });
        };
        </script>';

        return $html;
    }
}

/**
 * Page: Custom Suspend Overdue Manager
 */
if (!function_exists('csm_render_suspension_manager_page')) {
    function csm_render_suspension_manager_page($vars)
    {
        $moduleLink = $vars['modulelink'];
        $overrides = Capsule::table('mod_csm_suspension_overrides')
            ->join('tblhosting', 'mod_csm_suspension_overrides.service_id', '=', 'tblhosting.id')
            ->join('tblclients', 'tblhosting.userid', '=', 'tblclients.id')
            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
            ->select(
                'mod_csm_suspension_overrides.*',
                'tblhosting.domain',
                'tblhosting.nextduedate as whmcs_due_date',
                'tblhosting.domainstatus as service_status',
                'tblhosting.amount',
                'tblproducts.name as product_name',
                'tblclients.firstname',
                'tblclients.lastname',
                'tblclients.phonenumber'
            )
            ->orderBy('mod_csm_suspension_overrides.id', 'DESC')
            ->get();

        $html = '';
        if (isset($_GET['saved'])) {
            $html .= '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Custom Grace Suspend date saved successfully.</div>';
        }
        if (isset($_GET['deleted'])) {
            $html .= '<div class="alert alert-info"><i class="fas fa-info-circle"></i> Suspension override removed. WHMCS default automation resumed.</div>';
        }

        $activeOverridesCount = $overrides->where('status', 'active')->count();

        $html .= '<div class="csm-card" style="margin-bottom:18px;">
            <div style="padding:20px;">
                <h3 style="margin:0 0 10px;font-size:18px;font-weight:800;color:#172033;">
                    <i class="fas fa-clock-rotate-left text-primary"></i> Custom Suspend Overdue &amp; Grace Period Manager
                </h3>
                <p class="csm-muted" style="margin-bottom:14px;">
                    This feature allows you to grant a <strong>Custom Suspension Grace Period</strong> to any client service without altering their WHMCS <code>Next Due Date</code> or billing invoice cycle. The automated hook protects the service from automatic WHMCS suspension until the custom grace date expires.
                </p>
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;color:#1e40af;font-size:13px;">
                    <i class="fas fa-shield-alt"></i> <strong>Safe Execution:</strong> WHMCS <code>Next Due Date</code> and invoice generation remain 100% untouched. When the custom deadline passes and the invoice is still unpaid, the module automatically executes the suspension.
                </div>
            </div>
        </div>';

        $html .= '<div class="csm-table-card">
            <div class="csm-table-header">
                <div>
                    <h3>Active Suspension Grace Deadlines</h3>
                    <span class="label label-primary">' . $activeOverridesCount . ' Active Overrides</span>
                </div>
                <div>
                    <button class="btn btn-primary btn-sm" onclick="openAddGraceModal()"><i class="fas fa-plus"></i> Set Custom Suspend Deadline</button>
                </div>
            </div>
            <div style="overflow-x:auto;">
                <table class="csm-table">
                    <thead>
                        <tr>
                            <th>Service &amp; Domain</th>
                            <th>Client Info</th>
                            <th>Original Due Date</th>
                            <th>Custom Suspend Date</th>
                            <th>Grace Remaining</th>
                            <th>Reason / Note</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>';

        if ($overrides->isEmpty()) {
            $html .= '<tr><td colspan="8" style="text-align:center;padding:40px;color:#64748b;">
                <i class="fas fa-clock" style="font-size:32px;margin-bottom:10px;display:block;opacity:0.5;"></i>
                No active custom suspension overrides. Click <strong>"Set Custom Suspend Deadline"</strong> to add an extension.
            </td></tr>';
        } else {
            foreach ($overrides as $ov) {
                $today = new DateTime(date('Y-m-d'));
                $graceDate = new DateTime($ov->grace_suspend_date);
                $diff = (int)$today->diff($graceDate)->format('%r%a');

                $daysLabel = '';
                if ($diff > 0) {
                    $daysLabel = '<span class="label label-success">' . $diff . ' days remaining</span>';
                } elseif ($diff === 0) {
                    $daysLabel = '<span class="label label-warning">Expires Today</span>';
                } else {
                    $daysLabel = '<span class="label label-danger">' . abs($diff) . ' days overdue</span>';
                }

                $html .= '<tr>
                    <td>
                        <strong>#' . $ov->service_id . ' - ' . csm_h($ov->product_name) . '</strong>
                        ' . ($ov->domain ? '<br><small style="color:#1d4ed8;">' . csm_h($ov->domain) . '</small>' : '') . '
                    </td>
                    <td>
                        <strong>' . csm_h($ov->firstname . ' ' . $ov->lastname) . '</strong>
                        <br><small><i class="fas fa-phone"></i> ' . csm_h($ov->phonenumber ?: 'N/A') . '</small>
                    </td>
                    <td><code>' . date('d/m/Y', strtotime($ov->whmcs_due_date)) . '</code></td>
                    <td><strong style="color:#2563eb;">' . date('d/m/Y', strtotime($ov->grace_suspend_date)) . '</strong></td>
                    <td>' . $daysLabel . '</td>
                    <td><span style="font-size:12px;">' . csm_h($ov->reason ?: 'Extension granted') . '</span></td>
                    <td><span class="csm-badge csm-badge-' . ($ov->status === 'active' ? 'active' : 'suspended') . '">' . csm_h(ucfirst($ov->status)) . '</span></td>
                    <td>
                        <a href="' . csm_h($moduleLink) . '&action=delete_grace_suspend&id=' . $ov->id . '" class="btn btn-danger btn-xs" onclick="return csmConfirmDelete(this.href, \'Remove custom grace override and resume standard WHMCS automation?\')"><i class="fas fa-trash"></i> Remove</a>
                    </td>
                </tr>';
            }
        }

        $html .= '</tbody></table></div></div>';

        // Modal for Add Grace Deadline
        $html .= '
        <div id="csmGraceModal" class="modal fade" tabindex="-1" role="dialog" style="display:none;">
            <div class="modal-dialog modal-md" role="document">
                <div class="modal-content" style="border-radius:8px;">
                    <form method="post" action="' . csm_h($moduleLink) . '&action=save_grace_suspend">
                        <div class="modal-header" style="background:#12589b;color:#fff;border-radius:7px 7px 0 0;">
                            <button type="button" class="close" data-dismiss="modal" style="color:#fff;">&times;</button>
                            <h4 class="modal-title"><i class="fas fa-clock-rotate-left"></i> Set Custom Suspend Deadline</h4>
                        </div>
                        <div class="modal-body" style="padding:20px;">
                            <div class="form-group">
                                <label>WHMCS Service ID <span class="text-danger">*</span></label>
                                <input type="number" name="service_id" id="graceServiceId" class="form-control" required placeholder="Enter Service / Hosting ID (e.g. 1024)">
                                <small class="help-block">Enter the WHMCS Service ID (tblhosting.id).</small>
                            </div>
                            <div class="form-group">
                                <label>Custom Suspend Date (Grace Deadline) <span class="text-danger">*</span></label>
                                <input type="date" name="grace_suspend_date" id="graceSuspendDate" class="form-control" required>
                                <small class="help-block">Service will NOT be auto-suspended by WHMCS until this date passes.</small>
                            </div>
                            <div class="form-group">
                                <label>Reason / Admin Note</label>
                                <textarea name="reason" id="graceReason" class="form-control" rows="2" placeholder="e.g. Client requested 7 days extension for salary issue"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer" style="background:#f8fafc;">
                            <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Grace Override</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        function openAddGraceModal(serviceId, currentDue) {
            document.getElementById("graceServiceId").value = serviceId || "";
            document.getElementById("graceReason").value = "";
            $("#csmGraceModal").modal("show");
        }
        </script>';

        return $html;
    }
}

/**
 * Page: Due Notes / Ledger
 */
if (!function_exists('csm_render_due_notes_page')) {
    function csm_render_due_notes_page($vars)
    {
        $moduleLink = $vars['modulelink'];
        $notes = Capsule::table('mod_csm_service_notes')->orderBy('id', 'DESC')->take(200)->get();

        $activeCurrency = csm_get_active_currency();
        $currPrefix = ($activeCurrency && !empty($activeCurrency->prefix)) ? $activeCurrency->prefix : '৳ ';
        $currSuffix = ($activeCurrency && !empty($activeCurrency->suffix)) ? $activeCurrency->suffix : '';

        $html = '';
        if (isset($_GET['saved'])) {
            $html .= '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Due Note updated successfully.</div>';
        }
        if (isset($_GET['deleted'])) {
            $html .= '<div class="alert alert-info"><i class="fas fa-info-circle"></i> Due Note deleted.</div>';
        }

        $html .= '<div class="csm-table-card">
            <div class="csm-table-header">
                <div>
                    <h3><i class="fas fa-book-bookmark text-primary"></i> Service Due Notes &amp; Payment Ledger</h3>
                    <div class="csm-muted">History of all payment remarks, partial payments, and promised due dates.</div>
                </div>
            </div>
            <div style="overflow-x:auto;">
                <table class="csm-table">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Target Entity</th>
                            <th>ID</th>
                            <th>Note / Remarks</th>
                            <th>Paid Amount</th>
                            <th>Due Amount</th>
                            <th>Promised Date</th>
                            <th>Admin</th>
                            <th width="90" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>';

        if ($notes->isEmpty()) {
            $html .= '<tr><td colspan="9" style="text-align:center;padding:40px;color:#64748b;">
                <i class="fas fa-note-sticky" style="font-size:32px;margin-bottom:10px;display:block;opacity:0.5;"></i>
                No due notes found. Notes added from Live Monitor or Custom Customers will appear here.
            </td></tr>';
        } else {
            foreach ($notes as $n) {
                $targetLink = '#' . $n->rel_id;
                if ($n->rel_type === 'service') {
                    $targetLink = '<a href="clientsservices.php?id=' . $n->rel_id . '" target="_blank" style="font-weight:700;color:#0f5ea8;">#' . $n->rel_id . '</a>';
                } elseif ($n->rel_type === 'domain') {
                    $targetLink = '<a href="clientsdomains.php?id=' . $n->rel_id . '" target="_blank" style="font-weight:700;color:#0f5ea8;">#' . $n->rel_id . '</a>';
                }

                $html .= '<tr>
                    <td>' . date('d/m/Y H:i', strtotime($n->created_at)) . '</td>
                    <td><span class="label label-info">' . csm_h(strtoupper($n->rel_type)) . '</span></td>
                    <td>' . $targetLink . '</td>
                    <td><strong>' . csm_h($n->note) . '</strong></td>
                    <td>' . ($n->paid_amount !== null ? '<span style="color:#16a34a;font-weight:700;">+' . $currPrefix . number_format((float)$n->paid_amount, 2) . $currSuffix . '</span>' : '—') . '</td>
                    <td>' . ($n->due_amount !== null ? '<span style="color:#dc2626;font-weight:700;">' . $currPrefix . number_format((float)$n->due_amount, 2) . $currSuffix . '</span>' : '—') . '</td>
                    <td>' . ($n->promised_date ? date('d/m/Y', strtotime($n->promised_date)) : '—') . '</td>
                    <td>' . csm_h($n->admin_name ?: 'Admin') . '</td>
                    <td class="text-center">
                        <button class="btn btn-default btn-xs" onclick=\'openEditNotePageModal(' . json_encode($n) . ')\' title="Edit Note"><i class="fas fa-pen"></i></button>
                        <a href="' . csm_h($moduleLink) . '&action=delete_note&id=' . $n->id . '" class="btn btn-danger btn-xs" onclick="return csmConfirmDelete(this.href, \'Delete this note entry permanently?\')" title="Delete Note"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>';
            }
        }

        $html .= '</tbody></table></div></div>';

        // Edit Note Modal
        $html .= '
        <div id="csmEditNotePageModal" class="modal fade" tabindex="-1" role="dialog" style="display:none;">
            <div class="modal-dialog modal-md" role="document">
                <div class="modal-content" style="border-radius:8px;">
                    <form method="post" action="' . csm_h($moduleLink) . '&action=save_edited_note">
                        <input type="hidden" name="note_id" id="editNotePageId" value="0">
                        <div class="modal-header" style="background:#12589b;color:#fff;border-radius:7px 7px 0 0;">
                            <button type="button" class="close" data-dismiss="modal" style="color:#fff;">&times;</button>
                            <h4 class="modal-title"><i class="fas fa-edit"></i> Edit Due Note / Ledger Entry</h4>
                        </div>
                        <div class="modal-body" style="padding:20px;">
                            <div class="form-group">
                                <label>Note / Payment Remark <span class="text-danger">*</span></label>
                                <textarea name="note" id="editNotePageText" class="form-control" rows="3" required></textarea>
                            </div>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label>Paid Amount</label>
                                    <input type="number" step="0.01" name="paid_amount" id="editNotePagePaid" class="form-control">
                                </div>
                                <div class="col-md-4 form-group">
                                    <label>Due Amount</label>
                                    <input type="number" step="0.01" name="due_amount" id="editNotePageDue" class="form-control">
                                </div>
                                <div class="col-md-4 form-group">
                                    <label>Promised Date</label>
                                    <input type="date" name="promised_date" id="editNotePagePromised" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer" style="background:#f8fafc;">
                            <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        function openEditNotePageModal(noteObj) {
            document.getElementById("editNotePageId").value = noteObj.id;
            document.getElementById("editNotePageText").value = noteObj.note || "";
            document.getElementById("editNotePagePaid").value = noteObj.paid_amount || "";
            document.getElementById("editNotePageDue").value = noteObj.due_amount || "";
            document.getElementById("editNotePagePromised").value = noteObj.promised_date || "";
            $("#csmEditNotePageModal").modal("show");
        }
        </script>';

        return $html;
    }
}

/**
 * Page: Module Setup & Diagnostics
 */
if (!function_exists('csm_render_module_setup_page')) {
    function csm_render_module_setup_page($vars)
    {
        $moduleLink = $vars['modulelink'];
        $highlightDays = (int) csm_get_setting('highlight_days', '7');
        $countryCode = csm_get_setting('default_country_code', '880');
        $waTemplate = csm_get_setting('wa_template', '');
        $autoGraceEnabled = csm_get_setting('auto_suspend_grace_enabled', 'on') === 'on';
        $adminFolder = csm_get_setting('custom_admin_folder', 'admin');

        $html = '';
        if (isset($_GET['saved'])) {
            $html .= '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Module Setup settings saved successfully.</div>';
        }

        $currencies = [];
        try {
            $currencies = Capsule::table('tblcurrencies')->orderBy('default', 'DESC')->orderBy('code', 'ASC')->get();
        } catch (\Exception $e) {}
        $selectedCurrencyId = (int) csm_get_setting('display_currency_id', '0');

        $currencyOptionsHtml = '<option value="0"' . ($selectedCurrencyId === 0 ? ' selected' : '') . '>Auto (Use Client Profile Currency / WHMCS Default)</option>';
        if (!empty($currencies)) {
            foreach ($currencies as $cur) {
                $sym = trim(($cur->prefix ?: '') . ' ' . ($cur->suffix ?: ''));
                $currencyOptionsHtml .= '<option value="' . $cur->id . '"' . ($selectedCurrencyId === (int)$cur->id ? ' selected' : '') . '>'
                    . csm_h($cur->code) . ($sym ? ' (' . csm_h($sym) . ')' : '') . ($cur->default ? ' [WHMCS Default]' : '')
                    . '</option>';
            }
        }

        $html .= '<form method="post" action="' . csm_h($moduleLink) . '&action=save_module_setup">
            <div class="csm-card">
                <div class="csm-card-header">
                    <h3><i class="fas fa-cogs text-primary"></i> General &amp; WhatsApp Configuration</h3>
                </div>
                <div class="csm-card-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label><i class="fas fa-coins text-primary"></i> Module Calculation &amp; Display Currency</label>
                            <select name="display_currency_id" class="form-control">
                                ' . $currencyOptionsHtml . '
                            </select>
                            <small class="help-block">Select a fixed currency for all calculations &amp; symbols across this module, or keep Auto.</small>
                        </div>
                        <div class="col-md-6 form-group">
                            <label><i class="fas fa-exclamation-triangle text-warning"></i> Expiring Soon Warning (Days)</label>
                            <input type="number" name="highlight_days" class="form-control" value="' . $highlightDays . '" min="1" max="60" required>
                            <small class="help-block">Services expiring within this number of days will be highlighted with orange/red badges.</small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12 form-group">
                            <label><i class="fab fa-whatsapp text-success"></i> Default WhatsApp Country Code</label>
                            <input type="text" name="default_country_code" class="form-control" value="' . csm_h($countryCode) . '" placeholder="880" required>
                            <small class="help-block">E.g. <code>880</code> for Bangladesh if customer phone numbers start with 017...</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Default WhatsApp Message Template</label>
                        <textarea name="wa_template" class="form-control" rows="4" required>' . csm_h($waTemplate) . '</textarea>
                        <small class="help-block">Available tags: <code>{client_name}</code>, <code>{service_name}</code>, <code>{domain}</code>, <code>{due_date}</code>, <code>{amount}</code></small>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label class="csm-check-row">
                            <input type="checkbox" name="auto_suspend_grace_enabled" value="on"' . ($autoGraceEnabled ? ' checked' : '') . '>
                            <span>
                                <strong>Enable Custom Overdue Grace Period Suspension Hook</strong>
                                <small>Allows postponing automatic suspension for specific services until custom grace deadline without modifying main WHMCS billing dates.</small>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="csm-actions-toolbar">
                <div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Module Setup</button>
                </div>
                <div class="csm-muted">Changes apply immediately.</div>
            </div>
        </form>';

        return $html;
    }
}

/**
 * Page: Changelog
 */
if (!function_exists('csm_render_changelog_page')) {
    function csm_render_changelog_page()
    {
        return '
        <div class="csm-card">
            <div class="csm-card-header">
                <h3><i class="fas fa-list-alt text-primary"></i> Release History &amp; Changelog</h3>
            </div>
            <div class="csm-card-body">
                <div style="border-left:3px solid #1267b3;padding-left:16px;margin-bottom:24px;">
                    <h4 style="font-weight:800;color:#1e293b;margin:0 0 6px;">
                        <span class="label label-primary">v2.0.0</span> Major Feature &amp; UI Upgrade
                        <span style="color:#64748b;font-size:12px;font-weight:normal;margin-left:8px;">April 2026</span>
                    </h4>
                    <ul style="margin:8px 0 0 0;padding-left:18px;font-size:13px;line-height:1.7;color:#334155;">
                        <li>Redesigned entire module UI to a clean tabbed navigation architecture matching the enterprise WHMCS standard.</li>
                        <li><strong>Custom Customers &amp; Ledger:</strong> Added support for adding offline/custom clients and tracking their recurring service dues.</li>
                        <li><strong>Inline Due Notes &amp; Ledger:</strong> Added instant payment ledger notes and promised due date records for all services.</li>
                        <li><strong>Custom Suspend Overdue Manager:</strong> Added custom grace period suspension override without changing WHMCS core Next Due Date.</li>
                        <li>Moved all configuration settings directly into the module dashboard page.</li>
                    </ul>
                </div>
                <div style="border-left:3px solid #94a3b8;padding-left:16px;">
                    <h4 style="font-weight:800;color:#1e293b;margin:0 0 6px;">
                        <span class="label label-default">v1.0.0</span> Initial Release
                        <span style="color:#64748b;font-size:12px;font-weight:normal;margin-left:8px;">March 2026</span>
                    </h4>
                    <ul style="margin:8px 0 0 0;padding-left:18px;font-size:13px;line-height:1.7;color:#334155;">
                        <li>Live service and domain monitoring with real-time expiry date sorting.</li>
                        <li>WhatsApp 1-click modal connect and phone number copy.</li>
                    </ul>
                </div>
            </div>
        </div>';
    }
}

/**
 * Page: Developer Info
 */
if (!function_exists('csm_render_developer_page')) {
    function csm_render_developer_page()
    {
        return '
        <div class="csm-card">
            <div class="csm-card-header">
                <h3><i class="fas fa-code text-primary"></i> Developer &amp; Module Information</h3>
            </div>
            <div class="csm-card-body" style="font-size:13px;line-height:1.7;color:#334155;">
                <h4 style="font-weight:800;color:#0f5ea8;margin-top:0;">Client Services &amp; Expiry Monitor for WHMCS</h4>
                <p>Developed with passion by <strong>Bahari IT</strong>. Designed for web hosting providers, server managers, and agencies needing real-time customer due tracking and advanced automation control.</p>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-top:16px;">
                    <div><i class="fas fa-globe text-primary"></i> Website: <a href="https://client.bahariit.com" target="_blank" style="font-weight:600;color:#1267b3;">client.bahariit.com</a></div>
                    <div><i class="fas fa-envelope text-primary"></i> Support Email: <a href="mailto:support@bahariit.com" style="font-weight:600;color:#1267b3;">support@bahariit.com</a></div>
                    <div><i class="fas fa-shield-alt text-primary"></i> Version: <strong>2.0.0 (Enterprise)</strong></div>
                </div>
            </div>
        </div>';
    }
}

/**
 * Main Admin Area Output Function
 */
if (!function_exists('client_services_monitor_output')) {
    function client_services_monitor_output($vars)
    {
        csm_ensure_tables();
        csm_seed_settings();

        $action = isset($_GET['action']) ? (string) $_GET['action'] : 'live_monitor';

        // AJAX Note Handler
        if (isset($_GET['ajax']) && $_GET['ajax'] === 'save_note' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/json');
            $relType = trim($_POST['rel_type'] ?? 'service');
            $relId = (int)($_POST['rel_id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            $paid = isset($_POST['paid_amount']) && is_numeric($_POST['paid_amount']) ? (float)$_POST['paid_amount'] : null;
            $due  = isset($_POST['due_amount']) && is_numeric($_POST['due_amount']) ? (float)$_POST['due_amount'] : null;
            $promised = !empty($_POST['promised_date']) ? trim($_POST['promised_date']) : null;
            $adminId = isset($_SESSION['adminid']) ? (int)$_SESSION['adminid'] : null;
            $adminName = 'Admin';
            if ($adminId) {
                $adminRow = Capsule::table('tbladmins')->where('id', $adminId)->first(['firstname', 'lastname', 'username']);
                if ($adminRow) {
                    $adminName = trim(($adminRow->firstname ?? '') . ' ' . ($adminRow->lastname ?? '')) ?: ($adminRow->username ?? 'Admin');
                }
            }

            if ($relId > 0 && !empty($note)) {
                Capsule::table('mod_csm_service_notes')->insert([
                    'rel_type' => $relType,
                    'rel_id' => $relId,
                    'note' => $note,
                    'paid_amount' => $paid,
                    'due_amount' => $due,
                    'promised_date' => $promised,
                    'admin_id' => $adminId,
                    'admin_name' => $adminName,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Missing note or ID']);
            }
            exit;
        }

        // AJAX Edit Note Handler
        if (isset($_GET['ajax']) && $_GET['ajax'] === 'edit_note' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/json');
            $noteId = (int)($_POST['note_id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            $paid = isset($_POST['paid_amount']) && is_numeric($_POST['paid_amount']) ? (float)$_POST['paid_amount'] : null;
            $due  = isset($_POST['due_amount']) && is_numeric($_POST['due_amount']) ? (float)$_POST['due_amount'] : null;
            $promised = !empty($_POST['promised_date']) ? trim($_POST['promised_date']) : null;

            if ($noteId > 0 && !empty($note)) {
                Capsule::table('mod_csm_service_notes')->where('id', $noteId)->update([
                    'note' => $note,
                    'paid_amount' => $paid,
                    'due_amount' => $due,
                    'promised_date' => $promised,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid note text or ID']);
            }
            exit;
        }

        // AJAX Delete Note Handler
        if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete_note') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/json');
            $noteId = (int)($_POST['note_id'] ?? ($_GET['note_id'] ?? 0));
            if ($noteId > 0) {
                Capsule::table('mod_csm_service_notes')->where('id', $noteId)->delete();
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid note ID']);
            }
            exit;
        }

        // AJAX Fetch Notes List Handler
        if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_notes') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/json');
            $relType = trim($_GET['rel_type'] ?? 'service');
            $relId = (int)($_GET['rel_id'] ?? 0);

            $notesList = Capsule::table('mod_csm_service_notes')
                ->where('rel_type', $relType)
                ->where('rel_id', $relId)
                ->orderBy('id', 'DESC')
                ->get();

            echo json_encode(['success' => true, 'notes' => $notesList]);
            exit;
        }

        // AJAX Fetch Services Data for Live Monitor
        if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/json');
            try {
                $response = client_services_monitor_fetch_data($_REQUEST, $vars);
                echo json_encode($response);
            } catch (\Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }

        // POST Action Handlers
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if ($action === 'save_custom_customer') {
                $cId = (int)($_POST['customer_id'] ?? 0);
                $clientIds = isset($_POST['client_ids']) ? (array)$_POST['client_ids'] : [];
                $service = trim($_POST['service_name'] ?? 'Custom Service');
                $domain = trim($_POST['domain'] ?? '');
                $amount = (float)($_POST['amount'] ?? 0);
                $cycle = trim($_POST['billing_cycle'] ?? 'Monthly');
                $due = !empty($_POST['next_due_date']) ? trim($_POST['next_due_date']) : null;
                $status = trim($_POST['status'] ?? 'Active');
                $notes = trim($_POST['notes'] ?? '');
                $now = date('Y-m-d H:i:s');

                $name = trim($_POST['customer_name'] ?? '');
                $company = trim($_POST['company_name'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $userId = (int)($_POST['userid'] ?? 0);

                if ($cId > 0) {
                    // Updating an existing record
                    if (empty($userId) && !empty($clientIds)) {
                        $userId = (int)$clientIds[0];
                    }
                    Capsule::table('mod_csm_custom_customers')->where('id', $cId)->update([
                        'userid'        => $userId > 0 ? $userId : null,
                        'customer_name' => !empty($name) ? $name : 'Custom Client',
                        'company_name'  => $company,
                        'phone'         => $phone,
                        'email'         => $email,
                        'service_name'  => $service,
                        'domain'        => $domain,
                        'amount'        => $amount,
                        'billing_cycle' => $cycle,
                        'next_due_date' => $due,
                        'status'        => $status,
                        'notes'         => $notes,
                        'updated_at'    => $now,
                    ]);
                } else {
                    // Adding new record(s)
                    if (!empty($clientIds) && count($clientIds) > 1) {
                        // Multi-client batch add
                        foreach ($clientIds as $uId) {
                            $uId = (int)$uId;
                            if ($uId <= 0) continue;
                            $cl = Capsule::table('tblclients')->where('id', $uId)->first();
                            if (!$cl) continue;

                            $newCustId = Capsule::table('mod_csm_custom_customers')->insertGetId([
                                'userid'        => $uId,
                                'customer_name' => trim($cl->firstname . ' ' . $cl->lastname),
                                'company_name'  => $cl->companyname ?: '',
                                'phone'         => $cl->phonenumber ?: '',
                                'email'         => $cl->email ?: '',
                                'service_name'  => $service,
                                'domain'        => $domain,
                                'amount'        => $amount,
                                'billing_cycle' => $cycle,
                                'next_due_date' => $due,
                                'status'        => $status,
                                'notes'         => $notes,
                                'created_at'    => $now,
                                'updated_at'    => $now,
                            ]);

                            if (!empty($notes)) {
                                Capsule::table('mod_csm_service_notes')->insert([
                                    'rel_type'   => 'custom_customer',
                                    'rel_id'     => $newCustId,
                                    'note'       => $notes,
                                    'due_amount' => $amount,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ]);
                            }
                        }
                    } else {
                        // Single client (either selected from WHMCS or typed manually)
                        if (empty($userId) && !empty($clientIds)) {
                            $userId = (int)$clientIds[0];
                        }
                        if ($userId > 0 && empty($name)) {
                            $cl = Capsule::table('tblclients')->where('id', $userId)->first();
                            if ($cl) {
                                $name = trim($cl->firstname . ' ' . $cl->lastname);
                                if (empty($company)) $company = $cl->companyname ?: '';
                                if (empty($phone)) $phone = $cl->phonenumber ?: '';
                                if (empty($email)) $email = $cl->email ?: '';
                            }
                        }

                        $newCustId = Capsule::table('mod_csm_custom_customers')->insertGetId([
                            'userid'        => $userId > 0 ? $userId : null,
                            'customer_name' => !empty($name) ? $name : 'Custom Client',
                            'company_name'  => $company,
                            'phone'         => $phone,
                            'email'         => $email,
                            'service_name'  => $service,
                            'domain'        => $domain,
                            'amount'        => $amount,
                            'billing_cycle' => $cycle,
                            'next_due_date' => $due,
                            'status'        => $status,
                            'notes'         => $notes,
                            'created_at'    => $now,
                            'updated_at'    => $now,
                        ]);

                        if (!empty($notes)) {
                            Capsule::table('mod_csm_service_notes')->insert([
                                'rel_type'   => 'custom_customer',
                                'rel_id'     => $newCustId,
                                'note'       => $notes,
                                'due_amount' => $amount,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }
                    }
                }

                header('Location: ' . $vars['modulelink'] . '&action=custom_customers&saved=1');
                exit;
            }

            if ($action === 'save_edited_note') {
                $noteId = (int)($_POST['note_id'] ?? 0);
                $note = trim($_POST['note'] ?? '');
                $paid = isset($_POST['paid_amount']) && is_numeric($_POST['paid_amount']) ? (float)$_POST['paid_amount'] : null;
                $due  = isset($_POST['due_amount']) && is_numeric($_POST['due_amount']) ? (float)$_POST['due_amount'] : null;
                $promised = !empty($_POST['promised_date']) ? trim($_POST['promised_date']) : null;

                if ($noteId > 0 && !empty($note)) {
                    Capsule::table('mod_csm_service_notes')->where('id', $noteId)->update([
                        'note' => $note,
                        'paid_amount' => $paid,
                        'due_amount' => $due,
                        'promised_date' => $promised,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }

                header('Location: ' . $vars['modulelink'] . '&action=due_notes&saved=1');
                exit;
            }

            if ($action === 'save_grace_suspend') {
                $serviceId = (int)($_POST['service_id'] ?? 0);
                $graceDate = trim($_POST['grace_suspend_date'] ?? '');
                $reason = trim($_POST['reason'] ?? '');
                $adminId = isset($_SESSION['adminid']) ? (int)$_SESSION['adminid'] : 0;
                $now = date('Y-m-d H:i:s');

                if ($serviceId > 0 && !empty($graceDate)) {
                    $srv = Capsule::table('tblhosting')->where('id', $serviceId)->first();
                    if ($srv) {
                        Capsule::table('mod_csm_suspension_overrides')->updateOrInsert(
                            ['service_id' => $serviceId],
                            [
                                'userid' => $srv->userid,
                                'original_due_date' => $srv->nextduedate,
                                'grace_suspend_date' => $graceDate,
                                'reason' => $reason,
                                'status' => 'active',
                                'admin_id' => $adminId,
                                'updated_at' => $now,
                            ]
                        );
                    }
                }

                if ((isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_GET['ajax']) && $_GET['ajax'] == '1')) {
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true]);
                    exit;
                }

                header('Location: ' . $vars['modulelink'] . '&action=suspension_manager&saved=1');
                exit;
            }

            if ($action === 'add_monitored_clients') {
                $clientIds = isset($_POST['client_ids']) ? (array)$_POST['client_ids'] : [];
                $notes = trim($_POST['notes'] ?? '');
                $now = date('Y-m-d H:i:s');
                foreach ($clientIds as $uid) {
                    $uid = (int)$uid;
                    if ($uid > 0) {
                        Capsule::table('mod_csm_monitored_clients')->updateOrInsert(
                            ['userid' => $uid],
                            [
                                'notes' => $notes ?: null,
                                'updated_at' => $now,
                                'created_at' => $now,
                            ]
                        );
                    }
                }
                header('Location: ' . $vars['modulelink'] . '&action=custom_customers&saved=1');
                exit;
            }

            if ($action === 'save_module_setup') {
                csm_save_setting('display_currency_id', (int)($_POST['display_currency_id'] ?? 0));
                csm_save_setting('highlight_days', (int)($_POST['highlight_days'] ?? 7));
                csm_save_setting('default_country_code', trim($_POST['default_country_code'] ?? '880'));
                csm_save_setting('wa_template', trim($_POST['wa_template'] ?? ''));
                csm_save_setting('auto_suspend_grace_enabled', !empty($_POST['auto_suspend_grace_enabled']) ? 'on' : '');

                header('Location: ' . $vars['modulelink'] . '&action=module_setup&saved=1');
                exit;
            }
        }

        // GET Action Handlers
        if ($action === 'remove_monitored_client') {
            $uId = (int)($_GET['userid'] ?? 0);
            if ($uId > 0) {
                Capsule::table('mod_csm_monitored_clients')->where('userid', $uId)->delete();
            }
            header('Location: ' . $vars['modulelink'] . '&action=custom_customers&removed=1');
            exit;
        }

        if ($action === 'delete_custom_customer') {
            $delId = (int)($_GET['id'] ?? 0);
            if ($delId > 0) {
                Capsule::table('mod_csm_custom_customers')->where('id', $delId)->delete();
                Capsule::table('mod_csm_service_notes')->where('rel_type', 'custom_customer')->where('rel_id', $delId)->delete();
            }
            header('Location: ' . $vars['modulelink'] . '&action=custom_customers&deleted=1');
            exit;
        }

        if ($action === 'delete_note') {
            $delId = (int)($_GET['id'] ?? 0);
            if ($delId > 0) {
                Capsule::table('mod_csm_service_notes')->where('id', $delId)->delete();
            }
            header('Location: ' . $vars['modulelink'] . '&action=due_notes&deleted=1');
            exit;
        }

        if ($action === 'delete_grace_suspend') {
            $delId = (int)($_GET['id'] ?? 0);
            if ($delId > 0) {
                Capsule::table('mod_csm_suspension_overrides')->where('id', $delId)->delete();
            }
            header('Location: ' . $vars['modulelink'] . '&action=suspension_manager&deleted=1');
            exit;
        }

        if (!in_array($action, ['live_monitor', 'custom_customers', 'suspension_manager', 'due_notes', 'module_setup', 'changelog', 'developer_info'], true)) {
            $action = 'live_monitor';
        }

        echo csm_render_header($vars, $action);

        if ($action === 'custom_customers') {
            echo csm_render_custom_customers_page($vars);
        } elseif ($action === 'suspension_manager') {
            echo csm_render_suspension_manager_page($vars);
        } elseif ($action === 'due_notes') {
            echo csm_render_due_notes_page($vars);
        } elseif ($action === 'module_setup') {
            echo csm_render_module_setup_page($vars);
        } elseif ($action === 'changelog') {
            echo csm_render_changelog_page();
        } elseif ($action === 'developer_info') {
            echo csm_render_developer_page();
        } else {
            // Live Monitor Page
            $modulelink = $vars['modulelink'] ?? 'addonmodules.php?module=client_services_monitor';
            $servers = Capsule::table('tblservers')->select('id', 'name', 'ipaddress')->orderBy('name', 'ASC')->get();
            $productGroups = Capsule::table('tblproductgroups')->select('id', 'name')->orderBy('order', 'ASC')->get();
            $products = Capsule::table('tblproducts')
                ->join('tblproductgroups', 'tblproducts.gid', '=', 'tblproductgroups.id')
                ->select('tblproducts.id', 'tblproducts.name', 'tblproducts.type', 'tblproductgroups.name as group_name')
                ->orderBy('tblproductgroups.order', 'ASC')
                ->orderBy('tblproducts.order', 'ASC')
                ->get();
            $paymentGateways = Capsule::table('tblpaymentgateways')->where('setting', 'name')->select('gateway', 'value')->get();

            include __DIR__ . '/templates/admin_dashboard.php';
        }

        echo csm_render_footer($action);
    }
}

/**
 * Fetch data for AJAX DataTables & Expiry Monitor
 */
if (!function_exists('client_services_monitor_fetch_data')) {
    function client_services_monitor_fetch_data($params, $vars)
    {
        $productType   = isset($params['product_type']) ? trim($params['product_type']) : '';
        $productId     = isset($params['product_id']) ? (int)$params['product_id'] : 0;
        $billingCycle  = isset($params['billing_cycle']) ? trim($params['billing_cycle']) : '';
        $status        = isset($params['status']) ? trim($params['status']) : csm_get_setting('default_status', 'Active');
        $serverId      = isset($params['server_id']) ? (int)$params['server_id'] : 0;
        $paymentMethod = isset($params['payment_method']) ? trim($params['payment_method']) : '';
        $dueFilter     = isset($params['due_filter']) ? trim($params['due_filter']) : '';
        $search        = isset($params['search']) ? trim($params['search']) : '';
        $page          = max(1, isset($params['page']) ? (int)$params['page'] : 1);
        $limit         = isset($params['limit']) ? (int)$params['limit'] : ((int)csm_get_setting('records_per_page', '50'));
        if ($limit <= 0) $limit = 50;

        $defaultCountryCode = preg_replace('/[^0-9]/', '', csm_get_setting('default_country_code', '880'));
        $warningDays = (int) csm_get_setting('highlight_days', '7');

        $chosenCurrId = (int) csm_get_setting('display_currency_id', '0');
        $chosenCurrency = $chosenCurrId > 0 ? Capsule::table('tblcurrencies')->where('id', $chosenCurrId)->first() : null;

        $allCurrencies = Capsule::table('tblcurrencies')->get()->keyBy('id');
        $defaultCurrency = Capsule::table('tblcurrencies')->where('default', 1)->first() ?: Capsule::table('tblcurrencies')->first();

        $isDomainOnly = ($productType === 'domain');
        $isServicesOnly = in_array($productType, ['hostingaccount', 'reselleraccount', 'server', 'other']);

        $records = [];
        $totalRecords = 0;

        // Query Services
        if (!$isDomainOnly) {
            $query = Capsule::table('tblhosting')
                ->join('tblclients', 'tblhosting.userid', '=', 'tblclients.id')
                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->leftJoin('tblproductgroups', 'tblproducts.gid', '=', 'tblproductgroups.id')
                ->leftJoin('tblservers', 'tblhosting.server', '=', 'tblservers.id')
                ->select(
                    'tblhosting.id as item_id',
                    Capsule::raw("'service' as record_type"),
                    'tblhosting.userid',
                    'tblhosting.orderid',
                    'tblhosting.regdate',
                    'tblhosting.domain',
                    'tblhosting.paymentmethod',
                    'tblhosting.firstpaymentamount',
                    'tblhosting.amount as price',
                    'tblhosting.billingcycle',
                    'tblhosting.nextduedate',
                    'tblhosting.domainstatus as status',
                    'tblhosting.username',
                    'tblhosting.dedicatedip',
                    'tblproducts.name as product_name',
                    'tblproducts.type as product_type',
                    'tblproductgroups.name as group_name',
                    'tblservers.name as server_name',
                    'tblclients.firstname',
                    'tblclients.lastname',
                    'tblclients.companyname',
                    'tblclients.email',
                    'tblclients.phonenumber',
                    'tblclients.currency as client_currency'
                );

            if ($isServicesOnly) {
                $query->where('tblproducts.type', $productType);
            }
            if ($productId > 0) {
                $query->where('tblhosting.packageid', $productId);
            }
            if (!empty($billingCycle) && $billingCycle !== 'Any') {
                $query->where('tblhosting.billingcycle', $billingCycle);
            }
            if ($serverId > 0) {
                $query->where('tblhosting.server', $serverId);
            }
            if (!empty($paymentMethod) && $paymentMethod !== 'Any') {
                $query->where('tblhosting.paymentmethod', $paymentMethod);
            }

            if ($status === 'Active') {
                $query->where('tblhosting.domainstatus', 'Active');
            } elseif ($status === 'Active,Suspended') {
                $query->whereIn('tblhosting.domainstatus', ['Active', 'Suspended']);
            } elseif (!empty($status) && $status !== 'All' && $status !== 'Any') {
                $query->where('tblhosting.domainstatus', $status);
            }

            if (!empty($dueFilter)) {
                $today = date('Y-m-d');
                if ($dueFilter === 'today') {
                    $query->where('tblhosting.nextduedate', '=', $today);
                } elseif ($dueFilter === '3days') {
                    $query->whereBetween('tblhosting.nextduedate', [$today, date('Y-m-d', strtotime('+3 days'))]);
                } elseif ($dueFilter === '7days') {
                    $query->whereBetween('tblhosting.nextduedate', [$today, date('Y-m-d', strtotime('+7 days'))]);
                } elseif ($dueFilter === '15days') {
                    $query->whereBetween('tblhosting.nextduedate', [$today, date('Y-m-d', strtotime('+15 days'))]);
                } elseif ($dueFilter === '30days') {
                    $query->whereBetween('tblhosting.nextduedate', [$today, date('Y-m-d', strtotime('+30 days'))]);
                } elseif ($dueFilter === 'overdue') {
                    $query->where('tblhosting.nextduedate', '<', $today);
                }
            }

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('tblhosting.id', $search)
                      ->orWhere('tblhosting.domain', 'like', "%{$search}%")
                      ->orWhere('tblhosting.username', 'like', "%{$search}%")
                      ->orWhere('tblclients.firstname', 'like', "%{$search}%")
                      ->orWhere('tblclients.lastname', 'like', "%{$search}%")
                      ->orWhere(Capsule::raw("CONCAT(tblclients.firstname, ' ', tblclients.lastname)"), 'like', "%{$search}%")
                      ->orWhere('tblclients.email', 'like', "%{$search}%")
                      ->orWhere('tblclients.phonenumber', 'like', "%{$search}%")
                      ->orWhere('tblproducts.name', 'like', "%{$search}%");
                });
            }

            $totalRecords = $query->count();
            $records = $query->orderBy('tblhosting.nextduedate', 'ASC')
                             ->orderBy('tblhosting.id', 'DESC')
                             ->skip(($page - 1) * $limit)
                             ->take($limit)
                             ->get();
        } else {
            // Query Domains
            $query = Capsule::table('tbldomains')
                ->join('tblclients', 'tbldomains.userid', '=', 'tblclients.id')
                ->select(
                    'tbldomains.id as item_id',
                    Capsule::raw("'domain' as record_type"),
                    'tbldomains.userid',
                    'tbldomains.orderid',
                    'tbldomains.registrationdate as regdate',
                    'tbldomains.domain',
                    'tbldomains.paymentmethod',
                    'tbldomains.firstpaymentamount',
                    'tbldomains.recurringamount as price',
                    'tbldomains.registrationperiod as billingcycle',
                    'tbldomains.nextduedate',
                    'tbldomains.status',
                    Capsule::raw("'' as username"),
                    Capsule::raw("'' as dedicatedip"),
                    Capsule::raw("'Domain Registration' as product_name"),
                    Capsule::raw("'domain' as product_type"),
                    Capsule::raw("'' as group_name"),
                    Capsule::raw("'' as server_name"),
                    'tblclients.firstname',
                    'tblclients.lastname',
                    'tblclients.companyname',
                    'tblclients.email',
                    'tblclients.phonenumber',
                    'tblclients.currency as client_currency'
                );

            if (!empty($paymentMethod) && $paymentMethod !== 'Any') {
                $query->where('tbldomains.paymentmethod', $paymentMethod);
            }

            if ($status === 'Active') {
                $query->where('tbldomains.status', 'Active');
            } elseif (!empty($status) && $status !== 'All' && $status !== 'Any') {
                $query->where('tbldomains.status', $status);
            }

            if (!empty($dueFilter)) {
                $today = date('Y-m-d');
                if ($dueFilter === 'today') {
                    $query->where('tbldomains.nextduedate', '=', $today);
                } elseif ($dueFilter === '3days') {
                    $query->whereBetween('tbldomains.nextduedate', [$today, date('Y-m-d', strtotime('+3 days'))]);
                } elseif ($dueFilter === '7days') {
                    $query->whereBetween('tbldomains.nextduedate', [$today, date('Y-m-d', strtotime('+7 days'))]);
                } elseif ($dueFilter === '15days') {
                    $query->whereBetween('tbldomains.nextduedate', [$today, date('Y-m-d', strtotime('+15 days'))]);
                } elseif ($dueFilter === '30days') {
                    $query->whereBetween('tbldomains.nextduedate', [$today, date('Y-m-d', strtotime('+30 days'))]);
                } elseif ($dueFilter === 'overdue') {
                    $query->where('tbldomains.nextduedate', '<', $today);
                }
            }

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('tbldomains.id', $search)
                      ->orWhere('tbldomains.domain', 'like', "%{$search}%")
                      ->orWhere('tblclients.firstname', 'like', "%{$search}%")
                      ->orWhere('tblclients.lastname', 'like', "%{$search}%")
                      ->orWhere(Capsule::raw("CONCAT(tblclients.firstname, ' ', tblclients.lastname)"), 'like', "%{$search}%")
                      ->orWhere('tblclients.email', 'like', "%{$search}%")
                      ->orWhere('tblclients.phonenumber', 'like', "%{$search}%");
                });
            }

            $totalRecords = $query->count();
            $records = $query->orderBy('tbldomains.nextduedate', 'ASC')
                             ->orderBy('tbldomains.id', 'DESC')
                             ->skip(($page - 1) * $limit)
                             ->take($limit)
                             ->get();
        }

        // Fetch overrides & notes for returned records in bulk
        $serviceIds = !empty($records) ? $records->pluck('item_id')->toArray() : [];
        $overrides = Capsule::table('mod_csm_suspension_overrides')
            ->whereIn('service_id', $serviceIds)
            ->where('status', 'active')
            ->get()
            ->keyBy('service_id');

        $notes = Capsule::table('mod_csm_service_notes')
            ->where('rel_type', $isDomainOnly ? 'domain' : 'service')
            ->whereIn('rel_id', $serviceIds)
            ->orderBy('id', 'DESC')
            ->get()
            ->groupBy('rel_id');

        // Format Rows
        $formattedRecords = [];
        $todayTs = strtotime(date('Y-m-d'));

        foreach ($records as $row) {
            $curr = $chosenCurrency ?: ($allCurrencies->get($row->client_currency) ?: $defaultCurrency);
            $prefix = $curr ? $curr->prefix : '';
            $suffix = $curr && !empty($curr->suffix) ? $curr->suffix : '';

            $dueDateStr = $row->nextduedate && $row->nextduedate !== '0000-00-00' ? $row->nextduedate : null;
            $daysLeft = null;
            $expiryStatus = 'normal';

            if ($dueDateStr) {
                $dueTs = strtotime($dueDateStr);
                $daysLeft = (int)round(($dueTs - $todayTs) / 86400);

                if ($daysLeft < 0) {
                    $expiryStatus = 'overdue';
                } elseif ($daysLeft == 0) {
                    $expiryStatus = 'today';
                } elseif ($daysLeft <= $warningDays) {
                    $expiryStatus = 'warning';
                }
            }

            // Phone and WhatsApp link generator
            $rawPhone = trim($row->phonenumber ?? '');
            $cleanPhone = preg_replace('/[^0-9+]/', '', str_replace('.', '', $rawPhone));
            $cleanDisplayPhone = str_replace('.', ' ', $rawPhone);
            $cleanDisplayPhone = trim(preg_replace('/\s+/', ' ', $cleanDisplayPhone));

            $intlPhone = '';
            if (!empty($cleanPhone)) {
                $digitsOnly = preg_replace('/[^0-9]/', '', $cleanPhone);
                if (substr($digitsOnly, 0, strlen($defaultCountryCode)) === $defaultCountryCode) {
                    $intlPhone = $digitsOnly;
                } elseif (substr($digitsOnly, 0, 1) === '0') {
                    $intlPhone = $defaultCountryCode . substr($digitsOnly, 1);
                } else {
                    $intlPhone = $defaultCountryCode . $digitsOnly;
                }
            }

            $dialPhone = !empty($intlPhone) ? '+' . ltrim($intlPhone, '+') : (!empty($cleanPhone) ? (substr($cleanPhone, 0, 1) === '+' ? $cleanPhone : '+' . $cleanPhone) : '');

            $waTemplate = csm_get_setting('wa_template', '');
            $waMessage = str_replace(
                ['{client_name}', '{service_name}', '{domain}', '{due_date}', '{amount}'],
                [$row->firstname . ' ' . $row->lastname, $row->product_name, $row->domain ?: 'N/A', $dueDateStr ? date('d/m/Y', strtotime($dueDateStr)) : 'N/A', $prefix . number_format((float)$row->price, 2) . $suffix],
                $waTemplate
            );
            $waUrl = !empty($intlPhone) ? 'https://wa.me/' . $intlPhone . '?text=' . urlencode($waMessage) : '';

            $override = $overrides->get($row->item_id);
            $serviceNotes = $notes->get($row->item_id);
            $latestNote = $serviceNotes ? $serviceNotes->first() : null;

            $formattedRecords[] = [
                'id'             => $row->item_id,
                'record_type'    => $row->record_type,
                'userid'         => $row->userid,
                'client_name'    => trim($row->firstname . ' ' . $row->lastname),
                'company'        => $row->companyname ?: '',
                'email'          => $row->email,
                'phone'          => !empty($cleanDisplayPhone) ? $cleanDisplayPhone : 'N/A',
                'dial_phone'     => $dialPhone,
                'intl_phone'     => $intlPhone,
                'wa_url'         => $waUrl,
                'product_name'   => $row->product_name,
                'domain'         => $row->domain ?: '',
                'server_name'    => $row->server_name ?: '',
                'price'          => (float)$row->price,
                'formatted_price'=> $prefix . number_format((float)$row->price, 2) . $suffix,
                'billing_cycle'  => $row->billingcycle,
                'status'         => $row->status,
                'next_due_date'  => $dueDateStr ? date('d/m/Y', strtotime($dueDateStr)) : 'N/A',
                'raw_due_date'   => $dueDateStr,
                'days_left'      => $daysLeft,
                'expiry_status'  => $expiryStatus,
                'grace_date'     => $override ? date('d/m/Y', strtotime($override->grace_suspend_date)) : null,
                'grace_reason'   => $override ? $override->reason : null,
                'latest_note'    => $latestNote ? $latestNote->note : null,
                'notes_count'    => $serviceNotes ? count($serviceNotes) : 0,
            ];
        }

        // Stats summary
        $statActive = Capsule::table('tblhosting')->where('domainstatus', 'Active')->count();
        $statToday = Capsule::table('tblhosting')->where('domainstatus', 'Active')->where('nextduedate', date('Y-m-d'))->count();
        $stat3Days = Capsule::table('tblhosting')->where('domainstatus', 'Active')->whereBetween('nextduedate', [date('Y-m-d'), date('Y-m-d', strtotime('+3 days'))])->count();
        $stat7Days = Capsule::table('tblhosting')->where('domainstatus', 'Active')->whereBetween('nextduedate', [date('Y-m-d'), date('Y-m-d', strtotime('+7 days'))])->count();
        $statOverdue = Capsule::table('tblhosting')->where('domainstatus', 'Active')->where('nextduedate', '<', date('Y-m-d'))->count();

        return [
            'success' => true,
            'total'   => $totalRecords,
            'page'    => $page,
            'limit'   => $limit,
            'records' => $formattedRecords,
            'stats'   => [
                'total_active' => $statActive,
                'due_today'    => $statToday,
                'due_3days'    => $stat3Days,
                'due_7days'    => $stat7Days,
                'overdue'      => $statOverdue,
            ]
        ];
    }
}
