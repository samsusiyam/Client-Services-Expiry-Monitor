/**
 * WHMCS Client Services & Expiry Monitor - JavaScript Controller
 * Version: 2.1.0
 */

(function ($) {
    'use strict';

    var csmCurrentRecords = [];

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

    // Global SweetAlert2 notification helpers
    window.csmToast = function (icon, title) {
        if (typeof Swal !== 'undefined') {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });
            Toast.fire({ icon: icon || 'success', title: title });
        } else {
            alert(title);
        }
    };

    window.csmAlert = function (icon, title, text) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: icon || 'info',
                title: title || 'Notice',
                text: text || '',
                confirmButtonColor: '#12589b'
            });
        } else {
            alert((title ? title + ': ' : '') + (text || ''));
        }
    };

    window.csmConfirmAction = function (title, text, confirmBtnText, onConfirm) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: title || 'Are you sure?',
                text: text || "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#64748b',
                confirmButtonText: confirmBtnText || 'Yes, proceed'
            }).then(function (result) {
                if (result.isConfirmed && typeof onConfirm === 'function') {
                    onConfirm();
                }
            });
        } else {
            if (confirm(text || 'Are you sure you want to proceed?')) {
                if (typeof onConfirm === 'function') onConfirm();
            }
        }
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
            csmToast('success', 'Phone number copied: ' + phone);
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
                    csmCurrentRecords = response.records || [];
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

            // Direct Call + WhatsApp + Copy Phone Column
            var phoneHtml = '<span class="text-muted">N/A</span>';
            if (item.phone && item.phone !== 'N/A') {
                var dialNum = item.dial_phone || item.phone.replace(/[^0-9+]/g, '');
                var callBtn = dialNum ? '<a href="tel:' + escapeHtml(dialNum) + '" class="btn btn-primary btn-xs" title="Direct Call ' + escapeHtml(dialNum) + '" style="background:#0284c7;border-color:#0284c7;color:#fff;"><i class="fas fa-phone"></i></a>' : '';
                var waBtn = item.wa_url ? '<button class="btn btn-success btn-xs csm-btn-wa-modal" data-name="' + escapeHtml(item.client_name) + '" data-phone="' + escapeHtml(item.phone) + '" data-waurl="' + escapeHtml(item.wa_url) + '" title="WhatsApp Reminder"><i class="fab fa-whatsapp"></i></button>' : '';
                var copyBtn = '<button class="btn btn-default btn-xs csm-btn-copy" data-phone="' + escapeHtml(item.phone) + '" title="Copy"><i class="far fa-copy"></i></button>';
                
                phoneHtml = '<div style="font-size:12px;font-weight:700;color:#1e293b;">' + escapeHtml(item.phone) + '</div><div style="margin-top:3px;display:flex;gap:4px;">' + callBtn + waBtn + copyBtn + '</div>';
            }

            // Grace Suspend Column
            var graceHtml = '';
            if (item.grace_date) {
                graceHtml = '<span class="label label-primary" style="font-size:11px;" title="' + escapeHtml(item.grace_reason || 'Extension granted') + '"><i class="fas fa-clock"></i> ' + escapeHtml(item.grace_date) + '</span> ' +
                    '<button class="btn btn-default btn-xs" onclick="openLiveGraceModal(' + item.id + ', \'' + escapeHtml(addslashes(item.product_name + ' - ' + (item.domain || item.client_name))) + '\', \'' + escapeHtml(item.next_due_date) + '\')" title="Edit Deadline"><i class="fas fa-edit"></i></button>';
            } else {
                graceHtml = '<button class="btn btn-default btn-xs" onclick="openLiveGraceModal(' + item.id + ', \'' + escapeHtml(addslashes(item.product_name + ' - ' + (item.domain || item.client_name))) + '\', \'' + escapeHtml(item.next_due_date) + '\')" title="Set Custom Grace Suspend Date"><i class="fas fa-plus"></i> Grace</button>';
            }

            // Due Note / Remarks Column
            var noteHtml = '';
            if (item.latest_note) {
                noteHtml = '<span class="label label-info" style="font-size:11px;" title="' + escapeHtml(item.latest_note) + '"><i class="fas fa-note-sticky"></i> ' + escapeHtml(item.latest_note.substring(0, 16)) + '...</span> ' +
                    '<button class="btn btn-default btn-xs" onclick="openNoteModal(\'service\', ' + item.id + ', \'' + escapeHtml(addslashes(item.product_name + ' (#' + item.id + ')')) + '\', ' + item.price + ')" title="View/Add Note"><i class="fas fa-pen"></i></button>';
            } else {
                noteHtml = '<button class="btn btn-default btn-xs" onclick="openNoteModal(\'service\', ' + item.id + ', \'' + escapeHtml(addslashes(item.product_name + ' (#' + item.id + ')')) + '\', ' + item.price + ')" title="Add Due Note / Remarks"><i class="fas fa-plus"></i> Note</button>';
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
                '<td class="text-center" style="white-space:nowrap;">' +
                '<button type="button" class="btn btn-info btn-xs" onclick="csmQuickView(' + idx + ')" title="Quick View Specs & Details"><i class="fas fa-eye"></i></button> ' +
                '<a href="clientsservices.php?id=' + item.id + '" target="_blank" class="btn btn-default btn-xs" title="View Service in WHMCS"><i class="fas fa-external-link-alt"></i></a> ' +
                '<a href="dologin.php?userid=' + item.userid + '" target="_blank" class="btn btn-default btn-xs" title="Login as Client"><i class="fas fa-right-to-bracket"></i></a>' +
                '</td>' +
                '</tr>';
        });

        $tbody.html(rowsHtml);
    }

    // Quick View Modal
    window.csmQuickView = function (idx) {
        var item = csmCurrentRecords[idx];
        if (!item) return;

        $('#qvTitle').text(item.product_name + ' (#' + item.id + ')');
        $('#qvClientName').text(item.client_name);
        $('#qvCompany').text(item.company || 'Individual / None');
        $('#qvEmailLink').attr('href', 'mailto:' + item.email).text(item.email);
        
        var phoneClean = item.phone || 'N/A';
        $('#qvPhoneText').text(phoneClean);
        
        var phoneBtnsHtml = '';
        if (item.phone && item.phone !== 'N/A') {
            var dialNum = item.dial_phone || item.phone.replace(/[^0-9+]/g, '');
            if (dialNum) {
                phoneBtnsHtml += '<a href="tel:' + escapeHtml(dialNum) + '" class="btn btn-primary btn-xs" title="Call"><i class="fas fa-phone"></i> Call</a> ';
            }
            if (item.wa_url) {
                phoneBtnsHtml += '<a href="' + escapeHtml(item.wa_url) + '" target="_blank" class="btn btn-success btn-xs" title="WhatsApp"><i class="fab fa-whatsapp"></i> WhatsApp</a> ';
            }
            phoneBtnsHtml += '<button class="btn btn-default btn-xs csm-btn-copy" data-phone="' + escapeHtml(item.phone) + '" title="Copy"><i class="far fa-copy"></i></button>';
        }
        $('#qvPhoneButtons').html(phoneBtnsHtml);

        $('#qvPrice').text(item.formatted_price || '৳ 0.00');
        $('#qvBillingCycle').text(item.billing_cycle || '-');
        $('#qvNextDueDate').text(item.next_due_date || 'N/A');

        var dueBadge = '';
        if (item.expiry_status === 'overdue') {
            dueBadge = '<span class="csm-badge csm-badge-overdue">' + Math.abs(item.days_left) + 'd Overdue</span>';
        } else if (item.expiry_status === 'today') {
            dueBadge = '<span class="csm-badge csm-badge-warning">Due Today</span>';
        } else if (item.expiry_status === 'warning') {
            dueBadge = '<span class="csm-badge csm-badge-warning">' + item.days_left + 'd Left</span>';
        }
        $('#qvDueBadge').html(dueBadge);

        if (item.grace_date) {
            $('#qvGraceSuspend').html('<span class="label label-primary"><i class="fas fa-clock"></i> ' + escapeHtml(item.grace_date) + '</span> <small class="text-muted">(' + escapeHtml(item.grace_reason || 'Extension') + ')</small>');
        } else {
            $('#qvGraceSuspend').html('<span class="text-muted">None (Standard WHMCS)</span>');
        }

        $('#qvServiceId').text('#' + item.id);
        $('#qvProductName').text(item.product_name);
        if (item.domain) {
            $('#qvDomainLink').attr('href', 'http://' + item.domain).text(item.domain).show();
        } else {
            $('#qvDomainLink').text('N/A').attr('href', '#');
        }
        $('#qvServerName').text(item.server_name || 'Standard / None');

        var statusClass = item.status === 'Active' ? 'active' : (item.status === 'Suspended' ? 'suspended' : 'warning');
        $('#qvStatusBadge').html('<span class="csm-badge csm-badge-' + statusClass + '">' + escapeHtml(item.status) + '</span>');

        $('#qvBtnClientSummary').attr('href', 'clientssummary.php?userid=' + item.userid);
        $('#qvBtnLoginClient').attr('href', 'dologin.php?userid=' + item.userid);
        $('#qvBtnManageService').attr('href', 'clientsservices.php?id=' + item.id);

        $('#qvBtnAddNote').off('click').on('click', function () {
            $('#csmQuickViewModal').modal('hide');
            openNoteModal('service', item.id, item.product_name + ' (#' + item.id + ')', item.price);
        });

        // Load Note history in Quick View
        $('#qvNotesList').html('<p class="text-muted"><i class="fas fa-spinner fa-spin"></i> Loading notes...</p>');
        $.ajax({
            url: CSM_GET_NOTES_URL + '&rel_type=service&rel_id=' + item.id,
            type: 'GET',
            dataType: 'json',
            success: function (res) {
                if (res && res.success && res.notes && res.notes.length > 0) {
                    var nHtml = '';
                    res.notes.forEach(function (n) {
                        nHtml += '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:6px 8px;margin-bottom:5px;">' +
                            '<div style="font-weight:700;color:#1e293b;">' + escapeHtml(n.note) + '</div>' +
                            '<div style="font-size:10px;color:#64748b;margin-top:2px;">' +
                            (n.paid_amount ? '<span style="color:#16a34a;font-weight:700;">Paid: ' + n.paid_amount + '</span> &bull; ' : '') +
                            (n.due_amount ? '<span style="color:#dc2626;font-weight:700;">Due: ' + n.due_amount + '</span> &bull; ' : '') +
                            '<span>' + n.created_at + '</span>' +
                            '</div></div>';
                    });
                    $('#qvNotesList').html(nHtml);
                } else {
                    $('#qvNotesList').html('<p class="text-muted" style="margin:0;">No notes recorded yet.</p>');
                }
            },
            error: function () {
                $('#qvNotesList').html('<p class="text-muted" style="margin:0;">No notes available.</p>');
            }
        });

        $('#csmQuickViewModal').modal('show');
    };

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
    var currentEditingNoteId = 0;

    window.openNoteModal = function (relType, relId, targetName, dueAmount) {
        currentEditingNoteId = 0;
        $('#modalNoteRelType').val(relType);
        $('#modalNoteRelId').val(relId);
        $('#modalNoteTarget').val(targetName);
        $('#modalNoteText').val('');
        $('#modalNotePaid').val('');
        $('#modalNoteDue').val(dueAmount || '');
        $('#modalNotePromised').val('');
        $('#btnSaveNoteAjax').html('<i class="fas fa-plus"></i> Save Note / Record Entry');
        $('#modalNotesHistoryList').html('<p class="text-muted"><i class="fas fa-spinner fa-spin"></i> Loading history...</p>');

        loadNotesHistory(relType, relId);
        $('#csmNoteModal').modal('show');
    };

    function loadNotesHistory(relType, relId) {
        $.ajax({
            url: CSM_GET_NOTES_URL + '&rel_type=' + encodeURIComponent(relType) + '&rel_id=' + encodeURIComponent(relId),
            type: 'GET',
            dataType: 'json',
            success: function (res) {
                if (res && res.success && res.notes && res.notes.length > 0) {
                    var histHtml = '';
                    res.notes.forEach(function (n) {
                        var nJson = escapeHtml(JSON.stringify(n));
                        histHtml += '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px 10px;margin-bottom:8px;position:relative;">' +
                            '<div style="display:flex;justify-content:space-between;align-items:flex-start;">' +
                            '<div style="font-weight:700;color:#1e293b;flex-grow:1;padding-right:10px;">' + escapeHtml(n.note) + '</div>' +
                            '<div style="white-space:nowrap;display:flex;gap:4px;">' +
                            '<button type="button" class="btn btn-default btn-xs" onclick="csmInlineEditNote(' + n.id + ', ' + nJson + ')" title="Edit Note"><i class="fas fa-edit text-primary"></i></button>' +
                            '<button type="button" class="btn btn-danger btn-xs" onclick="csmInlineDeleteNote(' + n.id + ')" title="Delete Note"><i class="fas fa-trash"></i></button>' +
                            '</div>' +
                            '</div>' +
                            '<div style="font-size:11px;color:#64748b;margin-top:4px;">' +
                            (n.paid_amount ? '<span style="color:#16a34a;font-weight:700;">Paid: ' + n.paid_amount + '</span> &bull; ' : '') +
                            (n.due_amount ? '<span style="color:#dc2626;font-weight:700;">Due: ' + n.due_amount + '</span> &bull; ' : '') +
                            (n.promised_date ? '<span style="color:#2563eb;font-weight:600;">Promised: ' + n.promised_date + '</span> &bull; ' : '') +
                            '<span>' + n.created_at + ' (' + escapeHtml(n.admin_name || 'Admin') + ')</span>' +
                            '</div></div>';
                    });
                    $('#modalNotesHistoryList').html(histHtml);
                } else {
                    $('#modalNotesHistoryList').html('<p class="text-muted" style="margin:0;">No previous notes recorded.</p>');
                }
            }
        });
    }

    window.csmInlineEditNote = function (noteId, noteObj) {
        currentEditingNoteId = noteId;
        $('#modalNoteText').val(noteObj.note || '');
        $('#modalNotePaid').val(noteObj.paid_amount || '');
        $('#modalNoteDue').val(noteObj.due_amount || '');
        $('#modalNotePromised').val(noteObj.promised_date || '');
        $('#btnSaveNoteAjax').html('<i class="fas fa-save"></i> Update Note Entry');
        $('#modalNoteText').focus();
        csmToast('info', 'Loaded note for editing. Click Update Note Entry when done.');
    };

    window.csmInlineDeleteNote = function (noteId) {
        csmConfirmAction('Delete Note Entry?', 'Are you sure you want to delete this note permanently?', 'Yes, Delete', function () {
            $.ajax({
                url: CSM_DELETE_NOTE_URL,
                type: 'POST',
                data: { note_id: noteId },
                dataType: 'json',
                success: function (res) {
                    if (res && res.success) {
                        csmToast('success', 'Note deleted successfully');
                        var relType = $('#modalNoteRelType').val();
                        var relId = $('#modalNoteRelId').val();
                        loadNotesHistory(relType, relId);
                        csmLoadData();
                    } else {
                        csmAlert('error', 'Error', res.error || 'Failed to delete note.');
                    }
                },
                error: function () {
                    csmAlert('error', 'Network Error', 'Could not delete note due to a connection error.');
                }
            });
        });
    };

    window.csmSaveNoteAjax = function () {
        var relType = $('#modalNoteRelType').val();
        var relId = $('#modalNoteRelId').val();
        var note = $('#modalNoteText').val().trim();
        var paid = $('#modalNotePaid').val();
        var due = $('#modalNoteDue').val();
        var promised = $('#modalNotePromised').val();

        if (!note) {
            csmAlert('warning', 'Note Required', 'Please enter a note / remark text before saving.');
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

        $('#btnSaveNoteAjax').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

        $.ajax({
            url: targetUrl,
            type: 'POST',
            data: postData,
            dataType: 'json',
            success: function (res) {
                $('#btnSaveNoteAjax').prop('disabled', false).html('<i class="fas fa-plus"></i> Save Note / Record Entry');
                if (res && res.success) {
                    csmToast('success', isEditing ? 'Note updated successfully' : 'Note added successfully');
                    currentEditingNoteId = 0;
                    $('#modalNoteText').val('');
                    $('#modalNotePaid').val('');
                    $('#modalNotePromised').val('');
                    loadNotesHistory(relType, relId);
                    csmLoadData();
                } else {
                    csmAlert('error', 'Save Failed', res.error || 'Failed to save note.');
                }
            },
            error: function () {
                $('#btnSaveNoteAjax').prop('disabled', false).html('<i class="fas fa-plus"></i> Save Note / Record Entry');
                csmAlert('error', 'Network Error', 'Could not save note due to a connection issue.');
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
            csmAlert('warning', 'Date Required', 'Please select a custom grace suspend date.');
            return;
        }

        $.ajax({
            url: (typeof CSM_MODULE_LINK !== 'undefined' ? CSM_MODULE_LINK : 'addonmodules.php?module=client_services_monitor') + '&action=save_grace_suspend',
            type: 'POST',
            data: {
                service_id: serviceId,
                grace_suspend_date: graceDate,
                reason: reason
            },
            success: function () {
                $('#csmLiveGraceModal').modal('hide');
                csmToast('success', 'Custom suspension deadline saved.');
                csmLoadData();
            },
            error: function () {
                csmAlert('error', 'Error', 'Error saving suspension grace deadline.');
            }
        });
    };

})(jQuery);
