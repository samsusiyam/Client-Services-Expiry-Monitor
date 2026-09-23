<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
?>

<!-- FontAwesome & Custom CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../modules/addons/client_services_monitor/assets/css/style.css?v=<?php echo time(); ?>">

<div class="csm-container">
    <!-- Quick Stats Summary Cards -->
    <div class="csm-stats-row">
        <div class="csm-stat-card card-total" onclick="csmSetDueFilter('')">
            <div class="csm-stat-icon"><i class="fa-solid fa-cubes"></i></div>
            <div class="csm-stat-info">
                <span class="csm-stat-count" id="statTotalActive">0</span>
                <span class="csm-stat-label">Active Services</span>
            </div>
        </div>
        <div class="csm-stat-card card-due-today" onclick="csmSetDueFilter('today')">
            <div class="csm-stat-icon"><i class="fa-solid fa-clock"></i></div>
            <div class="csm-stat-info">
                <span class="csm-stat-count" id="statDueToday">0</span>
                <span class="csm-stat-label">Due Today</span>
            </div>
        </div>
        <div class="csm-stat-card card-due-3days" onclick="csmSetDueFilter('3days')">
            <div class="csm-stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="csm-stat-info">
                <span class="csm-stat-count" id="statDue3Days">0</span>
                <span class="csm-stat-label">Due in 3 Days</span>
            </div>
        </div>
        <div class="csm-stat-card card-due-7days" onclick="csmSetDueFilter('7days')">
            <div class="csm-stat-icon"><i class="fa-solid fa-calendar-week"></i></div>
            <div class="csm-stat-info">
                <span class="csm-stat-count" id="statDue7Days">0</span>
                <span class="csm-stat-label">Due in 7 Days</span>
            </div>
        </div>
        <div class="csm-stat-card card-overdue" onclick="csmSetDueFilter('overdue')">
            <div class="csm-stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="csm-stat-info">
                <span class="csm-stat-count" id="statOverdue">0</span>
                <span class="csm-stat-label">Overdue</span>
            </div>
        </div>
    </div>

    <!-- Search & Filter Card -->
    <div class="panel panel-default csm-filter-panel" style="border-radius:8px;border:1px solid #dce6f2;">
        <div class="panel-heading" style="background:#f8fafc;padding:12px 18px;">
            <h3 class="panel-title" style="font-weight:700;font-size:14px;"><i class="fa-solid fa-filter text-primary"></i> Real-Time Search &amp; Filtering</h3>
        </div>
        <div class="panel-body" style="padding:18px;">
            <form id="csmFilterForm" onsubmit="return false;">
                <div class="row">
                    <!-- Left Column -->
                    <div class="col-md-6 col-sm-12">
                        <div class="form-group row">
                            <label class="col-sm-4 control-label">Product Type</label>
                            <div class="col-sm-8">
                                <select id="filterProductType" class="form-control">
                                    <option value="">Any / All Types</option>
                                    <option value="server">VPS / Dedicated Server</option>
                                    <option value="hostingaccount">Shared Hosting</option>
                                    <option value="reselleraccount">Reseller Hosting</option>
                                    <option value="other">Other Product/Service</option>
                                    <option value="domain">Domain Names</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-4 control-label">Product/Service</label>
                            <div class="col-sm-8">
                                <select id="filterProductId" class="form-control">
                                    <option value="0">Any</option>
                                    <?php foreach ($products as $prod): ?>
                                        <option value="<?php echo $prod->id; ?>" data-type="<?php echo $prod->type; ?>">
                                            <?php echo htmlspecialchars($prod->group_name . ' - ' . $prod->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-4 control-label">Billing Cycle</label>
                            <div class="col-sm-8">
                                <select id="filterBillingCycle" class="form-control">
                                    <option value="Any">Any</option>
                                    <option value="Monthly">Monthly</option>
                                    <option value="Quarterly">Quarterly</option>
                                    <option value="Semi-Annually">Semi-Annually</option>
                                    <option value="Annually">Annually</option>
                                    <option value="Biennially">Biennially</option>
                                    <option value="Triennially">Triennially</option>
                                    <option value="One Time">One Time</option>
                                    <option value="Free Account">Free Account</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-4 control-label">Next Due Filter</label>
                            <div class="col-sm-8">
                                <select id="filterDueStatus" class="form-control">
                                    <option value="">All Due Dates</option>
                                    <option value="today">Due Today</option>
                                    <option value="3days">Expiring in 3 Days</option>
                                    <option value="7days">Expiring in 7 Days</option>
                                    <option value="15days">Expiring in 15 Days</option>
                                    <option value="30days">Expiring in 30 Days / This Month</option>
                                    <option value="overdue">Overdue (Expired)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="col-md-6 col-sm-12">
                        <div class="form-group row">
                            <label class="col-sm-4 control-label">Server</label>
                            <div class="col-sm-8">
                                <select id="filterServer" class="form-control">
                                    <option value="0">Any</option>
                                    <?php foreach ($servers as $srv): ?>
                                        <option value="<?php echo $srv->id; ?>">
                                            <?php echo htmlspecialchars($srv->name . ($srv->ipaddress ? ' (' . $srv->ipaddress . ')' : '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-4 control-label">Payment Method</label>
                            <div class="col-sm-8">
                                <select id="filterPaymentMethod" class="form-control">
                                    <option value="Any">Any</option>
                                    <?php foreach ($paymentGateways as $gw): ?>
                                        <option value="<?php echo htmlspecialchars($gw->gateway); ?>">
                                            <?php echo htmlspecialchars($gw->value ?: $gw->gateway); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-4 control-label">Status</label>
                            <div class="col-sm-8">
                                <select id="filterStatus" class="form-control">
                                    <option value="Active" selected>Active (Default)</option>
                                    <option value="Active,Suspended">Active &amp; Suspended</option>
                                    <option value="Suspended">Suspended</option>
                                    <option value="Pending">Pending</option>
                                    <option value="Terminated">Terminated</option>
                                    <option value="Cancelled">Cancelled</option>
                                    <option value="All">All Statuses</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-4 control-label">Live Search</label>
                            <div class="col-sm-8">
                                <div class="input-group">
                                    <input type="text" id="filterKeyword" class="form-control" placeholder="Search Client, Phone, Domain, IP, ID...">
                                    <span class="input-group-btn">
                                        <button class="btn btn-default" type="button" id="csmBtnClearKeyword" title="Clear Search">
                                            <i class="fa-solid fa-times"></i>
                                        </button>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row csm-filter-buttons" style="margin-top:10px;">
                    <div class="col-xs-12 text-center">
                        <button type="button" id="csmBtnSearch" class="btn btn-primary" style="margin-right:8px;">
                            <i class="fa-solid fa-search"></i> Search / Apply Filter
                        </button>
                        <button type="button" id="csmBtnReset" class="btn btn-default">
                            <i class="fa-solid fa-rotate-left"></i> Reset Filters
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Table Meta Bar -->
    <div class="csm-table-meta" style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div class="csm-record-count">
            <span id="csmRecordCountText" style="font-weight:700;color:#334155;">Loading records...</span>
        </div>
        <div class="csm-meta-controls" style="display:flex;align-items:center;gap:12px;">
            <label style="margin:0;font-size:13px;display:flex;align-items:center;gap:6px;">Per Page:
                <select id="csmPerPageSelect" class="form-control input-sm" style="width:auto;display:inline-block;">
                    <option value="25">25</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                    <option value="250">250</option>
                </select>
            </label>
            <button id="csmBtnManualRefresh" class="btn btn-default btn-sm" title="Refresh Live Data">
                <i class="fa-solid fa-sync" id="csmRefreshIcon"></i> Refresh
            </button>
        </div>
    </div>

    <!-- Main Results Table -->
    <div class="csm-table-card">
        <div style="overflow-x:auto;">
            <table class="csm-table" id="csmServicesTable">
                <thead>
                    <tr>
                        <th width="70">ID</th>
                        <th>Product / Service</th>
                        <th>Domain</th>
                        <th>Client Details</th>
                        <th>Phone / WhatsApp</th>
                        <th>Billing</th>
                        <th>Next Due Date <i class="fa-solid fa-arrow-down-short-wide text-primary"></i></th>
                        <th>Grace Suspend</th>
                        <th>Due Note / Remarks</th>
                        <th>Status</th>
                        <th width="90" class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody id="csmTableBody">
                    <tr>
                        <td colspan="11" class="text-center csm-loading-state" style="padding:40px;">
                            <i class="fa-solid fa-spinner fa-spin fa-2x text-primary"></i>
                            <p style="margin-top:10px;color:#64748b;">Loading live services...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Bottom Pagination -->
    <div class="csm-pagination-bottom-wrapper" style="margin-top:14px;display:flex;justify-content:center;">
        <div id="csmPaginationBottom"></div>
    </div>
</div>

<!-- Modal: Due Note & Ledger -->
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
                    <textarea id="modalNoteText" class="form-control" rows="2" placeholder="e.g. Paid 500 Tk via bKash, remaining 500 Tk due on 25th"></textarea>
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

                <button type="button" class="btn btn-primary btn-block" id="btnSaveNoteAjax" onclick="csmSaveNoteAjax()">
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
</div>

<!-- Modal: Custom Suspend Deadline (Grace Period) -->
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
                <button type="button" class="btn btn-primary" onclick="csmSaveGraceAjax()"><i class="fas fa-save"></i> Save Deadline</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Quick View Service Specs & Details -->
<div class="modal fade" id="csmQuickViewModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="border-radius:10px;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,0.2);">
            <div class="modal-header" style="background:#12589b;color:#fff;padding:16px 22px;">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:0.9;font-size:24px;">&times;</button>
                <h4 class="modal-title" style="font-weight:700;display:flex;align-items:center;gap:10px;">
                    <i class="fa-solid fa-circle-info"></i>
                    <span id="qvTitle">Service Overview &amp; Specifications</span>
                </h4>
            </div>
            <div class="modal-body" style="padding:22px;background:#f8fafc;">
                <div class="row">
                    <!-- Client Overview Column -->
                    <div class="col-md-6">
                        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:15px;">
                            <h5 style="font-weight:800;color:#0f5ea8;margin-top:0;margin-bottom:12px;border-bottom:1px solid #f1f5f9;padding-bottom:8px;">
                                <i class="fa-solid fa-user"></i> Client Profile
                            </h5>
                            <table class="table table-condensed" style="margin-bottom:0;font-size:13px;">
                                <tr>
                                    <td width="110" style="color:#64748b;font-weight:600;border:0;">Client Name:</td>
                                    <td style="font-weight:700;color:#1e293b;border:0;" id="qvClientName">-</td>
                                </tr>
                                <tr>
                                    <td style="color:#64748b;font-weight:600;">Company:</td>
                                    <td id="qvCompany">-</td>
                                </tr>
                                <tr>
                                    <td style="color:#64748b;font-weight:600;">Email Address:</td>
                                    <td><a id="qvEmailLink" href="mailto:" style="color:#2563eb;">-</a></td>
                                </tr>
                                <tr>
                                    <td style="color:#64748b;font-weight:600;">Phone Number:</td>
                                    <td>
                                        <span id="qvPhoneText" style="font-weight:700;">-</span>
                                        <div id="qvPhoneButtons" style="margin-top:4px;display:flex;gap:5px;"></div>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- Billing & Expiry Card -->
                        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">
                            <h5 style="font-weight:800;color:#0f5ea8;margin-top:0;margin-bottom:12px;border-bottom:1px solid #f1f5f9;padding-bottom:8px;">
                                <i class="fa-solid fa-credit-card"></i> Billing &amp; Due Status
                            </h5>
                            <table class="table table-condensed" style="margin-bottom:0;font-size:13px;">
                                <tr>
                                    <td width="110" style="color:#64748b;font-weight:600;border:0;">Recurring Price:</td>
                                    <td style="font-weight:800;color:#1e293b;border:0;font-size:15px;" id="qvPrice">-</td>
                                </tr>
                                <tr>
                                    <td style="color:#64748b;font-weight:600;">Billing Cycle:</td>
                                    <td style="font-weight:600;" id="qvBillingCycle">-</td>
                                </tr>
                                <tr>
                                    <td style="color:#64748b;font-weight:600;">Next Due Date:</td>
                                    <td>
                                        <span id="qvNextDueDate" style="font-weight:800;">-</span>
                                        <span id="qvDueBadge" style="margin-left:6px;"></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="color:#64748b;font-weight:600;">Grace Deadline:</td>
                                    <td id="qvGraceSuspend">-</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Service Details Column -->
                    <div class="col-md-6">
                        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:15px;">
                            <h5 style="font-weight:800;color:#0f5ea8;margin-top:0;margin-bottom:12px;border-bottom:1px solid #f1f5f9;padding-bottom:8px;">
                                <i class="fa-solid fa-server"></i> Service Specifications
                            </h5>
                            <table class="table table-condensed" style="margin-bottom:0;font-size:13px;">
                                <tr>
                                    <td width="110" style="color:#64748b;font-weight:600;border:0;">Service ID:</td>
                                    <td style="font-weight:700;border:0;" id="qvServiceId">-</td>
                                </tr>
                                <tr>
                                    <td style="color:#64748b;font-weight:600;">Product / Plan:</td>
                                    <td style="font-weight:700;color:#1e293b;" id="qvProductName">-</td>
                                </tr>
                                <tr>
                                    <td style="color:#64748b;font-weight:600;">Domain:</td>
                                    <td><a id="qvDomainLink" href="#" target="_blank" style="font-weight:700;color:#1d4ed8;">-</a></td>
                                </tr>
                                <tr>
                                    <td style="color:#64748b;font-weight:600;">Server Node:</td>
                                    <td id="qvServerName">-</td>
                                </tr>
                                <tr>
                                    <td style="color:#64748b;font-weight:600;">Status:</td>
                                    <td id="qvStatusBadge">-</td>
                                </tr>
                            </table>
                        </div>

                        <!-- Due Notes History Snippet -->
                        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">
                            <h5 style="font-weight:800;color:#0f5ea8;margin-top:0;margin-bottom:12px;border-bottom:1px solid #f1f5f9;padding-bottom:8px;display:flex;justify-content:space-between;align-items:center;">
                                <span><i class="fa-solid fa-book-bookmark"></i> Recent Notes &amp; Remarks</span>
                                <button type="button" class="btn btn-primary btn-xs" id="qvBtnAddNote"><i class="fa-solid fa-plus"></i> Add Note</button>
                            </h5>
                            <div id="qvNotesList" style="max-height:140px;overflow-y:auto;font-size:12px;">
                                <p class="text-muted">No notes recorded.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="background:#f1f5f9;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <a id="qvBtnClientSummary" href="#" target="_blank" class="btn btn-default btn-sm">
                        <i class="fa-solid fa-user"></i> Client Profile
                    </a>
                    <a id="qvBtnLoginClient" href="#" target="_blank" class="btn btn-default btn-sm">
                        <i class="fa-solid fa-right-to-bracket"></i> Login as Client
                    </a>
                    <a id="qvBtnManageService" href="#" target="_blank" class="btn btn-primary btn-sm">
                        <i class="fa-solid fa-external-link-alt"></i> Manage Service in WHMCS
                    </a>
                </div>
                <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    var CSM_MODULE_LINK = "<?php echo addslashes($modulelink ?? ($vars['modulelink'] ?? 'addonmodules.php?module=client_services_monitor')); ?>";
    var CSM_AJAX_URL = CSM_MODULE_LINK + "&ajax=1";
    var CSM_NOTE_URL = CSM_MODULE_LINK + "&ajax=save_note";
    var CSM_EDIT_NOTE_URL = CSM_MODULE_LINK + "&ajax=edit_note";
    var CSM_DELETE_NOTE_URL = CSM_MODULE_LINK + "&ajax=delete_note";
    var CSM_GET_NOTES_URL = CSM_MODULE_LINK + "&ajax=get_notes";
</script>
<script src="../modules/addons/client_services_monitor/assets/js/app.js?v=<?php echo time(); ?>"></script>
