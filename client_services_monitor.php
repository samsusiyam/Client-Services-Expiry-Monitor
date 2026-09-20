<?php
/**
 * WHMCS Addon Module: Client Services & Expiry Monitor
 *
 * @package    WHMCS
 * @author     Developer
 * @copyright  Copyright (c) 2026
 * @version    1.0.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Define addon module configuration parameters.
 *
 * @return array
 */
function client_services_monitor_config()
{
    return [
        'name' => 'Client Services & Expiry Monitor',
        'description' => 'Live real-time monitoring of client active products, services and domains sorted by next due date with client phone number, one-click WhatsApp and call connect.',
        'author' => 'WHMCS Custom Modules',
        'language' => 'english',
        'version' => '1.0.0',
        'logo' => 'logo.png',
        'fields' => [
            'default_status' => [
                'FriendlyName' => 'Default Status',
                'Type' => 'dropdown',
                'Options' => [
                    'Active' => 'Active Only (Recommended)',
                    'Active,Suspended' => 'Active & Suspended',
                    'All' => 'All Statuses',
                ],
                'Default' => 'Active',
                'Description' => 'Select default status for services loaded on page load.',
            ],
            'records_per_page' => [
                'FriendlyName' => 'Default Records Per Page',
                'Type' => 'dropdown',
                'Options' => [
                    '25' => '25 Records',
                    '50' => '50 Records',
                    '100' => '100 Records',
                    '250' => '250 Records',
                ],
                'Default' => '50',
                'Description' => 'Number of service records displayed per page.',
            ],
            'highlight_days' => [
                'FriendlyName' => 'Expiring Soon Warning (Days)',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '7',
                'Description' => 'Number of days before expiry to highlight services with warning badge.',
            ],
            'default_country_code' => [
                'FriendlyName' => 'Default WhatsApp Country Code',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '880',
                'Description' => 'Default country code (e.g. 880 for Bangladesh) if phone number lacks country code.',
            ],
        ]
    ];
}

/**
 * Activate addon module.
 *
 * @return array
 */
function client_services_monitor_activate()
{
    return [
        'status' => 'success',
        'description' => 'Client Services & Expiry Monitor module has been successfully activated.'
    ];
}

/**
 * Deactivate addon module.
 *
 * @return array
 */
function client_services_monitor_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'Client Services & Expiry Monitor module has been deactivated.'
    ];
}

/**
 * Admin Area Output.
 *
 * @param array $vars
 */
function client_services_monitor_output($vars)
{
    $modulelink = $vars['modulelink'];
    $version    = $vars['version'];
    $config_highlight_days = (int)($vars['highlight_days'] ?: 7);
    $config_country_code   = preg_replace('/[^0-9]/', '', $vars['default_country_code'] ?: '880');
    $config_default_status = $vars['default_status'] ?: 'Active';
    $config_per_page       = (int)($vars['records_per_page'] ?: 50);

    // AJAX Endpoint Handler
    if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
        header('Content-Type: application/json');
        ob_clean();
        try {
            $response = client_services_monitor_fetch_data($_REQUEST, $vars);
            echo json_encode($response);
        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }

    // Fetch lists for filter dropdowns
    $servers = Capsule::table('tblservers')->select('id', 'name', 'ipaddress')->orderBy('name', 'ASC')->get();
    $productGroups = Capsule::table('tblproductgroups')->select('id', 'name')->orderBy('order', 'ASC')->get();
    $products = Capsule::table('tblproducts')
        ->join('tblproductgroups', 'tblproducts.gid', '=', 'tblproductgroups.id')
        ->select('tblproducts.id', 'tblproducts.name', 'tblproducts.type', 'tblproductgroups.name as group_name')
        ->orderBy('tblproductgroups.order', 'ASC')
        ->orderBy('tblproducts.order', 'ASC')
        ->get();
    $paymentGateways = Capsule::table('tblpaymentgateways')->where('setting', 'name')->select('gateway', 'value')->get();

    // Render Admin UI
    include __DIR__ . '/templates/admin_dashboard.php';
}

