<?php
/**
 * WHMCS Addon Module: Client Services & Expiry Monitor Hooks
 *
 * Handles:
 * 1. Automatic suspension prevention during custom grace period (PreModuleSuspend hook)
 * 2. Daily Cron auto-suspension execution when custom grace period deadline expires (DailyCronJob hook)
 * 3. Navigation shortcuts for Admin area
 *
 * @package    WHMCS
 * @author     Bahari IT
 * @copyright  Copyright (c) 2026 Bahari IT
 * @version    2.0.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Hook 1: PreModuleSuspend - Prevent auto-suspension if service is within active grace period
 */
add_hook('PreModuleSuspend', 1, function ($vars) {
    try {
        $serviceId = 0;
        if (isset($vars['params']['serviceid'])) {
            $serviceId = (int)$vars['params']['serviceid'];
        } elseif (isset($vars['params']['accountid'])) {
            $serviceId = (int)$vars['params']['accountid'];
        }

        if ($serviceId <= 0) {
            return;
        }

        // Check if module tables exist
        if (!Capsule::schema()->hasTable('mod_csm_suspension_overrides')) {
            return;
        }

        $override = Capsule::table('mod_csm_suspension_overrides')
            ->where('service_id', $serviceId)
            ->where('status', 'active')
            ->first();

        if ($override && !empty($override->grace_suspend_date)) {
            $today = date('Y-m-d');
            $graceDate = date('Y-m-d', strtotime($override->grace_suspend_date));

            // If today is within or on the grace date, prevent suspension
            if ($today <= $graceDate) {
                logActivity("CSM Hook: Prevented suspension for Service #{$serviceId} - Custom Grace Deadline active until {$graceDate}. Reason: {$override->reason}");
                return [
                    'abortcmd' => true,
                    'abort' => true,
                ];
            }
        }
    } catch (\Exception $e) {
        logActivity("CSM Hook Error in PreModuleSuspend: " . $e->getMessage());
    }
});

/**
 * Hook 2: DailyCronJob - Check all expired grace suspension overrides and trigger suspension
 */
add_hook('DailyCronJob', 1, function ($vars) {
    try {
        if (!Capsule::schema()->hasTable('mod_csm_suspension_overrides')) {
            return;
        }

        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        // Find all active overrides where grace date has passed
        $expiredOverrides = Capsule::table('mod_csm_suspension_overrides')
            ->where('status', 'active')
            ->where('grace_suspend_date', '<', $today)
            ->get();

        foreach ($expiredOverrides as $override) {
            $serviceId = (int)$override->service_id;
            $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();

            if (!$service) {
                Capsule::table('mod_csm_suspension_overrides')
                    ->where('id', $override->id)
                    ->update(['status' => 'expired', 'updated_at' => $now]);
                continue;
            }

            // Only suspend if service is currently Active
            if ($service->domainstatus === 'Active') {
                $suspendReason = 'CSM Grace Deadline Expired (' . date('d/m/Y', strtotime($override->grace_suspend_date)) . ') - ' . ($override->reason ?: 'Overdue Payment');

                // Call WHMCS local API to suspend module service
                $command = 'ModuleSuspend';
                $postData = [
                    'serviceid'  => $serviceId,
                    'suspreason' => $suspendReason,
                ];

                $results = localAPI($command, $postData);

                if (isset($results['result']) && $results['result'] === 'success') {
                    // Update override status to suspended
                    Capsule::table('mod_csm_suspension_overrides')
                        ->where('id', $override->id)
                        ->update([
                            'status'     => 'suspended',
                            'updated_at' => $now,
                        ]);

                    // Add note to service ledger
                    if (Capsule::schema()->hasTable('mod_csm_service_notes')) {
                        Capsule::table('mod_csm_service_notes')->insert([
                            'rel_type'    => 'service',
                            'rel_id'      => $serviceId,
                            'note'        => "[Auto-Suspended] Grace deadline {$override->grace_suspend_date} expired. Service automatically suspended.",
                            'admin_name'  => 'CSM Automation Cron',
                            'created_at'  => $now,
                        ]);
                    }

                    logActivity("CSM Cron: Automatically suspended Service #{$serviceId} after grace deadline ({$override->grace_suspend_date}) expired.");
                } else {
                    logActivity("CSM Cron Error: Failed to auto-suspend Service #{$serviceId}. Response: " . json_encode($results));
                }
            } else {
                // If service is already Suspended, Terminated, or Cancelled, mark override as expired
                Capsule::table('mod_csm_suspension_overrides')
                    ->where('id', $override->id)
                    ->update([
                        'status'     => 'expired',
                        'updated_at' => $now,
                    ]);
            }
        }
    } catch (\Exception $e) {
        logActivity("CSM Hook Error in DailyCronJob: " . $e->getMessage());
    }
});

