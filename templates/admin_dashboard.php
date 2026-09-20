<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
?>

<!-- FontAwesome & Custom CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../modules/addons/client_services_monitor/assets/css/style.css?v=<?php echo time(); ?>">

<div class="csm-container">
    <!-- Header / Title -->
    <div class="csm-header">
        <div class="csm-title-group">
            <h2 class="csm-title">
                <i class="fa-solid fa-server text-primary"></i> Client Services & Expiry Monitor
                <span class="csm-live-badge"><span class="csm-pulse-dot"></span> LIVE Realtime</span>
            </h2>
            <p class="csm-subtitle">সম্পূর্ণ পেজ রিলোড ছাড়া লাইভ ফিল্টারিং, অটো-সর্টিং ও ইনস্ট্যান্ট আপডেট ড্যাশবোর্ড</p>
        </div>
        <div class="csm-header-actions">
            <div class="csm-auto-refresh">
                <label for="csmAutoRefreshSelect"><i class="fa-solid fa-arrows-rotate"></i> Auto Refresh:</label>
                <select id="csmAutoRefreshSelect" class="form-control input-sm">
                    <option value="15">Every 15s (Ultra Live)</option>
                    <option value="30" selected>Every 30s</option>
                    <option value="60">Every 60s</option>
                    <option value="120">Every 2m</option>
                    <option value="0">Manual Only</option>
                </select>
            </div>
            <button id="csmBtnManualRefresh" class="btn btn-default btn-sm" title="Instant Refresh Data">
                <i class="fa-solid fa-sync" id="csmRefreshIcon"></i> Refresh Now
            </button>
        </div>
    </div>

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

    <!-- Search & Filter Card (Styled like WHMCS native Filter) -->
    <div class="panel panel-default csm-filter-panel">
        <div class="panel-heading">
            <h3 class="panel-title"><i class="fa-solid fa-filter"></i> Search / Filter</h3>
        </div>
        <div class="panel-body">
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
                                    <option value="Active" <?php echo ($config_default_status === 'Active') ? 'selected' : ''; ?>>Active (Default)</option>
                                    <option value="Active,Suspended" <?php echo ($config_default_status === 'Active,Suspended') ? 'selected' : ''; ?>>Active & Suspended</option>
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

                <div class="row csm-filter-buttons">
                    <div class="col-xs-12 text-center">
                        <button type="button" id="csmBtnSearch" class="btn btn-primary">
                            <i class="fa-solid fa-search"></i> Search / Apply Filter
                        </button>
                        <button type="button" id="csmBtnReset" class="btn btn-default">
                            <i class="fa-solid fa-rotate-left"></i> Reset
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Table Meta Bar (Showing records count & Pagination options) -->
    <div class="csm-table-meta">
        <div class="csm-record-count">
            <span id="csmRecordCountText">Loading records...</span>
        </div>
        <div class="csm-meta-controls">
            <label>Per Page:
                <select id="csmPerPageSelect" class="form-control input-sm">
                    <option value="25" <?php echo $config_per_page == 25 ? 'selected' : ''; ?>>25</option>
                    <option value="50" <?php echo $config_per_page == 50 ? 'selected' : ''; ?>>50</option>
                    <option value="100" <?php echo $config_per_page == 100 ? 'selected' : ''; ?>>100</option>
                    <option value="250" <?php echo $config_per_page == 250 ? 'selected' : ''; ?>>250</option>
                </select>
            </label>
            <div class="csm-pagination-top" id="csmPaginationTop"></div>
        </div>
    </div>

    <!-- Main Results Table -->
    <div class="table-responsive csm-table-wrapper">
        <table class="table table-striped table-hover csm-table" id="csmServicesTable">
            <thead>
                <tr>
                    <th width="40" class="text-center"><input type="checkbox" id="csmSelectAll"></th>
                    <th width="80">ID <i class="fa-solid fa-sort-down"></i></th>
                    <th>Product / Service</th>
                    <th>Domain</th>
                    <th>Client Name</th>
                    <th>Client Phone / WhatsApp</th>
                    <th>Price</th>
                    <th>Billing Cycle</th>
                    <th>Next Due Date <i class="fa-solid fa-arrow-up-wide-short text-primary" title="Sorted: Expiring Soonest First"></i></th>
                    <th>Status</th>
                    <th width="80" class="text-center">Action</th>
                </tr>
            </thead>
            <tbody id="csmTableBody">
                <tr>
                    <td colspan="11" class="text-center csm-loading-state">
                        <div class="csm-spinner">
                            <i class="fa-solid fa-spinner fa-spin fa-2x text-primary"></i>
                            <p>Loading services data...</p>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Bottom Pagination -->
    <div class="csm-pagination-bottom-wrapper">
        <div id="csmPaginationBottom"></div>
    </div>
</div>

<!-- Modal for Quick Client Contact or Notes (Optional Enhancement) -->
<div class="modal fade" id="csmQuickContactModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title"><i class="fa-brands fa-whatsapp text-success"></i> Send WhatsApp Message</h4>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Recipient:</label>
                    <input type="text" id="modalClientInfo" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label>Phone Number:</label>
                    <input type="text" id="modalPhoneNumber" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label>Message Template:</label>
                    <select id="modalTemplateSelect" class="form-control" onchange="csmApplyWhatsAppTemplate()">
                        <option value="custom">Custom Message</option>
                        <option value="due_reminder">Renewal Reminder (মেয়াদ শেষ হওয়ার নোটিশ)</option>
                        <option value="overdue_notice">Service Overdue Notice (বিল বকেয়া নোটিশ)</option>
                        <option value="welcome">Service Active Confirmation</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Message Text:</label>
                    <textarea id="modalMessageText" class="form-control" rows="4" placeholder="Type your WhatsApp message here..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="csmBtnSendWhatsApp">
                    <i class="fa-brands fa-whatsapp"></i> Open in WhatsApp
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    var CSM_AJAX_URL = "<?php echo $modulelink; ?>&ajax=1";
    var CSM_WARNING_DAYS = <?php echo $config_highlight_days; ?>;
    var CSM_COUNTRY_CODE = "<?php echo $config_country_code; ?>";
</script>
<script src="../modules/addons/client_services_monitor/assets/js/app.js?v=<?php echo time(); ?>"></script>