/**
 * Fetch and filter data for AJAX requests
 *
 * @param array $params
 * @param array $vars
 * @return array
 */
function client_services_monitor_fetch_data($params, $vars)
{
    $productType   = isset($params['product_type']) ? trim($params['product_type']) : '';
    $productId     = isset($params['product_id']) ? (int)$params['product_id'] : 0;
    $billingCycle  = isset($params['billing_cycle']) ? trim($params['billing_cycle']) : '';
    $status        = isset($params['status']) ? trim($params['status']) : ($vars['default_status'] ?: 'Active');
    $serverId      = isset($params['server_id']) ? (int)$params['server_id'] : 0;
    $paymentMethod = isset($params['payment_method']) ? trim($params['payment_method']) : '';
    $dueFilter     = isset($params['due_filter']) ? trim($params['due_filter']) : '';
    $search        = isset($params['search']) ? trim($params['search']) : '';
    $page          = max(1, isset($params['page']) ? (int)$params['page'] : 1);
    $limit         = isset($params['limit']) ? (int)$params['limit'] : ((int)($vars['records_per_page'] ?: 50));
    if ($limit <= 0) $limit = 50;

    $defaultCountryCode = preg_replace('/[^0-9]/', '', $vars['default_country_code'] ?: '880');
    $warningDays = (int)($vars['highlight_days'] ?: 7);

    // Get default currency
    $defaultCurrency = Capsule::table('tblcurrencies')->where('default', 1)->first();
    if (!$defaultCurrency) {
        $defaultCurrency = Capsule::table('tblcurrencies')->first();
    }

    $allCurrencies = Capsule::table('tblcurrencies')->get()->keyBy('id');

    $isDomainOnly = ($productType === 'domain');
    $isServicesOnly = in_array($productType, ['hostingaccount', 'reselleraccount', 'server', 'other']);

    $records = [];
    $totalRecords = 0;

    if (!$isDomainOnly) {
        // Query Hosting / Services (tblhosting)
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
                'tblhosting.recurringamount as price',
                'tblhosting.billingcycle',
                'tblhosting.nextduedate',
                'tblhosting.domainstatus as status',
                'tblhosting.username',
                'tblhosting.dedicatedip',
                'tblhosting.promoid',
                'tblproducts.name as product_name',
                'tblproducts.type as product_type',
                'tblproductgroups.name as group_name',
                'tblservers.name as server_name',
                'tblservers.ipaddress as server_ip',
                'tblclients.firstname',
                'tblclients.lastname',
                'tblclients.companyname',
                'tblclients.email',
                'tblclients.phonenumber',
                'tblclients.currency as client_currency'
            );

        // Product Type Filter
        if ($isServicesOnly) {
            $query->where('tblproducts.type', $productType);
        }

        // Product ID Filter
        if ($productId > 0) {
            $query->where('tblhosting.packageid', $productId);
        }

        // Billing Cycle Filter
        if (!empty($billingCycle) && $billingCycle !== 'Any') {
            $query->where('tblhosting.billingcycle', $billingCycle);
        }

        // Server Filter
        if ($serverId > 0) {
            $query->where('tblhosting.server', $serverId);
        }

        // Payment Method Filter
        if (!empty($paymentMethod) && $paymentMethod !== 'Any') {
            $query->where('tblhosting.paymentmethod', $paymentMethod);
        }

        // Status Filter
        if ($status === 'Active') {
            $query->where('tblhosting.domainstatus', 'Active');
        } elseif ($status === 'Active,Suspended') {
            $query->whereIn('tblhosting.domainstatus', ['Active', 'Suspended']);
        } elseif (!empty($status) && $status !== 'All' && $status !== 'Any') {
            $query->where('tblhosting.domainstatus', $status);
        }

        // Due Filter
        applyDueDateFilter($query, 'tblhosting.nextduedate', $dueFilter);

        // Search Keyword
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('tblhosting.id', $search)
                  ->orWhere('tblhosting.domain', 'like', "%{$search}%")
                  ->orWhere('tblhosting.username', 'like', "%{$search}%")
                  ->orWhere('tblhosting.dedicatedip', 'like', "%{$search}%")
                  ->orWhere('tblclients.firstname', 'like', "%{$search}%")
                  ->orWhere('tblclients.lastname', 'like', "%{$search}%")
                  ->orWhere(Capsule::raw("CONCAT(tblclients.firstname, ' ', tblclients.lastname)"), 'like', "%{$search}%")
                  ->orWhere('tblclients.email', 'like', "%{$search}%")
                  ->orWhere('tblclients.phonenumber', 'like', "%{$search}%")
                  ->orWhere('tblclients.companyname', 'like', "%{$search}%")
                  ->orWhere('tblproducts.name', 'like', "%{$search}%");
            });
        }

        // If not mixing with domain query, execute directly
        if ($productType !== 'all_inclusive' && $isServicesOnly) {
            $totalRecords = $query->count();
            // Order by nextduedate ASC (services expiring soonest at the top)
            $records = $query->orderBy('tblhosting.nextduedate', 'ASC')
                             ->orderBy('tblhosting.id', 'DESC')
                             ->skip(($page - 1) * $limit)
                             ->take($limit)
                             ->get();
        }
    }

    if ($productType === 'domain') {
        // Query Domains (tbldomains)
        $dQuery = Capsule::table('tbldomains')
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
                Capsule::raw("CONCAT(tbldomains.registrationperiod, ' Year(s)') as billingcycle"),
                'tbldomains.nextduedate',
                'tbldomains.status',
                Capsule::raw("'' as username"),
                Capsule::raw("'' as dedicatedip"),
                Capsule::raw("0 as promoid"),
                Capsule::raw("'Domain Registration' as product_name"),
                Capsule::raw("'domain' as product_type"),
                Capsule::raw("'Domains' as group_name"),
                Capsule::raw("tbldomains.registrar as server_name"),
                Capsule::raw("'' as server_ip"),
                'tblclients.firstname',
                'tblclients.lastname',
                'tblclients.companyname',
                'tblclients.email',
                'tblclients.phonenumber',
                'tblclients.currency as client_currency'
            );

        // Status Filter
        if ($status === 'Active') {
            $dQuery->where('tbldomains.status', 'Active');
        } elseif ($status === 'Active,Suspended') {
            $dQuery->whereIn('tbldomains.status', ['Active', 'Pending']);
        } elseif (!empty($status) && $status !== 'All' && $status !== 'Any') {
            $dQuery->where('tbldomains.status', $status);
        }

        // Due Filter
        applyDueDateFilter($dQuery, 'tbldomains.nextduedate', $dueFilter);

        // Payment Method Filter
        if (!empty($paymentMethod) && $paymentMethod !== 'Any') {
            $dQuery->where('tbldomains.paymentmethod', $paymentMethod);
        }

        // Search Keyword
        if (!empty($search)) {
            $dQuery->where(function ($q) use ($search) {
                $q->where('tbldomains.id', $search)
                  ->orWhere('tbldomains.domain', 'like', "%{$search}%")
                  ->orWhere('tblclients.firstname', 'like', "%{$search}%")
                  ->orWhere('tblclients.lastname', 'like', "%{$search}%")
                  ->orWhere(Capsule::raw("CONCAT(tblclients.firstname, ' ', tblclients.lastname)"), 'like', "%{$search}%")
                  ->orWhere('tblclients.email', 'like', "%{$search}%")
                  ->orWhere('tblclients.phonenumber', 'like', "%{$search}%")
                  ->orWhere('tblclients.companyname', 'like', "%{$search}%");
            });
        }

        $totalRecords = $dQuery->count();
        $records = $dQuery->orderBy('tbldomains.nextduedate', 'ASC')
                          ->orderBy('tbldomains.id', 'DESC')
                          ->skip(($page - 1) * $limit)
                          ->take($limit)
                          ->get();
    } elseif (empty($productType) || $productType === 'Any' || $productType === 'all') {
        // Query both services and domains (union) or services default
        $totalRecords = $query->count();
        $records = $query->orderBy('tblhosting.nextduedate', 'ASC')
                         ->orderBy('tblhosting.id', 'DESC')
                         ->skip(($page - 1) * $limit)
                         ->take($limit)
                         ->get();
    }

    $today = new \DateTime(date('Y-m-d'));

    // Format output items
    $formattedItems = [];
    foreach ($records as $item) {
        $currencyObj = isset($allCurrencies[$item->client_currency]) ? $allCurrencies[$item->client_currency] : $defaultCurrency;
        $prefix = $currencyObj ? $currencyObj->prefix : '';
        $suffix = $currencyObj ? $currencyObj->suffix : 'BDT';

        // Format Next Due Date & Days Calculation
        $dueDateStr = $item->nextduedate;
        $daysDiff = null;
        $dueBadgeClass = 'badge-secondary';
        $dueBadgeText = '';

        if (!empty($dueDateStr) && $dueDateStr !== '0000-00-00') {
            $dueDate = new \DateTime($dueDateStr);
            $interval = $today->diff($dueDate);
            $days = (int)$interval->format('%r%a');
            $daysDiff = $days;

            if ($days < 0) {
                $dueBadgeClass = 'badge-danger bg-danger';
                $dueBadgeText = 'Overdue by ' . abs($days) . ' d';
            } elseif ($days === 0) {
                $dueBadgeClass = 'badge-warning bg-warning text-dark';
                $dueBadgeText = 'Due Today!';
            } elseif ($days <= $warningDays) {
                $dueBadgeClass = 'badge-warning bg-warning text-dark';
                $dueBadgeText = 'Due in ' . $days . ' d';
            } else {
                $dueBadgeClass = 'badge-info bg-info text-white';
                $dueBadgeText = 'In ' . $days . ' d';
            }
            $formattedDueDate = date('d/m/Y', strtotime($dueDateStr));
        } else {
            $formattedDueDate = 'N/A';
            $dueBadgeText = 'No Date';
        }

        // Format Phone Number & WhatsApp
        $rawPhone = $item->phonenumber;
        $cleanPhone = preg_replace('/[^0-9]/', '', $rawPhone);
        $whatsappNumber = '';

        if (!empty($cleanPhone)) {
            // Bangladesh special logic: if starts with 01X, prepend 88
            if (strlen($cleanPhone) === 11 && substr($cleanPhone, 0, 1) === '0') {
                $whatsappNumber = '88' . $cleanPhone;
            } elseif (strlen($cleanPhone) === 10 && substr($cleanPhone, 0, 1) === '1') {
                $whatsappNumber = '880' . $cleanPhone;
            } elseif (substr($cleanPhone, 0, 2) === '88') {
                $whatsappNumber = $cleanPhone;
            } else {
                $whatsappNumber = $defaultCountryCode . ltrim($cleanPhone, '0');
            }
        }

        // Format Status Badge
        $statusLower = strtolower($item->status);
        $statusBadgeClass = 'badge-secondary';
        if ($statusLower === 'active') {
            $statusBadgeClass = 'badge-success bg-success';
        } elseif ($statusLower === 'suspended') {
            $statusBadgeClass = 'badge-warning bg-warning text-dark';
        } elseif ($statusLower === 'pending') {
            $statusBadgeClass = 'badge-info bg-info';
        } elseif ($statusLower === 'terminated' || $statusLower === 'cancelled' || $statusLower === 'fraud') {
            $statusBadgeClass = 'badge-danger bg-danger';
        }

        // Type Badge
        $typeLabel = 'Service';
        if ($item->record_type === 'domain') {
            $typeLabel = 'Domain';
        } elseif ($item->product_type === 'server') {
            $typeLabel = 'VPS/Server';
        } elseif ($item->product_type === 'hostingaccount') {
            $typeLabel = 'Shared Hosting';
        } elseif ($item->product_type === 'reselleraccount') {
            $typeLabel = 'Reseller';
        } elseif ($item->product_type === 'other') {
            $typeLabel = 'Other';
        }

        $formattedItems[] = [
            'id' => $item->item_id,
            'record_type' => $item->record_type,
            'userid' => $item->userid,
            'orderid' => $item->orderid,
            'regdate' => (!empty($item->regdate) && $item->regdate !== '0000-00-00') ? date('d/m/Y', strtotime($item->regdate)) : '-',
            'product_name' => $item->product_name,
            'product_type' => $item->product_type,
            'type_label' => $typeLabel,
            'group_name' => $item->group_name,
            'domain' => $item->domain ?: '',
            'server_name' => $item->server_name ?: '',
            'server_ip' => $item->server_ip ?: '',
            'dedicatedip' => $item->dedicatedip ?: '',
            'username' => $item->username ?: '',
            'client_name' => trim($item->firstname . ' ' . $item->lastname),
            'company_name' => $item->companyname ?: '',
            'email' => $item->email,
            'phonenumber' => $rawPhone ?: 'N/A',
            'whatsapp_url' => !empty($whatsappNumber) ? "https://wa.me/{$whatsappNumber}" : '',
            'price_formatted' => $prefix . number_format((float)$item->price, 2) . $suffix,
            'paymentmethod' => $item->paymentmethod ?: '-',
            'billingcycle' => $item->billingcycle ?: '-',
            'nextduedate' => $formattedDueDate,
            'days_left' => $daysDiff,
            'due_badge_class' => $dueBadgeClass,
            'due_badge_text' => $dueBadgeText,
            'status' => strtoupper($item->status),
            'status_badge_class' => $statusBadgeClass,
            'service_url' => ($item->record_type === 'domain') ? "clientsdomains.php?id={$item->item_id}" : "clientsservices.php?id={$item->item_id}",
            'client_url' => "clientssummary.php?userid={$item->userid}",
        ];
    }

    $totalPages = ceil($totalRecords / $limit);

    return [
        'success' => true,
        'records' => $formattedItems,
        'total' => $totalRecords,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => $totalPages,
        'showing_from' => $totalRecords > 0 ? (($page - 1) * $limit) + 1 : 0,
        'showing_to' => min($page * $limit, $totalRecords)
    ];
}

/**
 * Apply due date filter logic to query
 */
function applyDueDateFilter($query, $columnName, $dueFilter)
{
    $todayStr = date('Y-m-d');
    if ($dueFilter === 'overdue') {
        $query->where($columnName, '<', $todayStr)->where($columnName, '!=', '0000-00-00');
    } elseif ($dueFilter === 'today') {
        $query->where($columnName, '=', $todayStr);
    } elseif ($dueFilter === '3days') {
        $in3Days = date('Y-m-d', strtotime('+3 days'));
        $query->whereBetween($columnName, [$todayStr, $in3Days]);
    } elseif ($dueFilter === '7days') {
        $in7Days = date('Y-m-d', strtotime('+7 days'));
        $query->whereBetween($columnName, [$todayStr, $in7Days]);
    } elseif ($dueFilter === '15days') {
        $in15Days = date('Y-m-d', strtotime('+15 days'));
        $query->whereBetween($columnName, [$todayStr, $in15Days]);
    } elseif ($dueFilter === '30days' || $dueFilter === 'this_month') {
        $in30Days = date('Y-m-d', strtotime('+30 days'));
        $query->whereBetween($columnName, [$todayStr, $in30Days]);
    }
}
