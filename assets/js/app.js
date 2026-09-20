/**
 * WHMCS Client Services & Expiry Monitor - JavaScript Controller
 */

(function ($) {
    'use strict';

    var csmState = {
        page: 1,
        limit: 50,
        product_type: '',
        product_id: 0,
        billing_cycle: 'Any',
        status: 'Active',
        server_id: 0,
        payment_method: 'Any',
        due_filter: '',
        search: '',
        autoRefreshTimer: null,
        searchDebounceTimer: null
    };

    // Initialize on document ready
    $(document).ready(function () {
        initEventHandlers();
        csmLoadData();
        setupAutoRefresh();
    });

    /**
     * Bind all DOM Event Handlers
     */
    function initEventHandlers() {
        // Search Button
        $('#csmBtnSearch').on('click', function () {
            csmState.page = 1;
            csmCollectFilters();
            csmLoadData();
        });

        // Reset Button
        $('#csmBtnReset').on('click', function () {
            $('#csmFilterForm')[0].reset();
            $('#filterStatus').val('Active');
            $('#filterDueStatus').val('');
            $('#filterProductType').val('');
            $('#filterProductId').val('0');
            $('#filterBillingCycle').val('Any');
            $('#filterServer').val('0');
            $('#filterPaymentMethod').val('Any');
            $('#filterKeyword').val('');

            csmState.page = 1;
            csmState.due_filter = '';
            csmCollectFilters();
            csmLoadData();
        });

        // Filter dropdown immediate change trigger
        $('#filterProductType, #filterProductId, #filterBillingCycle, #filterDueStatus, #filterServer, #filterPaymentMethod, #filterStatus').on('change', function () {
            csmState.page = 1;
            csmCollectFilters();
            csmLoadData();
        });

        // Live Search Input with Debounce
        $('#filterKeyword').on('input', function () {
            clearTimeout(csmState.searchDebounceTimer);
            csmState.searchDebounceTimer = setTimeout(function () {
                csmState.page = 1;
                csmCollectFilters();
                csmLoadData();
            }, 350);
        });

        // Clear Keyword Button
        $('#csmBtnClearKeyword').on('click', function () {
            $('#filterKeyword').val('');
            csmState.page = 1;
            csmCollectFilters();
            csmLoadData();
        });

        // Manual Refresh Button
        $('#csmBtnManualRefresh').on('click', function () {
            var $icon = $('#csmRefreshIcon');
            $icon.addClass('fa-spin');
            csmLoadData(function () {
                $icon.removeClass('fa-spin');
            });
        });

        // Records Per Page Change
        $('#csmPerPageSelect').on('change', function () {
            csmState.limit = parseInt($(this).val(), 10) || 50;
            csmState.page = 1;
            csmLoadData();
        });

        // Auto-refresh select change
        $('#csmAutoRefreshSelect').on('change', function () {
            setupAutoRefresh();
        });

        // Select All Checkbox
        $('#csmSelectAll').on('change', function () {
            var isChecked = $(this).is(':checked');
            $('.csm-row-checkbox').prop('checked', isChecked);
        });

        // Copy Phone Number
        $(document).on('click', '.csm-btn-copy', function (e) {
            e.preventDefault();
            var phone = $(this).data('phone');
            if (!phone || phone === 'N/A') return;

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(phone);
            } else {
                var tempInput = document.createElement('input');
                tempInput.value = phone;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);
            }

            var $btn = $(this);
            var originalHtml = $btn.html();
            $btn.html('<i class="fa-solid fa-check text-success"></i>');
            setTimeout(function () {
                $btn.html(originalHtml);
            }, 1500);
        });

        // WhatsApp Modal Trigger
        $(document).on('click', '.csm-btn-wa-modal', function (e) {
            e.preventDefault();
            var name = $(this).data('name');
            var phone = $(this).data('phone');
            var product = $(this).data('product');
            var duedate = $(this).data('duedate');
            var waUrl = $(this).data('waurl');

            $('#modalClientInfo').val(name + ' (' + product + ')');
            $('#modalPhoneNumber').val(phone);
            $('#csmBtnSendWhatsApp').data('waurl', waUrl);
            $('#csmBtnSendWhatsApp').data('name', name);
            $('#csmBtnSendWhatsApp').data('product', product);
            $('#csmBtnSendWhatsApp').data('duedate', duedate);

            csmApplyWhatsAppTemplate();
            $('#csmQuickContactModal').modal('show');
        });

        // WhatsApp Modal Send Click
        $('#csmBtnSendWhatsApp').on('click', function () {
            var baseUrl = $(this).data('waurl');
            var message = $('#modalMessageText').val();
            if (baseUrl) {
                var finalUrl = baseUrl + (baseUrl.indexOf('?') > -1 ? '&' : '?') + 'text=' + encodeURIComponent(message);
                window.open(finalUrl, '_blank');
                $('#csmQuickContactModal').modal('hide');
            }
        });
    }

    /**
     * Collect all filter inputs into state
     */
    function csmCollectFilters() {
        csmState.product_type = $('#filterProductType').val();
        csmState.product_id = $('#filterProductId').val();
        csmState.billing_cycle = $('#filterBillingCycle').val();
        csmState.status = $('#filterStatus').val();
        csmState.server_id = $('#filterServer').val();
        csmState.payment_method = $('#filterPaymentMethod').val();
        csmState.due_filter = $('#filterDueStatus').val();
        csmState.search = $('#filterKeyword').val();
        csmState.limit = parseInt($('#csmPerPageSelect').val(), 10) || 50;
    }

    /**
     * Setup Auto-Refresh Interval
     */
    function setupAutoRefresh() {
        if (csmState.autoRefreshTimer) {
            clearInterval(csmState.autoRefreshTimer);
            csmState.autoRefreshTimer = null;
        }

        var intervalSecs = parseInt($('#csmAutoRefreshSelect').val(), 10) || 0;
        if (intervalSecs > 0) {
            csmState.autoRefreshTimer = setInterval(function () {
                csmLoadData();
            }, intervalSecs * 1000);
        }
    }

    /**
     * Quick filter by Due Card click
     */
    window.csmSetDueFilter = function (filterVal) {
        $('#filterDueStatus').val(filterVal);
        csmState.due_filter = filterVal;
        csmState.page = 1;
        csmLoadData();
    };

    /**
     * WhatsApp Template Generator
     */
    window.csmApplyWhatsAppTemplate = function () {
        var template = $('#modalTemplateSelect').val();
        var name = $('#csmBtnSendWhatsApp').data('name') || 'Client';
        var product = $('#csmBtnSendWhatsApp').data('product') || 'Service';
        var duedate = $('#csmBtnSendWhatsApp').data('duedate') || '';

        var msg = '';
        if (template === 'due_reminder') {
            msg = 'Dear ' + name + ', your service ' + product + ' is scheduled for renewal on ' + duedate + '. Please renew your service to ensure uninterrupted operation. Thank you - Bahari IT';
        } else if (template === 'overdue_notice') {
            msg = 'Dear ' + name + ', your service ' + product + ' expired on ' + duedate + ' and is currently overdue. Please pay your pending invoice promptly to avoid suspension. Thank you - Bahari IT';
        } else if (template === 'welcome') {
            msg = 'Dear ' + name + ', your service ' + product + ' is active and running smoothly. Please feel free to reach out if you need any assistance. Thank you - Bahari IT';
        }

        $('#modalMessageText').val(msg);
    };

    /**
     * Load Data via AJAX
     */
    function csmLoadData(callback) {
        var params = {
            product_type: csmState.product_type,
            product_id: csmState.product_id,
            billing_cycle: csmState.billing_cycle,
            status: csmState.status,
            server_id: csmState.server_id,
            payment_method: csmState.payment_method,
            due_filter: csmState.due_filter,
            search: csmState.search,
            page: csmState.page,
            limit: csmState.limit
        };

        $.ajax({
            url: CSM_AJAX_URL,
            type: 'GET',
            data: params,
            dataType: 'json',
            success: function (response) {
                if (response && response.success) {
                    renderTable(response.records);
                    renderPagination(response);
                    updateCounters(response);
                } else {
                    renderError(response.error || 'Failed to load records.');
                }
                if (typeof callback === 'function') callback();
            },
            error: function (xhr, status, error) {
                renderError('Server error: ' + error);
                if (typeof callback === 'function') callback();
            }
        });
    }

    /**
     * Render Table Rows
     */
    function renderTable(records) {
        var $tbody = $('#csmTableBody');
        $tbody.empty();
        $('#csmSelectAll').prop('checked', false);

        if (!records || records.length === 0) {
            $tbody.html(
                '<tr><td colspan="11" class="text-center csm-empty-state">' +
                '<i class="fa-solid fa-folder-open"></i>' +
                '<p>No services or records found matching your filters.</p>' +
                '</td></tr>'
            );
            return;
        }

        var rowsHtml = '';
        $.each(records, function (index, item) {
            var rowClass = (item.days_left !== null && item.days_left <= 0) ? 'row-overdue' : '';

            // Sub meta tags (Order, Reg Date, Server, IP, Username)
            var subMeta = '<div class="csm-sub-meta">';
            if (item.orderid) {
                subMeta += '<span class="csm-meta-tag"><strong>Order:</strong> #' + escapeHtml(item.orderid) + '</span>';
            }
            if (item.regdate && item.regdate !== '-') {
                subMeta += '<span class="csm-meta-tag"><i class="fa-regular fa-calendar"></i> ' + escapeHtml(item.regdate) + '</span>';
            }
            if (item.server_name) {
                subMeta += '<span class="csm-meta-tag"><i class="fa-solid fa-server"></i> ' + escapeHtml(item.server_name) + '</span>';
            }
            if (item.dedicatedip) {
                subMeta += '<span class="csm-meta-tag"><i class="fa-solid fa-network-wired"></i> ' + escapeHtml(item.dedicatedip) + '</span>';
            }
            if (item.username) {
                subMeta += '<span class="csm-meta-tag"><i class="fa-regular fa-user"></i> ' + escapeHtml(item.username) + '</span>';
            }
            subMeta += '</div>';

            // Type pill badge
            var typeClass = 'type-other';
            if (item.product_type === 'server') typeClass = 'type-server';
            else if (item.product_type === 'hostingaccount') typeClass = 'type-hosting';
            else if (item.product_type === 'reselleraccount') typeClass = 'type-reseller';
            else if (item.record_type === 'domain') typeClass = 'type-domain';
            var typePill = '<span class="csm-type-pill ' + typeClass + '">' + escapeHtml(item.type_label) + '</span>';

            // Domain link
            var domainHtml = '<span class="text-muted">-</span>';
            if (item.domain) {
                var cleanDom = escapeHtml(item.domain);
                domainHtml = '<a href="http://' + cleanDom + '" target="_blank" class="csm-domain-link">' +
                    '<i class="fa-solid fa-globe text-muted"></i> ' + cleanDom +
                    '</a>' +
                    '<a href="http://www.' + cleanDom + '" target="_blank" class="csm-www-btn" title="Open with www">WWW</a>';
            }

            // Contact & WhatsApp button (Clean formatted phone without dots)
            var phoneHtml = '<span class="text-muted">N/A</span>';
            if (item.phonenumber && item.phonenumber !== 'N/A') {
                var cleanDisplayPhone = escapeHtml(item.phonenumber);
                var waBtn = '';
                if (item.whatsapp_url) {
                    waBtn = '<button type="button" class="csm-btn-wa csm-btn-wa-modal" ' +
                        'data-name="' + escapeHtml(item.client_name) + '" ' +
                        'data-phone="' + cleanDisplayPhone + '" ' +
                        'data-product="' + escapeHtml(item.product_name) + '" ' +
                        'data-duedate="' + escapeHtml(item.nextduedate) + '" ' +
                        'data-waurl="' + escapeHtml(item.whatsapp_url) + '" title="Chat on WhatsApp">' +
                        '<i class="fa-brands fa-whatsapp"></i> WhatsApp' +
                        '</button>';
                }

                var callBtn = item.dial_url ? '<a href="' + escapeHtml(item.dial_url) + '" class="csm-btn-dial" title="Direct Phone Call"><i class="fa-solid fa-phone"></i> Call</a>' : '';
                var copyBtn = '<button type="button" class="csm-btn-copy-num csm-btn-copy" data-phone="' + cleanDisplayPhone + '" title="Copy Number"><i class="fa-regular fa-copy"></i></button>';

                phoneHtml = '<div class="csm-phone-box">' +
                    '<span class="csm-phone-badge"><i class="fa-solid fa-phone-volume text-primary"></i> ' + cleanDisplayPhone + '</span>' +
                    '<div class="csm-contact-actions">' + waBtn + callBtn + copyBtn + '</div>' +
                    '</div>';
            }

            // Price & Payment
            var priceHtml = '<div class="csm-price-box">' + escapeHtml(item.price_formatted) + '</div>' +
                '<div class="csm-sub-meta"><span class="csm-meta-tag">' + escapeHtml(item.paymentmethod) + '</span></div>';

            // Due Date & Modern Pill Badge
            var pillClass = 'pill-normal';
            var pillIcon = '<i class="fa-regular fa-calendar-check"></i>';
            if (item.days_left !== null) {
                if (item.days_left < 0) {
                    pillClass = 'pill-overdue';
                    pillIcon = '<i class="fa-solid fa-circle-exclamation"></i>';
                } else if (item.days_left === 0) {
                    pillClass = 'pill-today';
                    pillIcon = '<i class="fa-solid fa-bolt"></i>';
                } else if (item.days_left <= CSM_WARNING_DAYS) {
                    pillClass = 'pill-warning';
                    pillIcon = '<i class="fa-solid fa-hourglass-half"></i>';
                }
            }

            var dueHtml = '<div class="csm-due-box">' +
                '<span class="csm-due-date"><i class="fa-regular fa-calendar text-muted"></i> ' + escapeHtml(item.nextduedate) + '</span>' +
                '<span class="csm-pill-badge ' + pillClass + '">' + pillIcon + ' ' + escapeHtml(item.due_badge_text) + '</span>' +
                '</div>';

            // Status Pill
            var statusLower = item.status.toLowerCase();
            var statusClass = 'status-' + statusLower;
            var statusHtml = '<span class="status-pill ' + statusClass + '">' + escapeHtml(item.status) + '</span>';

            // Action Links
            var actionHtml = '<a href="' + escapeHtml(item.service_url) + '" class="btn btn-default btn-sm" title="Manage Service / Product" target="_blank">' +
                '<i class="fa-solid fa-arrow-up-right-from-square text-primary"></i>' +
                '</a>';

            rowsHtml += '<tr class="' + rowClass + '">' +
                '<td class="text-center"><input type="checkbox" class="csm-row-checkbox" value="' + item.id + '"></td>' +
                '<td><a href="' + escapeHtml(item.service_url) + '" class="csm-item-title font-weight-bold" target="_blank">#' + item.id + '</a></td>' +
                '<td>' +
                    '<div><a href="' + escapeHtml(item.service_url) + '" class="csm-item-title" target="_blank">' + escapeHtml(item.product_name) + '</a>' + typePill + '</div>' +
                    subMeta +
                '</td>' +
                '<td>' + domainHtml + '</td>' +
                '<td>' +
                    '<a href="' + escapeHtml(item.client_url) + '" class="csm-client-link" target="_blank"><i class="fa-regular fa-user text-muted"></i> ' + escapeHtml(item.client_name) + '</a>' +
                    (item.company_name ? '<div class="csm-sub-meta">' + escapeHtml(item.company_name) + '</div>' : '') +
                '</td>' +
                '<td>' + phoneHtml + '</td>' +
                '<td>' + priceHtml + '</td>' +
                '<td><span class="csm-meta-tag font-weight-bold">' + escapeHtml(item.billingcycle) + '</span></td>' +
                '<td>' + dueHtml + '</td>' +
                '<td>' + statusHtml + '</td>' +
                '<td class="text-center">' + actionHtml + '</td>' +
                '</tr>';
        });

        $tbody.html(rowsHtml);
    }

    /**
     * Render Pagination Controls
     */
    function renderPagination(data) {
        var countText = 'Showing ' + data.showing_from + ' to ' + data.showing_to + ' of ' + data.total + ' Records Found';
        $('#csmRecordCountText').text(countText);

        var paginationHtml = '';
        if (data.total_pages > 1) {
            paginationHtml = '<ul class="csm-pagination-list">';

            // Prev Button
            if (data.page > 1) {
                paginationHtml += '<li><a href="javascript:void(0);" onclick="csmGoToPage(' + (data.page - 1) + ')">&laquo; Prev</a></li>';
            } else {
                paginationHtml += '<li class="disabled"><span>&laquo; Prev</span></li>';
            }

            // Page numbers
            var startPage = Math.max(1, data.page - 2);
            var endPage = Math.min(data.total_pages, data.page + 2);

            if (startPage > 1) {
                paginationHtml += '<li><a href="javascript:void(0);" onclick="csmGoToPage(1)">1</a></li>';
                if (startPage > 2) paginationHtml += '<li class="disabled"><span>...</span></li>';
            }

            for (var p = startPage; p <= endPage; p++) {
                if (p === data.page) {
                    paginationHtml += '<li class="active"><span>' + p + '</span></li>';
                } else {
                    paginationHtml += '<li><a href="javascript:void(0);" onclick="csmGoToPage(' + p + ')">' + p + '</a></li>';
                }
            }

            if (endPage < data.total_pages) {
                if (endPage < data.total_pages - 1) paginationHtml += '<li class="disabled"><span>...</span></li>';
                paginationHtml += '<li><a href="javascript:void(0);" onclick="csmGoToPage(' + data.total_pages + ')">' + data.total_pages + '</a></li>';
            }

            // Next Button
            if (data.page < data.total_pages) {
                paginationHtml += '<li><a href="javascript:void(0);" onclick="csmGoToPage(' + (data.page + 1) + ')">Next &raquo;</a></li>';
            } else {
                paginationHtml += '<li class="disabled"><span>Next &raquo;</span></li>';
            }

            paginationHtml += '</ul>';
        }

        $('#csmPaginationTop').html(paginationHtml);
        $('#csmPaginationBottom').html(paginationHtml);
    }

    /**
     * Jump to page
     */
    window.csmGoToPage = function (page) {
        csmState.page = page;
        csmLoadData();
        $('html, body').animate({ scrollTop: $('#csmServicesTable').offset().top - 100 }, 200);
    };

    /**
     * Update metric counters
     */
    function updateCounters(data) {
        // Calculate quick metric badges from records
        var todayCount = 0;
        var due3Count = 0;
        var due7Count = 0;
        var overdueCount = 0;

        if (data.records) {
            $.each(data.records, function (i, r) {
                if (r.days_left !== null) {
                    if (r.days_left < 0) overdueCount++;
                    else if (r.days_left === 0) todayCount++;
                    else if (r.days_left <= 3) due3Count++;
                    else if (r.days_left <= 7) due7Count++;
                }
            });
        }

        $('#statTotalActive').text(data.total);
        if (data.due_filter === 'today') $('#statDueToday').text(data.total);
        else if (data.due_filter === '3days') $('#statDue3Days').text(data.total);
        else if (data.due_filter === '7days') $('#statDue7Days').text(data.total);
        else if (data.due_filter === 'overdue') $('#statOverdue').text(data.total);
        else {
            $('#statDueToday').text(todayCount + '+');
            $('#statDue3Days').text(due3Count + '+');
            $('#statDue7Days').text(due7Count + '+');
            $('#statOverdue').text(overdueCount + '+');
        }
    }

    /**
     * Render Error in table
     */
    function renderError(msg) {
        $('#csmTableBody').html(
            '<tr><td colspan="11" class="text-center text-danger csm-loading-state">' +
            '<i class="fa-solid fa-triangle-exclamation fa-2x"></i>' +
            '<p class="mt-2">' + escapeHtml(msg) + '</p>' +
            '</td></tr>'
        );
    }

    /**
     * HTML entity escaper
     */
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

})(jQuery);
