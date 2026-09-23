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
