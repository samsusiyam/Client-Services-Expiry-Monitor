/**
 * WHMCS Client Services & Expiry Monitor - JavaScript Controller
 * Version: 2.0.0
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

    $(document).ready(function () {
        initEventHandlers();
        csmLoadData();
    });

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

        // Filter dropdown immediate change
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
            }, 300);
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

        // Per Page Change
        $('#csmPerPageSelect').on('change', function () {
            csmState.limit = parseInt($(this).val(), 10) || 50;
            csmState.page = 1;
            csmLoadData();
        });

        // WhatsApp Modal Trigger
        $(document).on('click', '.csm-btn-wa-modal', function (e) {
            e.preventDefault();
            var name = $(this).data('name');
            var phone = $(this).data('phone');
            var waUrl = $(this).data('waurl');

            $('#modalClientInfo').val(name);
            $('#modalPhoneNumber').val(phone);
            $('#modalMessageText').val($(this).data('msg') || '');
            $('#csmBtnSendWhatsApp').data('waurl', waUrl);
            $('#csmQuickContactModal').modal('show');
        });

        // Send WhatsApp Button inside modal
        $('#csmBtnSendWhatsApp').on('click', function () {
            var waUrl = $(this).data('waurl');
            var customText = $('#modalMessageText').val().trim();
            if (waUrl) {
                var baseUrl = waUrl.split('?')[0];
                var finalUrl = baseUrl + '?text=' + encodeURIComponent(customText);
                window.open(finalUrl, '_blank');
            }
            $('#csmQuickContactModal').modal('hide');
        });

        // Copy Phone Number
        $(document).on('click', '.csm-btn-copy', function (e) {
            e.preventDefault();
            var phone = $(this).data('phone');
            if (!phone || phone === 'N/A') return;

            navigator.clipboard.writeText(phone);
            var $btn = $(this);
            var orig = $btn.html();
            $btn.html('<i class="fa-solid fa-check text-success"></i>');
            setTimeout(function () { $btn.html(orig); }, 1500);
        });
    }

    function csmCollectFilters() {
        csmState.product_type = $('#filterProductType').val() || '';
        csmState.product_id = parseInt($('#filterProductId').val(), 10) || 0;
        csmState.billing_cycle = $('#filterBillingCycle').val() || 'Any';
        csmState.status = $('#filterStatus').val() || 'Active';
        csmState.server_id = parseInt($('#filterServer').val(), 10) || 0;
        csmState.payment_method = $('#filterPaymentMethod').val() || 'Any';
        csmState.due_filter = $('#filterDueStatus').val() || '';
        csmState.search = $('#filterKeyword').val() || '';
        csmState.limit = parseInt($('#csmPerPageSelect').val(), 10) || 50;
    }

    window.csmSetDueFilter = function (filterVal) {
        $('#filterDueStatus').val(filterVal);
        csmState.due_filter = filterVal;
        csmState.page = 1;
        csmLoadData();
    };

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
                    updateCounters(response.stats);
                } else {
                    renderError(response.error || 'Failed to load services.');
                }
                if (typeof callback === 'function') callback();
            },
            error: function (xhr, status, error) {
                renderError('Server error: ' + error);
                if (typeof callback === 'function') callback();
            }
        });
    }

    function renderTable(records) {
        var $tbody = $('#csmTableBody');
        $tbody.empty();

        if (!records || records.length === 0) {
            $tbody.html(
                '<tr><td colspan="11" class="text-center" style="padding:40px;color:#64748b;">' +
                '<i class="fa-solid fa-folder-open" style="font-size:32px;margin-bottom:8px;opacity:0.5;display:block;"></i>' +
                '<p>No services found matching the selected filters.</p>' +
                '</td></tr>'
            );
            return;
        }

        var rowsHtml = '';
        $.each(records, function (idx, item) {
            // Due badge
            var dueBadge = '';
            if (item.expiry_status === 'overdue') {
                dueBadge = '<span class="csm-badge csm-badge-overdue">' + Math.abs(item.days_left) + 'd Overdue</span>';
            } else if (item.expiry_status === 'today') {
                dueBadge = '<span class="csm-badge csm-badge-warning">Due Today</span>';
            } else if (item.expiry_status === 'warning') {
                dueBadge = '<span class="csm-badge csm-badge-warning">' + item.days_left + 'd Left</span>';
            } else if (item.days_left !== null) {
                dueBadge = '<small class="text-muted">' + item.days_left + 'd left</small>';
            }

            // WhatsApp / Phone
            var phoneHtml = '<span class="text-muted">N/A</span>';
            if (item.phone && item.phone !== 'N/A') {
                var waBtn = item.wa_url ? '<button class="btn btn-success btn-xs csm-btn-wa-modal" data-name="' + escapeHtml(item.client_name) + '" data-phone="' + escapeHtml(item.phone) + '" data-waurl="' + escapeHtml(item.wa_url) + '" title="WhatsApp Reminder"><i class="fab fa-whatsapp"></i></button>' : '';
                var copyBtn = '<button class="btn btn-default btn-xs csm-btn-copy" data-phone="' + escapeHtml(item.phone) + '" title="Copy"><i class="far fa-copy"></i></button>';
                phoneHtml = '<div style="font-size:12px;font-weight:600;">' + escapeHtml(item.phone) + '</div><div style="margin-top:2px;display:flex;gap:4px;">' + waBtn + copyBtn + '</div>';
            }

            // Grace Suspend Column
            var graceHtml = '';
            if (item.grace_date) {
                graceHtml = '<span class="label label-primary" style="font-size:11px;" title="' + escapeHtml(item.grace_reason || 'Extension granted') + '"><i class="fas fa-clock"></i> ' + escapeHtml(item.grace_date) + '</span> ' +
                    '<button class="btn btn-default btn-xs" onclick="openLiveGraceModal(' + item.id + ', \'' + escapeHtml(addslashes(item.product_name + ' - ' + (item.domain || item.client_name))) + '\', \'' + escapeHtml(item.next_due_date) + '\')" title="Edit Deadline"><i class="fas fa-edit"></i></button>';
            } else {
                graceHtml = '<button class="btn btn-default btn-xs" onclick="openLiveGraceModal(' + item.id + ', \'' + escapeHtml(addslashes(item.product_name + ' - ' + (item.domain || item.client_name))) + '\', \'' + escapeHtml(item.next_due_date) + '\')" title="Set Custom Grace Suspend Date"><i class="fas fa-plus"></i> Grace</button>';
            }

            // Due Note / হিসাব Column
            var noteHtml = '';
            if (item.latest_note) {
                noteHtml = '<span class="label label-info" style="font-size:11px;" title="' + escapeHtml(item.latest_note) + '"><i class="fas fa-note-sticky"></i> ' + escapeHtml(item.latest_note.substring(0, 16)) + '...</span> ' +
                    '<button class="btn btn-default btn-xs" onclick="openNoteModal(\'service\', ' + item.id + ', \'' + escapeHtml(addslashes(item.product_name + ' (#' + item.id + ')')) + '\', ' + item.price + ')" title="View/Add Note"><i class="fas fa-pen"></i></button>';
            } else {
                noteHtml = '<button class="btn btn-default btn-xs" onclick="openNoteModal(\'service\', ' + item.id + ', \'' + escapeHtml(addslashes(item.product_name + ' (#' + item.id + ')')) + '\', ' + item.price + ')" title="Add Due Note / হিসাব"><i class="fas fa-plus"></i> হিসাব</button>';
            }

            // Domain Link
            var domainHtml = item.domain ? '<a href="http://' + escapeHtml(item.domain) + '" target="_blank" style="color:#1d4ed8;font-weight:600;"><i class="fas fa-globe"></i> ' + escapeHtml(item.domain) + '</a>' : '<span class="text-muted">—</span>';

            var statusClass = item.status === 'Active' ? 'active' : (item.status === 'Suspended' ? 'suspended' : 'warning');

            rowsHtml += '<tr>' +
                '<td><strong>#' + item.id + '</strong></td>' +
                '<td><strong>' + escapeHtml(item.product_name) + '</strong>' + (item.server_name ? '<br><small class="text-muted"><i class="fas fa-server"></i> ' + escapeHtml(item.server_name) + '</small>' : '') + '</td>' +
                '<td>' + domainHtml + '</td>' +
                '<td><a href="clientssummary.php?userid=' + item.userid + '" target="_blank" style="font-weight:700;color:#0f5ea8;">' + escapeHtml(item.client_name) + '</a>' + (item.company ? '<br><small class="text-muted">' + escapeHtml(item.company) + '</small>' : '') + '</td>' +
                '<td>' + phoneHtml + '</td>' +
                '<td><strong>' + escapeHtml(item.formatted_price) + '</strong><br><small class="text-muted">' + escapeHtml(item.billing_cycle) + '</small></td>' +
                '<td><strong>' + escapeHtml(item.next_due_date) + '</strong><br>' + dueBadge + '</td>' +
                '<td>' + graceHtml + '</td>' +
                '<td>' + noteHtml + '</td>' +
                '<td><span class="csm-badge csm-badge-' + statusClass + '">' + escapeHtml(item.status) + '</span></td>' +
                '<td class="text-center">' +
                '<a href="clientsservices.php?id=' + item.id + '" target="_blank" class="btn btn-default btn-xs" title="View Service"><i class="fas fa-external-link-alt"></i></a> ' +
                '<a href="dologin.php?userid=' + item.userid + '" target="_blank" class="btn btn-default btn-xs" title="Login as Client"><i class="fas fa-right-to-bracket"></i></a>' +
                '</td>' +
                '</tr>';
        });

        $tbody.html(rowsHtml);
    }

    function renderPagination(data) {
        var total = data.total || 0;
        var page = data.page || 1;
        var limit = data.limit || 50;
        var totalPages = Math.ceil(total / limit);

        $('#csmRecordCountText').html('Showing <strong>' + ((page - 1) * limit + 1) + '</strong> to <strong>' + Math.min(page * limit, total) + '</strong> of <strong>' + total + '</strong> Services');

        if (totalPages <= 1) {
            $('#csmPaginationBottom').empty();
            return;
        }

        var html = '<ul class="pagination pagination-sm" style="margin:0;">';
        if (page > 1) {
            html += '<li><a href="javascript:void(0)" onclick="csmGoToPage(' + (page - 1) + ')">&laquo; Prev</a></li>';
        }

        var startP = Math.max(1, page - 3);
        var endP = Math.min(totalPages, page + 3);
        for (var p = startP; p <= endP; p++) {
            html += '<li class="' + (p === page ? 'active' : '') + '"><a href="javascript:void(0)" onclick="csmGoToPage(' + p + ')">' + p + '</a></li>';
        }

        if (page < totalPages) {
            html += '<li><a href="javascript:void(0)" onclick="csmGoToPage(' + (page + 1) + ')">Next &raquo;</a></li>';
        }
        html += '</ul>';

        $('#csmPaginationBottom').html(html);
    }

    window.csmGoToPage = function (p) {
        csmState.page = p;
        csmLoadData();
        $('html, body').animate({ scrollTop: $('#csmServicesTable').offset().top - 120 }, 200);
    };

    function updateCounters(stats) {
        if (!stats) return;
        $('#statTotalActive').text(stats.total_active || 0);
        $('#statDueToday').text(stats.due_today || 0);
        $('#statDue3Days').text(stats.due_3days || 0);
        $('#statDue7Days').text(stats.due_7days || 0);
        $('#statOverdue').text(stats.overdue || 0);
    }

    function renderError(msg) {
        $('#csmTableBody').html('<tr><td colspan="11" class="text-center text-danger" style="padding:30px;">' + escapeHtml(msg) + '</td></tr>');
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function addslashes(str) {
        return (str + '').replace(/[\\"']/g, '\\$&').replace(/\u0000/g, '\\0');
    }

    // Modal: Note Management
    window.openNoteModal = function (relType, relId, targetName, dueAmount) {
        $('#modalNoteRelType').val(relType);
        $('#modalNoteRelId').val(relId);
        $('#modalNoteTarget').val(targetName);
        $('#modalNoteText').val('');
        $('#modalNotePaid').val('');
        $('#modalNoteDue').val(dueAmount || '');
        $('#modalNotePromised').val('');
        $('#modalNotesHistoryList').html('<p class="text-muted">Loading history...</p>');

        // Fetch previous notes history
        $.ajax({
            url: CSM_GET_NOTES_URL + '&rel_type=' + encodeURIComponent(relType) + '&rel_id=' + encodeURIComponent(relId),
            type: 'GET',
            dataType: 'json',
            success: function (res) {
                if (res && res.success && res.notes && res.notes.length > 0) {
                    var histHtml = '';
                    res.notes.forEach(function (n) {
                        histHtml += '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px;margin-bottom:6px;">' +
                            '<div style="font-weight:700;color:#1e293b;">' + escapeHtml(n.note) + '</div>' +
                            '<div style="font-size:11px;color:#64748b;margin-top:2px;">' +
                            (n.paid_amount ? '<span style="color:#16a34a;">Paid: ' + n.paid_amount + '</span> &bull; ' : '') +
                            (n.due_amount ? '<span style="color:#dc2626;">Due: ' + n.due_amount + '</span> &bull; ' : '') +
                            (n.promised_date ? '<span>Promised: ' + n.promised_date + '</span> &bull; ' : '') +
                            '<span>' + n.created_at + ' (' + escapeHtml(n.admin_name || 'Admin') + ')</span>' +
                            '</div></div>';
                    });
                    $('#modalNotesHistoryList').html(histHtml);
                } else {
                    $('#modalNotesHistoryList').html('<p class="text-muted">No previous notes recorded.</p>');
                }
            }
        });

        $('#csmNoteModal').modal('show');
    };

    window.csmSaveNoteAjax = function () {
        var relType = $('#modalNoteRelType').val();
        var relId = $('#modalNoteRelId').val();
        var note = $('#modalNoteText').val().trim();
        var paid = $('#modalNotePaid').val();
        var due = $('#modalNoteDue').val();
        var promised = $('#modalNotePromised').val();

        if (!note) {
            alert('Please enter a note / remark text.');
            return;
        }

        $('#btnSaveNoteAjax').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

        $.ajax({
            url: CSM_NOTE_URL,
            type: 'POST',
            data: {
                rel_type: relType,
                rel_id: relId,
                note: note,
                paid_amount: paid,
                due_amount: due,
                promised_date: promised
            },
            dataType: 'json',
            success: function (res) {
                $('#btnSaveNoteAjax').prop('disabled', false).html('<i class="fas fa-plus"></i> Save Note / Record Entry');
                if (res && res.success) {
                    $('#csmNoteModal').modal('hide');
                    csmLoadData();
                } else {
                    alert('Error: ' + (res.error || 'Failed to save note.'));
                }
            },
            error: function () {
                $('#btnSaveNoteAjax').prop('disabled', false).html('<i class="fas fa-plus"></i> Save Note / Record Entry');
                alert('Network error while saving note.');
            }
        });
    };

    // Modal: Live Grace Period
    window.openLiveGraceModal = function (serviceId, targetName, currentDueDate) {
        $('#liveGraceServiceId').val(serviceId);
        $('#liveGraceTarget').val(targetName + ' (WHMCS Due: ' + currentDueDate + ')');
        $('#liveGraceDate').val('');
        $('#liveGraceReason').val('');
        $('#csmLiveGraceModal').modal('show');
    };

    window.csmSaveGraceAjax = function () {
        var serviceId = $('#liveGraceServiceId').val();
        var graceDate = $('#liveGraceDate').val();
        var reason = $('#liveGraceReason').val().trim();

        if (!graceDate) {
            alert('Please select a custom grace suspend date.');
            return;
        }

        $.ajax({
            url: '?module=client_services_monitor&action=save_grace_suspend',
            type: 'POST',
            data: {
                service_id: serviceId,
                grace_suspend_date: graceDate,
                reason: reason
            },
            success: function () {
                $('#csmLiveGraceModal').modal('hide');
                csmLoadData();
            },
            error: function () {
                alert('Error saving suspension grace deadline.');
            }
        });
    };

})(jQuery);