/**
 * Hook 3: AdminHomeWidgets - Dashboard Widget for Corporate Customers & Ledgers
 */
if (class_exists('\WHMCS\Module\AbstractWidget')) {
    class CsmCorporateCustomersWidget extends \WHMCS\Module\AbstractWidget
    {
        protected $title = 'Corporate Customers & Monthly Ledger';
        protected $description = 'Real-time overview of Corporate Clients, Monthly Bill Dues, Payments, and Service Expiries.';
        protected $weight = 10;
        protected $columns = 2;
        protected $show = true;
        protected $wrapper = true;

        public function getData()
        {
            try {
                if (!Capsule::schema()->hasTable('mod_csm_monitored_clients')) {
                    return null;
                }

                $activeCurrency = Capsule::table('tblcurrencies')->where('default', 1)->first();
                $prefix = $activeCurrency ? $activeCurrency->prefix : '৳ ';
                $suffix = $activeCurrency && !empty($activeCurrency->suffix) ? $activeCurrency->suffix : '';

                $currentMonth = date('Y-m');
                $currentMonthName = date('F Y');
                $today = date('Y-m-d');

                // Monthly Ledgers for current month
                $monthlyLedgers = collect([]);
                if (Capsule::schema()->hasTable('mod_csm_monthly_ledgers')) {
                    $monthlyLedgers = Capsule::table('mod_csm_monthly_ledgers')
                        ->where('billing_month', $currentMonth)
                        ->get()
                        ->keyBy('group_key');
                }

                // Fetch monitored rows
                $monitoredRows = Capsule::table('mod_csm_monitored_clients')->get()->keyBy('userid');
                $monitoredUserIds = $monitoredRows->keys()->toArray();

                $clients = !empty($monitoredUserIds) ? Capsule::table('tblclients')
                    ->whereIn('id', $monitoredUserIds)
                    ->select('id', 'firstname', 'lastname', 'companyname', 'email', 'phonenumber', 'status')
                    ->get() : collect([]);

                $customCustomers = Capsule::table('mod_csm_custom_customers')->get();

                // Service counts & recurring per client
                $services = !empty($monitoredUserIds) ? Capsule::table('tblhosting')
                    ->whereIn('userid', $monitoredUserIds)
                    ->where('domainstatus', 'Active')
                    ->select('id', 'userid', 'amount')
                    ->get() : collect([]);

                $domains = !empty($monitoredUserIds) ? Capsule::table('tbldomains')
                    ->whereIn('userid', $monitoredUserIds)
                    ->where('status', 'Active')
                    ->select('id', 'userid', 'recurringamount as amount')
                    ->get() : collect([]);

                $clientRecurringMap = [];
                $clientServiceCountMap = [];

                foreach ($services as $srv) {
                    $uId = (int)$srv->userid;
                    $clientRecurringMap[$uId] = ($clientRecurringMap[$uId] ?? 0.00) + (float)$srv->amount;
                    $clientServiceCountMap[$uId] = ($clientServiceCountMap[$uId] ?? 0) + 1;
                }
                foreach ($domains as $dom) {
                    $uId = (int)$dom->userid;
                    $clientRecurringMap[$uId] = ($clientRecurringMap[$uId] ?? 0.00) + (float)$dom->amount;
                    $clientServiceCountMap[$uId] = ($clientServiceCountMap[$uId] ?? 0) + 1;
                }

                $list = [];
                $monthTotalTarget = 0.00;
                $monthTotalPaid = 0.00;
                $monthTotalDue = 0.00;
                $unpaidCount = 0;
                $paidCount = 0;
                $activeServicesCount = $services->count();

                // 1. WHMCS Clients
                foreach ($clients as $c) {
                    $key = 'client_' . $c->id;
                    $cfg = $monitoredRows->get($c->id);
                    $alias = $cfg && !empty($cfg->custom_alias_name) ? $cfg->custom_alias_name : '';
                    $srvCount = $clientServiceCountMap[$c->id] ?? 0;
                    $totalRec = $clientRecurringMap[$c->id] ?? 0.00;

                    // Resolve monthly ledger
                    if (isset($monthlyLedgers[$key])) {
                        $mLedger = $monthlyLedgers[$key];
                        $rec = (float)$mLedger->total_recurring;
                        $paid = (float)$mLedger->paid_amount;
                        $due = (float)$mLedger->due_amount;
                        $status = strtolower($mLedger->status ?: ($due > 0 ? 'unpaid' : 'paid'));
                        $payDate = $mLedger->pay_date ?: '';
                        $notes = $mLedger->notes ?: '';
                    } else {
                        $rec = $totalRec;
                        $hasLegacyDue = ($cfg && (float)$cfg->custom_due_amount > 0);
                        $due = $hasLegacyDue ? (float)$cfg->custom_due_amount : $totalRec;
                        $legacyPaid = ($cfg && $cfg->paid_status === 'paid' && !$hasLegacyDue);
                        $paid = $legacyPaid ? $totalRec : 0.00;
                        $status = ($paid >= $rec && $rec > 0) ? 'paid' : ($due > 0 ? 'unpaid' : 'paid');
                        $payDate = ($cfg && !empty($cfg->last_paid_date) && $cfg->last_paid_date !== '0000-00-00') ? $cfg->last_paid_date : (!empty($cfg->monthly_pay_day) ? ($currentMonth . '-' . str_pad($cfg->monthly_pay_day, 2, '0', STR_PAD_LEFT)) : '');
                        $notes = ($cfg && !empty($cfg->custom_due_note)) ? $cfg->custom_due_note : '';
                    }

                    if ($status === 'paid') {
                        $paidCount++;
                    } else {
                        $unpaidCount++;
                    }

                    $monthTotalTarget += $rec;
                    $monthTotalPaid += $paid;
                    $monthTotalDue += $due;

                    $list[] = [
                        'key'         => $key,
                        'userid'      => $c->id,
                        'name'        => trim($c->firstname . ' ' . $c->lastname),
                        'company'     => $c->companyname ?: '',
                        'alias'       => $alias,
                        'phone'       => str_replace('.', ' ', $c->phonenumber ?: ''),
                        'products'    => $srvCount,
                        'recurring'   => $rec,
                        'paid_amount' => $paid,
                        'due_amount'  => $due,
                        'due_note'    => $notes,
                        'pay_date'    => $payDate,
                        'paid_status' => $status,
                    ];
                }

                // 2. Offline / Custom Customers
                foreach ($customCustomers as $cust) {
                    $key = 'offline_' . $cust->id;
                    $totalRec = (float)$cust->amount;
                    $alias = !empty($cust->custom_alias_name) ? $cust->custom_alias_name : '';

                    if (isset($monthlyLedgers[$key])) {
                        $mLedger = $monthlyLedgers[$key];
                        $rec = (float)$mLedger->total_recurring;
                        $paid = (float)$mLedger->paid_amount;
                        $due = (float)$mLedger->due_amount;
                        $status = strtolower($mLedger->status ?: ($due > 0 ? 'unpaid' : 'paid'));
                        $payDate = $mLedger->pay_date ?: '';
                        $notes = $mLedger->notes ?: '';
                    } else {
                        $rec = $totalRec;
                        $due = (float)$cust->amount;
                        $status = !empty($cust->status) ? strtolower($cust->status) : ($due > 0 ? 'unpaid' : 'paid');
                        $paid = ($status === 'paid') ? $rec : 0.00;
                        $payDate = (!empty($cust->last_paid_date) && $cust->last_paid_date !== '0000-00-00') ? $cust->last_paid_date : (!empty($cust->monthly_pay_day) ? ($currentMonth . '-' . str_pad($cust->monthly_pay_day, 2, '0', STR_PAD_LEFT)) : '');
                        $notes = !empty($cust->custom_due_note) ? $cust->custom_due_note : (!empty($cust->notes) ? $cust->notes : '');
                    }

                    if ($status === 'paid') {
                        $paidCount++;
                    } else {
                        $unpaidCount++;
                    }

                    $monthTotalTarget += $rec;
                    $monthTotalPaid += $paid;
                    $monthTotalDue += $due;

                    $list[] = [
                        'key'         => $key,
                        'userid'      => 0,
                        'name'        => $cust->customer_name ?: 'Custom Client',
                        'company'     => $cust->company_name ?: '',
                        'alias'       => $alias,
                        'phone'       => str_replace('.', ' ', $cust->phone ?: ''),
                        'products'    => 1,
                        'recurring'   => $rec,
                        'paid_amount' => $paid,
                        'due_amount'  => $due,
                        'due_note'    => $notes,
                        'pay_date'    => $payDate,
                        'paid_status' => $status,
                    ];
                }

                // Sort:
                // 1. Unpaid / Partial at Top (sorted by pay_date ASC, then due_amount DESC)
                // 2. Paid at Bottom (sorted by pay_date DESC)
                uasort($list, function ($a, $b) {
                    $isUnpaidA = ($a['paid_status'] !== 'paid' || (float)$a['due_amount'] > 0) ? 1 : 0;
                    $isUnpaidB = ($b['paid_status'] !== 'paid' || (float)$b['due_amount'] > 0) ? 1 : 0;
                    if ($isUnpaidA !== $isUnpaidB) {
                        return $isUnpaidB - $isUnpaidA;
                    }
                    if ($isUnpaidA === 1) {
                        $dA = (!empty($a['pay_date']) && $a['pay_date'] !== '0000-00-00') ? $a['pay_date'] : '9999-99-99';
                        $dB = (!empty($b['pay_date']) && $b['pay_date'] !== '0000-00-00') ? $b['pay_date'] : '9999-99-99';
                        if ($dA !== $dB) {
                            return strcmp($dA, $dB);
                        }
                        if ((float)$a['due_amount'] !== (float)$b['due_amount']) {
                            return ((float)$b['due_amount'] > (float)$a['due_amount']) ? 1 : -1;
                        }
                    } else {
                        $dA = (!empty($a['pay_date']) && $a['pay_date'] !== '0000-00-00') ? $a['pay_date'] : '0000-00-00';
                        $dB = (!empty($b['pay_date']) && $b['pay_date'] !== '0000-00-00') ? $b['pay_date'] : '0000-00-00';
                        if ($dA !== $dB) {
                            return strcmp($dB, $dA);
                        }
                    }
                    return strcmp($a['name'], $b['name']);
                });

                return [
                    'month_name'      => $currentMonthName,
                    'total_clients'   => count($list),
                    'month_target'    => $monthTotalTarget,
                    'month_paid'      => $monthTotalPaid,
                    'month_due'       => $monthTotalDue,
                    'unpaid_count'    => $unpaidCount,
                    'paid_count'      => $paidCount,
                    'active_services' => $activeServicesCount,
                    'curr_prefix'     => $prefix,
                    'curr_suffix'     => $suffix,
                    'clients'         => array_values($list),
                ];
            } catch (\Exception $e) {
                return null;
            }
        }

        public function generateOutput($data)
        {
            if (!$data) {
                return '<div class="widget-content-padded text-muted text-center" style="padding:20px;"><i class="fas fa-info-circle"></i> Corporate Monitor module data unavailable.</div>';
            }

            $moduleLink = 'addonmodules.php?module=client_services_monitor&action=custom_customers';
            $pfx = htmlspecialchars($data['curr_prefix'], ENT_QUOTES, 'UTF-8');
            $sfx = htmlspecialchars($data['curr_suffix'], ENT_QUOTES, 'UTF-8');
            $monthName = htmlspecialchars($data['month_name'], ENT_QUOTES, 'UTF-8');
            $today = date('Y-m-d');
            $todayTs = strtotime($today);

            $out = '
            <div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
                <!-- Month Header & Metrics Row -->
                <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:10px;padding:12px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <div style="background:#ffffff;border:1px solid #dce6f2;border-radius:8px;padding:10px 12px;border-left:4px solid #0284c7;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                        <div style="font-size:10.5px;color:#64748b;text-transform:uppercase;font-weight:700;letter-spacing:0.5px;">' . $monthName . ' Target</div>
                        <div style="font-size:18px;font-weight:800;color:#0f5ea8;margin-top:2px;">' . $pfx . number_format((float)$data['month_target'], 2) . $sfx . '</div>
                    </div>
                    <div style="background:#ffffff;border:1px solid #dce6f2;border-radius:8px;padding:10px 12px;border-left:4px solid #16a34a;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                        <div style="font-size:10.5px;color:#64748b;text-transform:uppercase;font-weight:700;letter-spacing:0.5px;">Collected / Paid</div>
                        <div style="font-size:18px;font-weight:800;color:#16a34a;margin-top:2px;">' . $pfx . number_format((float)$data['month_paid'], 2) . $sfx . '</div>
                    </div>
                    <div style="background:#ffffff;border:1px solid #dce6f2;border-radius:8px;padding:10px 12px;border-left:4px solid #dc2626;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                        <div style="font-size:10.5px;color:#64748b;text-transform:uppercase;font-weight:700;letter-spacing:0.5px;">Remaining Due</div>
                        <div style="font-size:18px;font-weight:800;color:#dc2626;margin-top:2px;">' . $pfx . number_format((float)$data['month_due'], 2) . $sfx . '</div>
                    </div>
                    <div style="background:#ffffff;border:1px solid #dce6f2;border-radius:8px;padding:10px 12px;border-left:4px solid #f59e0b;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                        <div style="font-size:10.5px;color:#64748b;text-transform:uppercase;font-weight:700;letter-spacing:0.5px;">Unpaid Clients</div>
                        <div style="font-size:18px;font-weight:800;color:#d97706;margin-top:2px;">' . (int)$data['unpaid_count'] . ' <small style="font-size:11px;color:#64748b;font-weight:600;">of ' . (int)$data['total_clients'] . '</small></div>
                    </div>
                </div>

                <!-- Corporate Customers Mini Table -->
                <div style="max-height:380px;overflow-y:auto;">
                    <table class="table table-hover" style="margin-bottom:0;font-size:12.5px;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#334155;font-weight:700;font-size:11.5px;border-bottom:2px solid #e2e8f0;">
                                <th style="padding:10px 14px;">Client &amp; Nickname</th>
                                <th style="padding:10px 12px;">Contact</th>
                                <th style="padding:10px 12px;" class="text-center">Products</th>
                                <th style="padding:10px 12px;">' . $monthName . ' Bill</th>
                                <th style="padding:10px 12px;">Bill Pay Date</th>
                                <th style="padding:10px 12px;" class="text-center">Status</th>
                                <th style="padding:10px 14px;" class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>';

            if (empty($data['clients'])) {
                $out .= '<tr><td colspan="7" style="text-align:center;padding:30px;color:#64748b;">
                    <i class="fas fa-users-gear" style="font-size:28px;opacity:0.4;margin-bottom:6px;display:block;"></i>
                    No corporate clients configured. <a href="' . $moduleLink . '" style="color:#0284c7;font-weight:700;">Open Corporate Monitor</a>
                </td></tr>';
            } else {
                $topClients = array_slice($data['clients'], 0, 15);
                foreach ($topClients as $cl) {
                    $isWhmcs = $cl['userid'] > 0;
                    $nameLink = $isWhmcs ? '<a href="clientssummary.php?userid=' . $cl['userid'] . '" target="_blank" style="font-weight:700;color:#0f5ea8;">' . htmlspecialchars($cl['name'], ENT_QUOTES, 'UTF-8') . '</a>' : '<strong style="color:#1e293b;">' . htmlspecialchars($cl['name'], ENT_QUOTES, 'UTF-8') . '</strong>';
                    
                    $aliasBadge = '';
                    if (!empty($cl['alias'])) {
                        $aliasBadge = '<div style="margin-bottom:2px;"><span style="background:#fef3c7;color:#92400e;border:1.5px solid #fde68a;padding:2px 8px;border-radius:5px;font-size:13px;font-weight:800;display:inline-block;"><i class="fas fa-id-badge text-warning"></i> ' . htmlspecialchars($cl['alias'], ENT_QUOTES, 'UTF-8') . '</span></div>';
                    }

                    // Phone / WA
                    $phoneClean = $cl['phone'];
                    $digitsPhone = preg_replace('/[^0-9]/', '', $phoneClean);
                    $waLink = !empty($digitsPhone) ? '<a href="https://wa.me/' . $digitsPhone . '" target="_blank" class="btn btn-default btn-xs" style="color:#16a34a;padding:1px 5px;" title="WhatsApp Reminder"><i class="fab fa-whatsapp"></i></a>' : '';

                    // Monthly Bill & Due
                    $isUnpaid = ($cl['paid_status'] !== 'paid' || (float)$cl['due_amount'] > 0);
                    $billDisplay = '';
                    if ($cl['due_amount'] > 0) {
                        $billDisplay = '<div><strong style="color:#dc2626;font-size:13px;"><i class="fas fa-circle-exclamation"></i> Due ' . $pfx . number_format($cl['due_amount'], 2) . $sfx . '</strong></div>';
                        if ($cl['recurring'] > 0 && $cl['paid_amount'] > 0) {
                            $billDisplay .= '<div style="font-size:10.5px;color:#16a34a;font-weight:600;">Paid: ' . $pfx . number_format($cl['paid_amount'], 2) . ' / Rec: ' . $pfx . number_format($cl['recurring'], 2) . '</div>';
                        }
                    } else {
                        $billDisplay = '<div><strong style="color:#16a34a;font-size:12.5px;"><i class="fas fa-circle-check"></i> ' . $pfx . number_format($cl['recurring'], 2) . $sfx . '</strong></div><div style="font-size:10px;color:#16a34a;font-weight:700;">100% Paid</div>';
                    }
                    if (!empty($cl['due_note'])) {
                        $billDisplay .= '<div style="color:#64748b;font-size:10.5px;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' . htmlspecialchars($cl['due_note'], ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-note-sticky text-info"></i> ' . htmlspecialchars($cl['due_note'], ENT_QUOTES, 'UTF-8') . '</div>';
                    }

                    // Bill Pay Date
                    $paidDateHtml = '';
                    if (!empty($cl['pay_date']) && $cl['pay_date'] !== '0000-00-00') {
                        $pTs = strtotime($cl['pay_date']);
                        $diff = (int)round(($pTs - $todayTs) / 86400);
                        $dateFmt = date('d/m/Y', $pTs);
                        if (!$isUnpaid) {
                            $paidDateHtml = '<strong style="color:#16a34a;font-size:12px;">' . $dateFmt . '</strong>';
                        } else {
                            if ($diff < 0) {
                                $paidDateHtml = '<strong style="color:#dc2626;font-size:12px;">' . $dateFmt . '</strong><br><span class="label label-danger" style="font-size:9px;">Overdue ' . abs($diff) . 'd</span>';
                            } elseif ($diff === 0) {
                                $paidDateHtml = '<strong style="color:#d97706;font-size:12px;">' . $dateFmt . '</strong><br><span class="label label-warning" style="font-size:9px;">Due Today</span>';
                            } else {
                                $paidDateHtml = '<strong style="color:#0f5ea8;font-size:12px;">' . $dateFmt . '</strong><br><span class="label label-info" style="font-size:9px;">In ' . $diff . 'd</span>';
                            }
                        }
                    } else {
                        $paidDateHtml = '<span class="text-muted" style="font-size:11px;">Not Set</span>';
                    }

                    // Status Badge
                    $statusBadge = '';
                    if ($cl['paid_status'] === 'paid') {
                        $statusBadge = '<span class="label label-success" style="font-size:10px;padding:3px 8px;font-weight:700;"><i class="fas fa-check"></i> Paid</span>';
                    } elseif ($cl['paid_status'] === 'partial') {
                        $statusBadge = '<span class="label label-warning" style="font-size:10px;padding:3px 8px;font-weight:700;background:#f59e0b;"><i class="fas fa-half-stroke"></i> Partial</span>';
                    } else {
                        $statusBadge = '<span class="label label-danger" style="font-size:10px;padding:3px 8px;font-weight:700;"><i class="fas fa-xmark"></i> Unpaid</span>';
                    }

                    $out .= '<tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px 14px;vertical-align:middle;">
                            ' . $aliasBadge . '
                            <div style="font-size:11.5px;color:#475569;">' . ($isWhmcs ? '<span class="label label-primary" style="font-size:9px;margin-right:3px;">#' . $cl['userid'] . '</span> ' : '<span class="label label-default" style="font-size:9px;margin-right:3px;">Offline</span> ') . $nameLink . '</div>
                        </td>
                        <td style="padding:10px 12px;vertical-align:middle;white-space:nowrap;">
                            <span style="font-size:11.5px;color:#1e293b;font-weight:600;">' . ($phoneClean ?: '—') . '</span> ' . $waLink . '
                        </td>
                        <td style="padding:10px 12px;vertical-align:middle;" class="text-center">
                            <span class="badge" style="background:#e0f2fe;color:#0369a1;font-weight:700;font-size:11px;padding:3px 7px;">' . (int)$cl['products'] . '</span>
                        </td>
                        <td style="padding:10px 12px;vertical-align:middle;">
                            ' . $billDisplay . '
                        </td>
                        <td style="padding:10px 12px;vertical-align:middle;">
                            ' . $paidDateHtml . '
                        </td>
                        <td style="padding:10px 12px;vertical-align:middle;" class="text-center">
                            ' . $statusBadge . '
                        </td>
                        <td style="padding:10px 14px;vertical-align:middle;text-align:center;white-space:nowrap;">
                            <a href="' . $moduleLink . '" class="btn btn-default btn-xs" style="font-weight:700;color:#0f5ea8;" title="Open in Corporate Monitor"><i class="fas fa-arrow-up-right-from-square"></i> View</a>
                        </td>
                    </tr>';
                }
            }

            $out .= '</tbody></table></div>
                <div style="padding:10px 14px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:11.5px;color:#64748b;"><i class="fas fa-shield-alt text-primary"></i> Bahari IT Corporate Monitor &bull; ' . $monthName . '</span>
                    <a href="' . $moduleLink . '" class="btn btn-primary btn-xs" style="font-weight:700;padding:4px 12px;"><i class="fas fa-users-gear"></i> Open Full Corporate Monitor Board &rarr;</a>
                </div>
            </div>';

            return $out;
        }
    }

    add_hook('AdminHomeWidgets', 1, function () {
        return new CsmCorporateCustomersWidget();
    });
}

