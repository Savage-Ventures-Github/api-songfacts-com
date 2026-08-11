(function ($) {
    'use strict';

    var overlay  = $('#smid-modal-overlay');
    var form     = $('#smid-connection-form');
    var isEdit   = false;

    // ── Open modal (Add) ──────────────────────────────────────────
    $('#smid-add-connection').on('click', function () {
        isEdit = false;
        $('#smid-modal-title').text('Add New Connection');
        $('#smid-connection-id').val('');
        form[0].reset();
        $('.smid-edit-note').hide();
        hideNotice();
        openModal();
    });

    // ── Open modal (Edit) ─────────────────────────────────────────
    $(document).on('click', '.smid-btn-edit', function () {
        isEdit = true;
        var btn = $(this);
        $('#smid-modal-title').text('Edit Connection');
        $('#smid-connection-id').val(btn.data('id'));
        $('#smid-label').val(btn.data('label'));
        $('#smid-host').val(btn.data('host'));
        $('#smid-db-name').val(btn.data('db'));
        $('#smid-username').val(btn.data('user'));
        $('#smid-password').val('');
        $('.smid-edit-note').show();
        hideNotice();
        openModal();
    });

    // ── Close modal ───────────────────────────────────────────────
    $('#smid-modal-close, #smid-cancel-btn').on('click', closeModal);
    overlay.on('click', function (e) {
        if ($(e.target).is(overlay)) closeModal();
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });

    function openModal()  { overlay.fadeIn(180); }
    function closeModal() { overlay.fadeOut(180); }

    // ── Toggle password visibility ────────────────────────────────
    $(document).on('click', '.smid-toggle-password', function () {
        var input = $('#smid-password');
        var icon  = $(this).find('.dashicons');
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
        } else {
            input.attr('type', 'password');
            icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
    });

    // ── Save connection ───────────────────────────────────────────
    form.on('submit', function (e) {
        e.preventDefault();

        var saveBtn = $('#smid-save-btn');
        var spinner = $('.smid-saving-spinner');
        saveBtn.prop('disabled', true).hide();
        spinner.show();
        hideNotice();

        $.post(smidAjax.ajaxurl, {
            action        : 'smid_save_connection',
            nonce         : smidAjax.nonce,
            connection_id : $('#smid-connection-id').val(),
            label         : $('#smid-label').val(),
            host          : $('#smid-host').val(),
            db_name       : $('#smid-db-name').val(),
            username      : $('#smid-username').val(),
            password      : $('#smid-password').val(),
        }, function (res) {
            saveBtn.prop('disabled', false).show();
            spinner.hide();

            if (res.success) {
                showNotice('success', res.data.message);
                refreshTable(res.data.id, res.data.conn, isEdit);
                setTimeout(closeModal, 1200);
            } else {
                showNotice('error', res.data.message);
            }
        }).fail(function () {
            saveBtn.prop('disabled', false).show();
            spinner.hide();
            showNotice('error', 'Request failed. Please try again.');
        });
    });

    // ── Delete connection ─────────────────────────────────────────
    $(document).on('click', '.smid-btn-delete', function () {
        if (!confirm('Delete this connection? This cannot be undone.')) return;

        var btn = $(this);
        var id  = btn.data('id');
        btn.prop('disabled', true).text('Deleting…');

        $.post(smidAjax.ajaxurl, {
            action        : 'smid_delete_connection',
            nonce         : smidAjax.nonce,
            connection_id : id,
        }, function (res) {
            if (res.success) {
                $('tr[data-id="' + id + '"]').fadeOut(300, function () {
                    $(this).remove();
                    checkEmptyState();
                });
            } else {
                alert(res.data.message);
                btn.prop('disabled', false).text('Delete');
            }
        });
    });

    // ── Test connection ───────────────────────────────────────────
    $(document).on('click', '.smid-btn-test', function () {
        var btn    = $(this);
        var id     = btn.data('id');
        var status = $('.smid-status[data-id="' + id + '"]');

        btn.prop('disabled', true).text('Testing…');
        status.removeClass('smid-status-ok smid-status-error').addClass('smid-status-unknown').text('• Testing…');

        $.post(smidAjax.ajaxurl, {
            action        : 'smid_test_connection',
            nonce         : smidAjax.nonce,
            connection_id : id,
        }, function (res) {
            btn.prop('disabled', false).text('Test');

            if (res.success) {
                status.removeClass('smid-status-unknown smid-status-error')
                      .addClass('smid-status-ok')
                      .text('• Connected');
            } else {
                status.removeClass('smid-status-unknown smid-status-ok')
                      .addClass('smid-status-error')
                      .text('• Failed');
                alert('Connection failed: ' + res.data.message);
            }
        }).fail(function () {
            btn.prop('disabled', false).text('Test');
            status.text('• Error');
        });
    });

    // ── Helpers ───────────────────────────────────────────────────
    function showNotice(type, msg) {
        $('#smid-form-notice')
            .removeClass('notice-success notice-error')
            .addClass('notice-' + type)
            .text(msg)
            .show();
    }

    function hideNotice() {
        $('#smid-form-notice').hide().text('');
    }

    function checkEmptyState() {
        var tbody = $('.smid-table tbody');
        if (tbody.length && tbody.find('tr').length === 0) {
            location.reload(); // reload to show empty state
        }
    }

    function refreshTable(id, conn, editing) {
        if (editing) {
            var row = $('tr[data-id="' + id + '"]');
            row.find('td:eq(0) strong').text(conn.label);
            row.find('td:eq(1)').text(conn.host);
            row.find('td:eq(2)').text(conn.db_name);
            row.find('td:eq(3)').text(conn.username);

            // Update edit button data attributes
            row.find('.smid-btn-edit')
                .data('label', conn.label)
                .data('host', conn.host)
                .data('db', conn.db_name)
                .data('user', conn.username);

            row.find('.smid-status')
                .removeClass('smid-status-ok smid-status-error')
                .addClass('smid-status-unknown')
                .text('• Unknown');
        } else {
            // New connection: reload to render proper row
            location.reload();
        }
    }


    // ════════════════════════════════════════════════════════════════
    // FORM SETTINGS (DB Connections page — Brands / Asset Categories)
    // ════════════════════════════════════════════════════════════════

    // Edit settings — show inline edit form, hide info row
    $(document).on('click', '.smid-btn-edit-settings', function () {
        var target = $(this).data('target');
        $(target).slideDown(200);
    });

    // Cancel edit settings — hide form, show info row
    $(document).on('click', '.smid-cancel-edit-settings', function () {
        var target = $(this).data('target');
        var info   = $(this).data('info');
        $(target).slideUp(200);
        if ( info ) $(info).show();
    });

    // Disconnect settings
    $(document).on('click', '.smid-btn-delete-settings', function () {
        if ( ! confirm('Remove this connection setting? The data will not be deleted.') ) return;
        var formKey = $(this).data('form');

        $.post(smidAjax.ajaxurl, {
            action   : 'smid_delete_form_settings',
            nonce    : smidAjax.nonce,
            form_key : formKey,
        }, function (res) {
            if ( res.success ) location.reload();
        });
    });

    // When connection changes — load tables
    $(document).on('change', '.smid-conn-selector', function () {
        var connId       = $(this).val();
        var targetSel    = $(this).data('table-target');
        var $tableSelect = $(targetSel);
        var $spinner     = $tableSelect.closest('.smid-form-row').find('.smid-tbl-spinner');

        $tableSelect.html('<option value="">' + ( connId ? 'Loading…' : '— Select Connection First —' ) + '</option>');

        if ( ! connId ) return;

        $spinner.addClass('is-active');

        $.post(smidAjax.ajaxurl, {
            action        : 'smid_get_tables_for_settings',
            nonce         : smidAjax.nonce,
            connection_id : connId,
        }, function (res) {
            $spinner.removeClass('is-active');
            if ( res.success ) {
                var opts = '<option value="">— Select Table —</option>';
                res.data.tables.forEach(function (t) {
                    opts += '<option value="' + esc(t) + '">' + esc(t) + '</option>';
                });
                $tableSelect.html(opts);
            } else {
                $tableSelect.html('<option value="">Error: ' + esc(res.data.message) + '</option>');
            }
        }).fail(function () {
            $spinner.removeClass('is-active');
            $tableSelect.html('<option value="">Request failed</option>');
        });
    });

    // Save / Update settings
    $(document).on('click', '.smid-save-settings', function () {
        var $btn    = $(this);
        var formKey = $btn.data('form');
        var connId  = $( $btn.data('conn-sel') ).val();
        var table   = $( $btn.data('table-sel') ).val();
        var $msg    = $btn.closest('.smid-form-actions').find('.smid-settings-msg');

        if ( ! connId || ! table ) {
            $msg.text('Please select both connection and table.').css('color', '#d63638').show();
            return;
        }

        var origText = $btn.text();
        $btn.prop('disabled', true).text('Saving…');

        $.post(smidAjax.ajaxurl, {
            action        : 'smid_save_form_settings',
            nonce         : smidAjax.nonce,
            form_key      : formKey,
            connection_id : connId,
            table         : table,
        }, function (res) {
            $btn.prop('disabled', false).text(origText);
            if ( res.success ) {
                $msg.text('✓ Saved!').css('color', '#00a32a').show();
                // Update badge in card header
                var $card = $btn.closest('.smid-fs-card');
                $card.find('.smid-badge-missing').replaceWith(
                    '<span class="smid-badge-configured"><span class="dashicons dashicons-yes-alt"></span> Configured</span>'
                );
                setTimeout(function () { location.reload(); }, 900);
            } else {
                $msg.text(res.data.message).css('color', '#d63638').show();
            }
        }).fail(function () {
            $btn.prop('disabled', false).text(origText);
            $msg.text('Request failed.').css('color', '#d63638').show();
        });
    });

    // ════════════════════════════════════════════════════════════════
    // TABLES PAGE
    // ════════════════════════════════════════════════════════════════

    var $tablesContainer = $('#smid-tables-container');
    if ( $tablesContainer.length ) {
        var connId         = $tablesContainer.data('connection');
        var currentPage    = 1;
        var currentTable   = '';
        var allTables      = [];

        // Load tables on page init
        loadTables();

        function loadTables() {
            $.post(smidAjax.ajaxurl, {
                action        : 'smid_get_tables',
                nonce         : smidAjax.nonce,
                connection_id : connId,
            }, function (res) {
                if ( res.success ) {
                    allTables = res.data.tables;
                    renderTablesUI( allTables );
                } else {
                    $tablesContainer.html('<div class="notice notice-error inline"><p>' + res.data.message + '</p></div>');
                }
            }).fail(function () {
                $tablesContainer.html('<div class="notice notice-error inline"><p>Failed to load tables.</p></div>');
            });
        }

        function renderTablesUI( tables ) {
            var html = '<div class="smid-tables-toolbar">'
                + '<input type="search" id="smid-table-search" placeholder="Search tables…" class="regular-text">'
                + '<span class="smid-table-count">' + tables.length + ' tables</span>'
                + '</div>'
                + '<table class="widefat smid-tables-table">'
                + '<thead><tr>'
                + '<th>#</th><th>Table Name</th><th>Rows</th><th>Engine</th><th>Size</th><th>Actions</th>'
                + '</tr></thead><tbody id="smid-tables-tbody">';

            tables.forEach(function (t, i) {
                html += renderTableRow(t, i + 1);
            });

            html += '</tbody></table>';
            $tablesContainer.html(html);

            // Live search
            $('#smid-table-search').on('input', function () {
                var q = $(this).val().toLowerCase();
                var filtered = allTables.filter(function (t) {
                    return t.name.toLowerCase().includes(q);
                });
                $('#smid-tables-tbody').html('');
                filtered.forEach(function (t, i) {
                    $('#smid-tables-tbody').append(renderTableRow(t, i + 1));
                });
                $('.smid-table-count').text(filtered.length + ' tables');
            });
        }

        function renderTableRow(t, num) {
            return '<tr data-table="' + esc(t.name) + '">'
                + '<td style="color:#999;font-size:12px;">' + num + '</td>'
                + '<td><span class="smid-table-name">' + esc(t.name) + '</span></td>'
                + '<td><span class="smid-row-count">' + (t.rows || 0) + '</span></td>'
                + '<td><span class="smid-engine-badge">' + esc(t.engine || '—') + '</span></td>'
                + '<td style="color:#646970;font-size:12px;">' + esc(t.size) + '</td>'
                + '<td class="smid-actions">'
                + '<button class="button smid-btn-view" data-table="' + esc(t.name) + '">View Data</button>'
                + '</td>'
                + '</tr>';
        }

        // ── View Data ─────────────────────────────────────────────
        $(document).on('click', '.smid-btn-view', function () {
            currentTable = $(this).data('table');
            currentPage  = 1;
            $('#smid-data-modal-title').text('Table: ' + currentTable);
            $('#smid-data-modal-body').html('<div class="smid-loading"><span class="spinner is-active"></span> Loading…</div>');
            $('#smid-data-modal').fadeIn(180);
            fetchTableData();
        });

        $('#smid-data-modal-close').on('click', function () {
            $('#smid-data-modal').fadeOut(180);
        });

        $('#smid-data-modal').on('click', function (e) {
            if ($(e.target).is('#smid-data-modal')) $(this).fadeOut(180);
        });

        function fetchTableData() {
            $.post(smidAjax.ajaxurl, {
                action        : 'smid_get_table_data',
                nonce         : smidAjax.nonce,
                connection_id : connId,
                table_name    : currentTable,
                page          : currentPage,
            }, function (res) {
                if ( ! res.success ) {
                    $('#smid-data-modal-body').html('<p style="color:red;">' + res.data.message + '</p>');
                    return;
                }

                var d = res.data;

                if ( d.columns.length === 0 ) {
                    $('#smid-data-modal-body').html('<p style="padding:20px;color:#646970;">No data found in this table.</p>');
                    return;
                }

                var html = '<div class="smid-data-table-wrap"><table class="smid-data-table"><thead><tr>';
                d.columns.forEach(function (col) {
                    html += '<th>' + esc(col) + '</th>';
                });
                html += '</tr></thead><tbody>';

                d.rows.forEach(function (row) {
                    html += '<tr>';
                    d.columns.forEach(function (col) {
                        var val = row[col];
                        if ( val === null || val === undefined ) {
                            html += '<td><span class="smid-null-val">NULL</span></td>';
                        } else {
                            html += '<td title="' + esc(String(val)) + '">' + esc(String(val)) + '</td>';
                        }
                    });
                    html += '</tr>';
                });

                html += '</tbody></table></div>';

                // Pagination
                html += '<div class="smid-data-pagination">';
                html += '<span>Showing rows ' + ((d.page - 1) * d.per_page + 1) + '–' + Math.min(d.page * d.per_page, d.total) + ' of ' + d.total + '</span>';
                if ( d.page > 1 ) {
                    html += '<button class="button smid-page-prev">&laquo; Prev</button>';
                }
                if ( d.page < d.total_pages ) {
                    html += '<button class="button smid-page-next">Next &raquo;</button>';
                }
                html += '</div>';

                $('#smid-data-modal-body').html(html);
            });
        }

        $(document).on('click', '.smid-page-prev', function () { currentPage--; fetchTableData(); });
        $(document).on('click', '.smid-page-next', function () { currentPage++; fetchTableData(); });

        // ── Drop table ────────────────────────────────────────────
        $(document).on('click', '.smid-btn-drop', function () {
            var table = $(this).data('table');
            if ( ! confirm('Delete table "' + table + '"? This is PERMANENT and cannot be undone!') ) return;

            var btn = $(this);
            btn.prop('disabled', true).text('Deleting…');

            $.post(smidAjax.ajaxurl, {
                action        : 'smid_drop_table',
                nonce         : smidAjax.nonce,
                connection_id : connId,
                table_name    : table,
            }, function (res) {
                if ( res.success ) {
                    $('tr[data-table="' + table + '"]').fadeOut(300, function () {
                        $(this).remove();
                        allTables = allTables.filter(function (t) { return t.name !== table; });
                        $('.smid-table-count').text(allTables.length + ' tables');
                    });
                } else {
                    alert('Error: ' + res.data.message);
                    btn.prop('disabled', false).text('Delete');
                }
            });
        });

        // ── Rename table ──────────────────────────────────────────
        $(document).on('click', '.smid-btn-rename', function () {
            var table = $(this).data('table');
            $('#smid-rename-input').val(table);
            $('#smid-rename-old').val(table);
            $('#smid-rename-notice').hide();
            $('#smid-rename-modal').fadeIn(180);
        });

        $('.smid-rename-close').on('click', function () {
            $('#smid-rename-modal').fadeOut(180);
        });

        $('#smid-rename-modal').on('click', function (e) {
            if ($(e.target).is('#smid-rename-modal')) $(this).fadeOut(180);
        });

        $('#smid-rename-confirm').on('click', function () {
            var oldName = $('#smid-rename-old').val();
            var newName = $('#smid-rename-input').val().trim();

            if ( ! newName || newName === oldName ) return;

            var btn = $(this);
            btn.prop('disabled', true).text('Renaming…');

            $.post(smidAjax.ajaxurl, {
                action        : 'smid_rename_table',
                nonce         : smidAjax.nonce,
                connection_id : connId,
                old_name      : oldName,
                new_name      : newName,
            }, function (res) {
                btn.prop('disabled', false).text('Rename');
                if ( res.success ) {
                    // Update UI
                    var row = $('tr[data-table="' + oldName + '"]');
                    row.attr('data-table', newName);
                    row.find('.smid-table-name').text(newName);
                    row.find('.smid-btn-view, .smid-btn-rename, .smid-btn-drop').data('table', newName).attr('data-table', newName);
                    allTables = allTables.map(function (t) {
                        return t.name === oldName ? Object.assign({}, t, { name: newName }) : t;
                    });
                    $('#smid-rename-modal').fadeOut(180);
                } else {
                    $('#smid-rename-notice')
                        .removeClass('notice-success').addClass('notice-error')
                        .text(res.data.message).show();
                }
            });
        });
    }

    // ════════════════════════════════════════════════════════════════
    // PAGINATION HELPER
    // ════════════════════════════════════════════════════════════════

    function buildPagination(currentPage, totalPages, totalItems, listKey) {
        if ( totalPages <= 1 ) return '';
        var prev     = currentPage - 1;
        var next     = currentPage + 1;
        var disFirst = currentPage <= 1;
        var disLast  = currentPage >= totalPages;

        return '<div class="tablenav bottom smid-tablenav">'
            + '<div class="tablenav-pages">'
            + '<span class="displaying-num">' + totalItems + ' item' + (totalItems !== 1 ? 's' : '') + '</span>'
            + '<span class="pagination-links">'
            + '<button class="button smid-pg-btn first-page' + (disFirst ? ' disabled' : '') + '" data-list="' + listKey + '" data-page="1"' + (disFirst ? ' disabled' : '') + ' title="First page">«</button>'
            + '<button class="button smid-pg-btn prev-page'  + (disFirst ? ' disabled' : '') + '" data-list="' + listKey + '" data-page="' + prev + '"'  + (disFirst ? ' disabled' : '') + ' title="Previous page">‹</button>'
            + '<span class="paging-input"><span class="tablenav-paging-text">' + currentPage + ' of <span class="total-pages">' + totalPages + '</span></span></span>'
            + '<button class="button smid-pg-btn next-page'  + (disLast  ? ' disabled' : '') + '" data-list="' + listKey + '" data-page="' + next + '"'  + (disLast  ? ' disabled' : '') + ' title="Next page">›</button>'
            + '<button class="button smid-pg-btn last-page'  + (disLast  ? ' disabled' : '') + '" data-list="' + listKey + '" data-page="' + totalPages + '"' + (disLast  ? ' disabled' : '') + ' title="Last page">»</button>'
            + '</span>'
            + '</div>'
            + '</div>';
    }

    // ════════════════════════════════════════════════════════════════
    // BRANDS CRUD
    // ════════════════════════════════════════════════════════════════

    var $brandsWrap    = $('#smid-brands-list-wrap');
    var allBrands      = [];
    var brandPage      = 1;
    var BRANDS_PER_PAGE = 10;

    if ( $brandsWrap.length ) {
        loadBrands();
    }

    function loadBrands() {
        $.post(smidAjax.ajaxurl, {
            action : 'smid_get_brands',
            nonce  : smidAjax.nonce,
        }, function (res) {
            if ( res.success ) {
                allBrands = res.data.brands;
                brandPage = 1;
                renderBrandsPage();
            } else {
                $brandsWrap.html('<div class="notice notice-error inline" style="margin:16px;"><p>' + esc(res.data.message) + '</p></div>');
            }
        }).fail(function () {
            $brandsWrap.html('<div class="notice notice-error inline" style="margin:16px;"><p>Failed to load brands. Check the <a href="admin.php?page=smid-connections">Connections page</a>.</p></div>');
        });
    }

    function renderBrandsPage() {
        var total      = allBrands.length;
        var totalPages = Math.max(1, Math.ceil(total / BRANDS_PER_PAGE));
        var start      = (brandPage - 1) * BRANDS_PER_PAGE;
        var slice      = allBrands.slice(start, start + BRANDS_PER_PAGE);

        if ( ! total ) {
            $brandsWrap.html(
                '<div class="smid-items-empty">'
                + '<span class="dashicons dashicons-tag"></span>'
                + '<p>No brands yet.</p>'
                + '<button class="button button-primary smid-empty-add-brand">+ Add First Brand</button>'
                + '</div>'
            );
            return;
        }

        var html = '<div class="smid-items-header">'
            + '<span class="smid-items-count">' + total + ' brand' + (total !== 1 ? 's' : '') + '</span>'
            + '</div>'
            + '<table class="smid-items-table"><thead><tr>'
            + '<th class="col-id">#</th>'
            + '<th>Brand Name</th>'
            + '<th class="col-date">Created</th>'
            + '<th class="col-actions">Actions</th>'
            + '</tr></thead><tbody>';

        slice.forEach(function (b) {
            html += '<tr data-brand-id="' + esc(b.id) + '">'
                + '<td class="col-id"><span class="smid-id-badge">' + esc(b.id) + '</span></td>'
                + '<td><span class="smid-item-name">' + esc(b.name) + '</span></td>'
                + '<td class="col-date"><span class="smid-date-val">' + esc(b.created_at || '—') + '</span></td>'
                + '<td class="col-actions"><div class="smid-actions">'
                + '<button class="smid-action-btn smid-action-edit smid-btn-edit-brand" data-id="' + esc(b.id) + '" data-name="' + esc(b.name) + '"><span class="dashicons dashicons-edit"></span> Edit</button>'
                + '<button class="smid-action-btn smid-action-delete smid-btn-delete-brand" data-id="' + esc(b.id) + '"><span class="dashicons dashicons-trash"></span> Delete</button>'
                + '</div></td></tr>';
        });

        html += '</tbody></table>' + buildPagination(brandPage, totalPages, total, 'brand');
        $brandsWrap.html(html);
    }

    // Pagination navigation
    $(document).on('click', '.smid-pg-btn[data-list="brand"]', function () {
        if ( $(this).prop('disabled') ) return;
        brandPage = parseInt( $(this).data('page') );
        renderBrandsPage();
    });

    // Empty state add button
    $(document).on('click', '.smid-empty-add-brand', function () {
        $('#smid-open-brand-modal').trigger('click');
    });

    // Open modal — Add
    $(document).on('click', '#smid-open-brand-modal', function () {
        $('#smid-brand-modal-title').text('Add New Brand');
        $('#smid-brand-id').val('');
        $('#smid-brand-name-input').val('');
        $('#smid-brand-submit').text('Add Brand');
        $('#smid-brand-notice').hide();
        $('#smid-brand-modal').fadeIn(180);
        $('#smid-brand-name-input').focus();
    });

    // Open modal — Edit
    $(document).on('click', '.smid-btn-edit-brand', function () {
        $('#smid-brand-modal-title').text('Edit Brand');
        $('#smid-brand-id').val($(this).data('id'));
        $('#smid-brand-name-input').val($(this).data('name'));
        $('#smid-brand-submit').text('Update Brand');
        $('#smid-brand-notice').hide();
        $('#smid-brand-modal').fadeIn(180);
        $('#smid-brand-name-input').focus();
    });

    // Close modal
    $('#smid-brand-modal-close, #smid-brand-modal-cancel').on('click', function () {
        $('#smid-brand-modal').fadeOut(180);
    });
    $('#smid-brand-modal').on('click', function (e) {
        if ($(e.target).is('#smid-brand-modal')) $(this).fadeOut(180);
    });

    // Submit brand form
    $(document).on('submit', '#smid-brand-form', function (e) {
        e.preventDefault();
        var $btn     = $('#smid-brand-submit');
        var $spinner = $('.smid-saving-spinner', '#smid-brand-modal');
        var id       = $('#smid-brand-id').val();
        var name     = $('#smid-brand-name-input').val().trim();

        if ( ! name ) return;

        $btn.prop('disabled', true).hide();
        $spinner.show();
        $('#smid-brand-notice').hide();

        $.post(smidAjax.ajaxurl, {
            action     : 'smid_save_brand',
            nonce      : smidAjax.nonce,
            brand_id   : id,
            brand_name : name,
        }, function (res) {
            $btn.prop('disabled', false).show();
            $spinner.hide();

            if ( res.success ) {
                $('#smid-brand-modal').fadeOut(180);
                loadBrands();
            } else {
                $('#smid-brand-notice')
                    .removeClass('notice-success').addClass('notice-error')
                    .text(res.data.message).show();
            }
        }).fail(function () {
            $btn.prop('disabled', false).show();
            $spinner.hide();
        });
    });

    // Delete brand
    $(document).on('click', '.smid-btn-delete-brand', function () {
        if ( ! confirm('Delete this brand? This cannot be undone.') ) return;
        var btn = $(this);
        var id  = btn.data('id');
        btn.prop('disabled', true);

        $.post(smidAjax.ajaxurl, {
            action   : 'smid_delete_brand',
            nonce    : smidAjax.nonce,
            brand_id : id,
        }, function (res) {
            if ( res.success ) {
                allBrands = allBrands.filter(function (b) { return b.id != id; });
                if ( brandPage > Math.max(1, Math.ceil(allBrands.length / BRANDS_PER_PAGE)) ) {
                    brandPage--;
                }
                renderBrandsPage();
            } else {
                alert(res.data.message);
                btn.prop('disabled', false);
            }
        });
    });

    // ════════════════════════════════════════════════════════════════
    // ASSET CATEGORIES CRUD
    // ════════════════════════════════════════════════════════════════

    var $catsWrap      = $('#smid-cats-list-wrap');
    var allCats        = [];
    var catPage        = 1;
    var CATS_PER_PAGE  = 10;

    if ( $catsWrap.length ) {
        loadCategories();
    }

    function loadCategories() {
        $.post(smidAjax.ajaxurl, {
            action : 'smid_get_categories',
            nonce  : smidAjax.nonce,
        }, function (res) {
            if ( res.success ) {
                allCats = res.data.categories;
                catPage = 1;
                renderCatsPage();
            } else {
                $catsWrap.html('<p style="padding:20px;color:#d63638;">' + esc(res.data.message) + '</p>');
            }
        }).fail(function () {
            $catsWrap.html('<div class="notice notice-error inline" style="margin:16px;"><p>Failed to load categories. Check the <a href="admin.php?page=smid-connections">Connections page</a>.</p></div>');
        });
    }

    function renderCatsPage() {
        var total      = allCats.length;
        var totalPages = Math.max(1, Math.ceil(total / CATS_PER_PAGE));
        var start      = (catPage - 1) * CATS_PER_PAGE;
        var slice      = allCats.slice(start, start + CATS_PER_PAGE);

        if ( ! total ) {
            $catsWrap.html(
                '<div class="smid-items-empty">'
                + '<span class="dashicons dashicons-category"></span>'
                + '<p>No categories yet.</p>'
                + '<button class="button button-primary smid-empty-add-cat">+ Add First Category</button>'
                + '</div>'
            );
            return;
        }

        var html = '<div class="smid-items-header">'
            + '<span class="smid-items-count">' + total + ' categor' + (total !== 1 ? 'ies' : 'y') + '</span>'
            + '</div>'
            + '<table class="smid-items-table"><thead><tr>'
            + '<th class="col-id">#</th>'
            + '<th>Category Name</th>'
            + '<th class="col-date">Created</th>'
            + '<th class="col-actions">Actions</th>'
            + '</tr></thead><tbody>';

        slice.forEach(function (c) {
            html += '<tr data-cat-id="' + esc(c.id) + '">'
                + '<td class="col-id"><span class="smid-id-badge">' + esc(c.id) + '</span></td>'
                + '<td><span class="smid-item-name">' + esc(c.name) + '</span></td>'
                + '<td class="col-date"><span class="smid-date-val">' + esc(c.created_at || '—') + '</span></td>'
                + '<td class="col-actions"><div class="smid-actions">'
                + '<button class="smid-action-btn smid-action-edit smid-btn-edit-cat" data-id="' + esc(c.id) + '" data-name="' + esc(c.name) + '"><span class="dashicons dashicons-edit"></span> Edit</button>'
                + '<button class="smid-action-btn smid-action-delete smid-btn-delete-cat" data-id="' + esc(c.id) + '"><span class="dashicons dashicons-trash"></span> Delete</button>'
                + '</div></td></tr>';
        });

        html += '</tbody></table>' + buildPagination(catPage, totalPages, total, 'cat');
        $catsWrap.html(html);
    }

    // Pagination navigation
    $(document).on('click', '.smid-pg-btn[data-list="cat"]', function () {
        if ( $(this).prop('disabled') ) return;
        catPage = parseInt( $(this).data('page') );
        renderCatsPage();
    });

    // Empty state add button
    $(document).on('click', '.smid-empty-add-cat', function () {
        $('#smid-open-cat-modal').trigger('click');
    });

    // Open modal — Add
    $(document).on('click', '#smid-open-cat-modal', function () {
        $('#smid-cat-modal-title').text('Add New Category');
        $('#smid-cat-id').val('');
        $('#smid-cat-name-input').val('');
        $('#smid-cat-submit').text('Add Category');
        $('#smid-cat-notice').hide();
        $('#smid-cat-modal').fadeIn(180);
        $('#smid-cat-name-input').focus();
    });

    // Open modal — Edit
    $(document).on('click', '.smid-btn-edit-cat', function () {
        $('#smid-cat-modal-title').text('Edit Category');
        $('#smid-cat-id').val($(this).data('id'));
        $('#smid-cat-name-input').val($(this).data('name'));
        $('#smid-cat-submit').text('Update Category');
        $('#smid-cat-notice').hide();
        $('#smid-cat-modal').fadeIn(180);
        $('#smid-cat-name-input').focus();
    });

    // Close modal
    $('#smid-cat-modal-close, #smid-cat-modal-cancel').on('click', function () {
        $('#smid-cat-modal').fadeOut(180);
    });
    $('#smid-cat-modal').on('click', function (e) {
        if ($(e.target).is('#smid-cat-modal')) $(this).fadeOut(180);
    });

    // Submit category form
    $(document).on('submit', '#smid-cat-form', function (e) {
        e.preventDefault();
        var $btn     = $('#smid-cat-submit');
        var $spinner = $('.smid-saving-spinner', '#smid-cat-modal');
        var id       = $('#smid-cat-id').val();
        var name     = $('#smid-cat-name-input').val().trim();

        if ( ! name ) return;

        $btn.prop('disabled', true).hide();
        $spinner.show();
        $('#smid-cat-notice').hide();

        $.post(smidAjax.ajaxurl, {
            action   : 'smid_save_category',
            nonce    : smidAjax.nonce,
            cat_id   : id,
            cat_name : name,
        }, function (res) {
            $btn.prop('disabled', false).show();
            $spinner.hide();

            if ( res.success ) {
                $('#smid-cat-modal').fadeOut(180);
                loadCategories();
            } else {
                $('#smid-cat-notice')
                    .removeClass('notice-success').addClass('notice-error')
                    .text(res.data.message).show();
            }
        }).fail(function () {
            $btn.prop('disabled', false).show();
            $spinner.hide();
        });
    });

    // Delete category
    $(document).on('click', '.smid-btn-delete-cat', function () {
        if ( ! confirm('Delete this category? This cannot be undone.') ) return;
        var btn = $(this);
        var id  = btn.data('id');
        btn.prop('disabled', true);

        $.post(smidAjax.ajaxurl, {
            action : 'smid_delete_category',
            nonce  : smidAjax.nonce,
            cat_id : id,
        }, function (res) {
            if ( res.success ) {
                allCats = allCats.filter(function (c) { return c.id != id; });
                if ( catPage > Math.max(1, Math.ceil(allCats.length / CATS_PER_PAGE)) ) {
                    catPage--;
                }
                renderCatsPage();
            } else {
                alert(res.data.message);
                btn.prop('disabled', false);
            }
        });
    });

    // ────────────────────────────────────────────────────────────────
    // ONLINE MEDIA ASSETS CRUD
    // ────────────────────────────────────────────────────────────────

    var $omaWrap         = $('#smid-oma-list-wrap');
    var allOma           = [];
    var omaPage          = 1;
    var OMA_PER_PAGE     = 10;
    var omaBrands        = [];
    var omaCats          = [];
    var omaArtists       = [];
    var omaExistingKws   = [];   // existing keywords for autosuggest
    var sfArtistsConn    = '';
    var sfArtistsTable   = '';
    var sfSongsConn      = '';
    var sfSongsTable     = '';

    // Run both in parallel; re-render list when form data arrives (names lookup)
    if ( $('#smid-oma-modal').length || $omaWrap.length ) {
        loadOmaFormData();
        loadExistingKeywords();
    }
    if ( $omaWrap.length ) {
        loadOmaRecords();
    }

    // Load brands + categories + artists for dropdowns
    function loadOmaFormData() {
        $.post(smidAjax.ajaxurl, {
            action : 'smid_get_oma_form_data',
            nonce  : smidAjax.nonce,
        }, function (res) {
            if ( res.success ) {
                omaBrands      = res.data.brands     || [];
                omaCats        = res.data.categories  || [];
                omaArtists     = res.data.artists     || [];
                sfArtistsConn  = res.data.sf_artists_conn  || '';
                sfArtistsTable = res.data.sf_artists_table || '';
                sfSongsConn    = res.data.sf_songs_conn    || '';
                sfSongsTable   = res.data.sf_songs_table   || '';
                populateSelect2('#smid-oma-brand',    omaBrands, 'Search brand…');
                populateSelect2('#smid-oma-category', omaCats,   'Search category…');
                initPairs();
                // Re-render list now that names are available
                if ( allOma.length ) { renderOmaPage(); }
            }
        });
    }

    // Load existing keywords for autosuggest
    function loadExistingKeywords() {
        $.post(smidAjax.ajaxurl, {
            action : 'smid_get_oma_existing_keywords',
            nonce  : smidAjax.nonce,
        }, function (res) {
            if ( res.success ) { omaExistingKws = res.data.keywords || []; }
        });
    }

    // Generic Select2 for brand / category (single)
    function populateSelect2(selector, items, placeholder) {
        var $sel = $(selector);
        if ( $.fn.select2 && $sel.data('select2') ) $sel.select2('destroy');
        $sel.empty().append('<option value=""></option>');
        items.forEach(function (item) {
            $sel.append('<option value="' + esc(item.id) + '">' + esc(item.name) + '</option>');
        });
        if ( $.fn.select2 ) {
            $sel.select2({
                placeholder    : placeholder || 'Search…',
                allowClear     : true,
                width          : '100%',
                dropdownParent : $('body'),
            });
        }
    }

    // ── Artist + Song Pairs system ────────────────────────────────
    var pairsState = { rows: [], counter: 0, cache: {} };

    function getSelectedSongIds(exceptRowId) {
        var ids = [];
        pairsState.rows.forEach(function (row) {
            if ( row.id !== exceptRowId && row.songId ) {
                ids.push(String(row.songId));
            }
        });
        return ids;
    }

    function buildPairRowHtml(rowId, isFirst) {
        return '<div class="smid-pair-row" data-row="' + rowId + '">'
            + '<div class="smid-pair-col smid-pair-artist-col">'
            + '<label class="smid-pair-sublabel">Artist</label>'
            + '<select class="smid-pair-artist-sel" data-row="' + rowId + '" style="width:100%;"></select>'
            + '</div>'
            + '<div class="smid-pair-col smid-pair-song-col">'
            + '<label class="smid-pair-sublabel">Song Title</label>'
            + '<select class="smid-pair-song-sel" data-row="' + rowId + '" style="width:100%;" disabled>'
            + '<option value="">Select artist first…</option>'
            + '</select>'
            + '</div>'
            + '<div class="smid-pair-col smid-pair-album-col">'
            + '<label class="smid-pair-sublabel">Album</label>'
            + '<input type="text" class="smid-pair-album-val" data-row="' + rowId + '" readonly placeholder="Auto-filled after song selection…" style="width:100%;">'
            + '</div>'
            + ( isFirst
                ? '<div class="smid-pair-remove-placeholder"></div>'
                : '<button type="button" class="smid-pair-remove" data-row="' + rowId + '" title="Remove row">×</button>' )
            + '</div>';
    }

    function s2destroy($el) {
        if ( $.fn.select2 && $el.data('select2') ) {
            try { $el.select2('close'); } catch(e) {}
            $el.select2('destroy');
        }
    }

    function initPairArtistSelect2(rowId) {
        var $a = $('.smid-pair-artist-sel[data-row="' + rowId + '"]');
        s2destroy($a);
        $a.empty();

        if ( ! $.fn.select2 ) return;

        // Build all options via DocumentFragment (single DOM operation — fast even for large lists)
        var frag = document.createDocumentFragment();
        var blank = document.createElement('option');
        blank.value = '';
        frag.appendChild(blank);
        omaArtists.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value    = item.id;
            opt.textContent = item.name;
            frag.appendChild(opt);
        });
        $a[0].appendChild(frag);

        $a.select2({
            placeholder        : 'Type 3+ characters to search…',
            minimumInputLength : 3,
            allowClear         : true,
            width              : '100%',
            dropdownParent     : $('body'),
            language           : { inputTooShort: function () { return 'Type at least 3 characters…'; } },
        });
    }

    function populatePairSong(rowId, songs, keepSongId) {
        var $s = $('.smid-pair-song-sel[data-row="' + rowId + '"]');
        s2destroy($s);
        $s.empty();
        var excluded = getSelectedSongIds(rowId);
        if ( songs && songs.length ) {
            $s.append('<option value=""></option>');
            songs.forEach(function (sg) {
                if ( excluded.indexOf(String(sg.id)) === -1 || String(sg.id) === String(keepSongId) ) {
                    $s.append('<option value="' + esc(sg.id) + '">' + esc(sg.name) + '</option>');
                }
            });
            $s.prop('disabled', false);
            if ( $.fn.select2 ) {
                $s.select2({ placeholder: 'Search song…', allowClear: true, width: '100%', dropdownParent: $('body') });
            }
            if ( keepSongId ) {
                $s.val(keepSongId).trigger('change.select2');
                fillAlbumForRow(rowId);
            }
        } else {
            $s.append('<option value="">Select artist first…</option>').prop('disabled', true);
            if ( $.fn.select2 ) {
                $s.select2({ placeholder: 'Select artist first…', width: '100%', dropdownParent: $('body') });
            }
        }
    }

    function refreshAllSongDropdowns() {
        pairsState.rows.forEach(function (row) {
            if ( row.songs && row.songs.length ) {
                populatePairSong(row.id, row.songs, row.songId);
            }
        });
    }

    function loadSongsForPairRow(rowId, artistId, keepSongId) {
        var stateRow = null;
        pairsState.rows.forEach(function (r) { if ( r.id === rowId ) stateRow = r; });
        if ( ! stateRow ) return;

        if ( pairsState.cache[artistId] ) {
            stateRow.songs = pairsState.cache[artistId];
            populatePairSong(rowId, stateRow.songs, keepSongId);
            if ( keepSongId ) stateRow.songId = keepSongId;
            return;
        }
        // Loading state
        var $s = $('.smid-pair-song-sel[data-row="' + rowId + '"]');
        s2destroy($s);
        $s.empty().append('<option value="">Loading…</option>').prop('disabled', true);
        if ( $.fn.select2 ) $s.select2({ placeholder: 'Loading…', width: '100%', dropdownParent: $('body') });

        $.post(smidAjax.ajaxurl, {
            action    : 'smid_get_oma_songs_by_artist',
            nonce     : smidAjax.nonce,
            artist_id : artistId,
        }, function (res) {
            var songs = res.success ? (res.data.songs || []) : [];
            pairsState.cache[artistId] = songs;
            stateRow.songs = songs;
            populatePairSong(rowId, songs, keepSongId);
            if ( keepSongId ) stateRow.songId = keepSongId;
        }).fail(function () {
            $s.empty().append('<option value="">Error loading songs</option>');
        });
    }

    function addPairRow(artistId, artistName, songId, albumId, albumName) {
        var rowId   = pairsState.counter++;
        var isFirst = pairsState.rows.length === 0;
        pairsState.rows.push({ id: rowId, artistId: null, songId: null, songs: [], albumId: null, albumName: null, albumList: [] });
        $('#smid-oma-pairs').append(buildPairRowHtml(rowId, isFirst));
        initPairArtistSelect2(rowId);

        if ( artistId ) {
            var $a = $('.smid-pair-artist-sel[data-row="' + rowId + '"]');
            $a.val(artistId).trigger('change.select2');
            var stateRow = null;
            pairsState.rows.forEach(function (r) { if ( r.id === rowId ) stateRow = r; });
            if ( stateRow ) stateRow.artistId = artistId;
            loadSongsForPairRow(rowId, artistId, songId);
        }
    }

    function initPairs() {
        // Destroy all existing Select2s in pairs
        $('#smid-oma-pairs .smid-pair-artist-sel, #smid-oma-pairs .smid-pair-song-sel').each(function () {
            s2destroy($(this));
        });
        pairsState = { rows: [], counter: 0, cache: pairsState.cache || {} };
        $('#smid-oma-pairs').empty();
        addPairRow(null, null, null, null, null);
    }

    // ── Per-row Album: auto-filled from song data ─────────────────
    function fillAlbumForRow(rowId) {
        var stateRow = null;
        pairsState.rows.forEach(function (r) { if ( r.id === rowId ) stateRow = r; });
        if ( ! stateRow ) return;

        var songId = stateRow.songId || $('.smid-pair-song-sel[data-row="' + rowId + '"]').val();
        var $input = $('.smid-pair-album-val[data-row="' + rowId + '"]');

        if ( ! songId ) {
            stateRow.albumId   = null;
            stateRow.albumName = null;
            $input.val('');
            return;
        }

        var song = null;
        (stateRow.songs || []).forEach(function (s) {
            if ( String(s.id) === String(songId) ) song = s;
        });

        var albumName = song ? ( song.album || '' ) : '';
        var albumId   = song ? parseInt( song.id ) : null;

        stateRow.albumId   = albumId;
        stateRow.albumName = albumName;
        $input.val( albumName );
    }

    // Artist changed in a pair row
    $(document).on('change', '.smid-pair-artist-sel', function () {
        $(this).closest('.smid-pair-artist-col').removeClass('smid-pair-field-error');
        var rowId    = parseInt( $(this).data('row') );
        var artistId = $(this).val();
        var stateRow = null;
        pairsState.rows.forEach(function (r) { if ( r.id === rowId ) stateRow = r; });
        if ( ! stateRow ) return;

        stateRow.artistId  = artistId || null;
        stateRow.songId    = null;
        stateRow.songs     = [];
        stateRow.albumId   = null;
        stateRow.albumName = null;
        stateRow.albumList = [];

        var $s = $('.smid-pair-song-sel[data-row="' + rowId + '"]');
        s2destroy($s);
        $s.empty().append('<option value="">Select artist first…</option>').prop('disabled', true);
        if ( $.fn.select2 ) $s.select2({ placeholder: 'Select artist first…', width: '100%', dropdownParent: $('body') });

        // Clear album when artist changes
        stateRow.albumId   = null;
        stateRow.albumName = null;
        $('.smid-pair-album-val[data-row="' + rowId + '"]').val('');

        if ( ! artistId ) return;
        loadSongsForPairRow(rowId, artistId, null);
    });

    // Song changed in a pair row → auto-fill album + refresh other rows
    $(document).on('change', '.smid-pair-song-sel', function () {
        $(this).closest('.smid-pair-song-col').removeClass('smid-pair-field-error');
        var rowId  = parseInt( $(this).data('row') );
        var songId = $(this).val();
        var stateRow = null;
        pairsState.rows.forEach(function (r) { if ( r.id === rowId ) stateRow = r; });
        if ( stateRow ) stateRow.songId = songId || null;
        fillAlbumForRow(rowId);
        var $mb = $('.smid-oma-modal .smid-modal-body');
        var scrollPos = $mb.scrollTop();
        refreshAllSongDropdowns();
        $mb.scrollTop(scrollPos);
    });

    // Add More
    $(document).on('click', '#smid-add-pair-btn', function () {
        addPairRow(null, null, null, null, null);
    });

    // Remove a pair row
    $(document).on('click', '.smid-pair-remove', function () {
        var rowId = parseInt( $(this).data('row') );
        var $a = $('.smid-pair-artist-sel[data-row="' + rowId + '"]');
        var $s = $('.smid-pair-song-sel[data-row="' + rowId + '"]');
        s2destroy($a);
        s2destroy($s);
        pairsState.rows = pairsState.rows.filter(function (r) { return r.id !== rowId; });
        $('.smid-pair-row[data-row="' + rowId + '"]').remove();
        refreshAllSongDropdowns();
    });


    // Load all OMA records
    function loadOmaRecords() {
        $omaWrap.html( renderOmaSkeleton() );
        $.post(smidAjax.ajaxurl, {
            action : 'smid_get_oma_records',
            nonce  : smidAjax.nonce,
        }, function (res) {
            if ( res.success ) {
                allOma  = res.data.records;
                // Merge brands/categories/artists returned with records (ensures list always has names)
                if ( res.data.brands     && res.data.brands.length     ) omaBrands  = res.data.brands;
                if ( res.data.categories && res.data.categories.length ) omaCats    = res.data.categories;
                if ( res.data.artists    && res.data.artists.length    ) omaArtists = res.data.artists;
                omaPage = 1;
                renderOmaPage();
            } else {
                $omaWrap.html('<div class="notice notice-error inline" style="margin:16px;"><p>' + esc(res.data.message) + '</p></div>');
            }
        }).fail(function () {
            $omaWrap.html('<div class="notice notice-error inline" style="margin:16px;"><p>Failed to load assets. Check the <a href="admin.php?page=smid-connections">Connections page</a>.</p></div>');
        });
    }

    function renderOmaSkeleton() {
        var cols = ['40px','130px','110px','110px','120px','130px','120px','110px','120px','100px'];
        var rows = '';
        for ( var i = 0; i < 5; i++ ) {
            rows += '<tr>';
            cols.forEach(function(w) {
                rows += '<td><span class="smid-skel" style="width:' + w + ';"></span></td>';
            });
            rows += '</tr>';
        }
        return '<table class="smid-items-table smid-oma-table"><thead><tr>'
            + '<th>#</th><th>Title</th><th>Brand</th><th>Category</th><th>Artist</th>'
            + '<th>Song Title</th><th>Keywords</th><th>Pub Date</th><th>URL</th><th>Actions</th>'
            + '</tr></thead><tbody>' + rows + '</tbody></table>';
    }

    function renderOmaPage() {
        var total      = allOma.length;
        var totalPages = Math.max(1, Math.ceil(total / OMA_PER_PAGE));
        var start      = (omaPage - 1) * OMA_PER_PAGE;
        var slice      = allOma.slice(start, start + OMA_PER_PAGE);

        if ( ! total ) {
            $omaWrap.html(
                '<div class="smid-items-empty">'
                + '<span class="dashicons dashicons-video-alt3"></span>'
                + '<p>No assets yet.</p>'
                + '<button class="button button-primary smid-empty-add-oma">+ Add First Asset</button>'
                + '</div>'
            );
            return;
        }

        var html = '<div class="smid-items-header">'
            + '<span class="smid-items-count">' + total + ' asset' + (total !== 1 ? 's' : '') + '</span>'
            + '</div>'
            + '<table class="smid-items-table smid-oma-table"><thead><tr>'
            + '<th class="col-id">#</th>'
            + '<th>Asset Title</th>'
            + '<th>Brand</th>'
            + '<th>Category</th>'
            + '<th>Source</th>'
            + '<th>Useable Media</th>'
            + '<th class="col-pairs">Artist / Song / Album</th>'
            + '<th>Keywords</th>'
            + '<th class="col-date">Pub Date</th>'
            + '<th class="col-url">URL</th>'
            + '<th class="col-actions">Actions</th>'
            + '</tr></thead><tbody>';

        slice.forEach(function (r) {
            var keywords = '';
            if ( r.keywords ) {
                try {
                    var tags = JSON.parse(r.keywords);
                    if ( Array.isArray(tags) ) {
                        keywords = tags.map(function (t) {
                            return '<span class="smid-kw-tag">' + esc(t) + '</span>';
                        }).join('');
                    } else {
                        keywords = esc(r.keywords);
                    }
                } catch(e) { keywords = esc(r.keywords); }
            }
            var shortUrl = r.public_url ? r.public_url.replace(/^https?:\/\//,'').substring(0,30) + (r.public_url.length > 33 ? '…' : '') : '—';

            // Resolve names
            var brandName = '—', catName = '—', artistName = '—', songTitleVal = '—';
            var bMatch = omaBrands.filter(function(b){ return String(b.id) === String(r.brand_id); });
            if ( bMatch.length ) brandName = bMatch[0].name;
            var cMatch = omaCats.filter(function(c){ return String(c.id) === String(r.asset_category_id); });
            if ( cMatch.length ) catName = cMatch[0].name;

            // Build pairs display HTML — show first 2 inline, always show View button
            var pairs      = (r.pairs && Array.isArray(r.pairs)) ? r.pairs : [];
            var pairsCount = pairs.length;
            var pairsHtml  = '<span class="smid-no-pairs">—</span>';
            var detailHtml = '';

            function buildPairLine(p) {
                var am    = omaArtists.filter(function(a){ return String(a.id) === String(p.aId); });
                var aName = am.length ? am[0].name : ('Artist #' + p.aId);
                return '<div class="smid-pair-row-display">'
                    + '<span class="smid-pd-artist">' + esc(aName) + '</span>'
                    + '<span class="smid-pd-song">' + esc(p.sName || '—') + '</span>'
                    + '<span class="smid-pd-album">' + esc(p.albumName || '—') + '</span>'
                    + '</div>';
            }

            if ( pairsCount > 0 ) {
                var visiblePairs = pairs.slice(0, 2);
                pairsHtml = '<div class="smid-pairs-col-header">'
                    + '<span>Artist</span><span>Song Title</span><span>Album</span>'
                    + '</div>'
                    + visiblePairs.map(buildPairLine).join('');
                if ( pairsCount > 2 ) {
                    pairsHtml += '<span class="smid-pairs-more">+' + (pairsCount - 2) + ' more</span>';
                }
            }

            html += '<tr class="smid-asset-row" data-oma-id="' + esc(r.id) + '">'
                + '<td class="col-id"><span class="smid-id-badge">' + esc(r.id) + '</span></td>'
                + '<td class="col-asset-title">' + esc(r.asset_title || '—') + '</td>'
                + '<td><span class="smid-item-name">' + esc(brandName) + '</span></td>'
                + '<td>' + esc(catName) + '</td>'
                + '<td>' + esc(r.source || '—') + '</td>'
                + '<td>' + esc(r.useable_media || '—') + '</td>'
                + '<td class="col-pairs">' + pairsHtml + '</td>'
                + '<td class="col-keywords">' + ( keywords || '<span style="color:#aaa;">—</span>' ) + '</td>'
                + '<td class="col-date"><span class="smid-date-val">' + esc((r.pub_date || '—').substring(0,10)) + '</span></td>'
                + '<td class="col-url">'
                + ( r.public_url ? '<a href="' + esc(r.public_url) + '" target="_blank" title="' + esc(r.public_url) + '" class="smid-url-link">' + esc(shortUrl) + '</a>' : '—' )
                + '</td>'
                + '<td class="col-actions"><div class="smid-actions">'
                + '<button class="smid-action-btn smid-action-view smid-btn-view-oma" data-id="' + esc(r.id) + '"><span class="dashicons dashicons-visibility"></span> View</button>'
                + '<button class="smid-action-btn smid-action-edit smid-btn-edit-oma" data-id="' + esc(r.id) + '" data-record=\'' + JSON.stringify(r).replace(/'/g, "&#39;") + '\'><span class="dashicons dashicons-edit"></span> Edit</button>'
                + '<button class="smid-action-btn smid-action-delete smid-btn-delete-oma" data-id="' + esc(r.id) + '"><span class="dashicons dashicons-trash"></span> Delete</button>'
                + '</div></td></tr>';
        });

        html += '</tbody></table>' + buildPagination(omaPage, totalPages, total, 'oma');
        $omaWrap.html(html);
    }

    // ── View Details Modal ────────────────────────────────────────
    $(document).on('click', '.smid-btn-view-oma', function () {
        var id = $(this).data('id');
        var r  = null;
        allOma.forEach(function (rec) { if ( String(rec.id) === String(id) ) r = rec; });
        if ( ! r ) return;

        $('#smid-view-modal-title').text( esc(r.asset_title || 'Asset #' + id) );
        $('#smid-view-modal-body').html( buildViewModalContent(r) );
        $('#smid-view-modal').fadeIn(180);
    });

    $('#smid-view-modal-close').on('click', function () { $('#smid-view-modal').fadeOut(180); });
    $('#smid-view-modal').on('click', function (e) {
        if ( $(e.target).is('#smid-view-modal') ) $('#smid-view-modal').fadeOut(180);
    });

    function buildViewModalContent(r) {
        var brandName = '—', catName = '—';
        var bMatch = omaBrands.filter(function(b){ return String(b.id) === String(r.brand_id); });
        if ( bMatch.length ) brandName = bMatch[0].name;
        var cMatch = omaCats.filter(function(c){ return String(c.id) === String(r.asset_category_id); });
        if ( cMatch.length ) catName = cMatch[0].name;

        var keywords = '';
        if ( r.keywords ) {
            try {
                var tags = JSON.parse(r.keywords);
                if ( Array.isArray(tags) ) {
                    keywords = tags.map(function(t){ return '<span class="smid-kw-tag">' + esc(t) + '</span>'; }).join('');
                }
            } catch(e) { keywords = esc(r.keywords); }
        }

        var pairs = (r.pairs && Array.isArray(r.pairs)) ? r.pairs : [];
        var html  = '<div class="smid-vm-wrap">';

        // ── Meta grid ─────────────────────────────────────────────
        html += '<div class="smid-vm-grid">';
        html += vmField( 'Brand',         brandName );
        html += vmField( 'Category',      catName );
        html += vmField( 'Source',        r.source        || '—' );
        html += vmField( 'Useable Media', r.useable_media || '—' );
        html += vmField( 'Published Date', (r.pub_date || '—').substring(0, 10) );
        html += '<div class="smid-vm-field smid-vm-field-full"><span class="smid-vm-label">Public URL</span><span class="smid-vm-value">'
            + ( r.public_url
                ? '<a href="' + esc(r.public_url) + '" target="_blank" class="smid-vm-url">' + esc(r.public_url) + '</a>'
                : '—' )
            + '</span></div>';
        html += '<div class="smid-vm-field smid-vm-field-full"><span class="smid-vm-label">Keywords</span><span class="smid-vm-value smid-vm-kws">'
            + ( keywords || '<span style="color:#aaa;">—</span>' ) + '</span></div>';
        html += '</div>';

        // ── Artist / Song / Album table ───────────────────────────
        if ( pairs.length ) {
            html += '<div class="smid-vm-section">'
                + '<h4 class="smid-vm-section-title"><span class="dashicons dashicons-format-audio"></span> Artist / Song / Album</h4>'
                + '<table class="smid-pairs-mini-table smid-vm-pairs-table">'
                + '<thead><tr><th>#</th><th>Artist</th><th>Song Title</th><th>Album</th></tr></thead><tbody>';
            pairs.forEach(function(p, i) {
                var am    = omaArtists.filter(function(a){ return String(a.id) === String(p.aId); });
                var aName = am.length ? am[0].name : ('Artist #' + p.aId);
                html += '<tr>'
                    + '<td class="smid-pd-idx">' + (i + 1) + '</td>'
                    + '<td><span class="smid-pd-artist">' + esc(aName) + '</span></td>'
                    + '<td><span class="smid-pd-song">'   + esc(p.sName    || '—') + '</span></td>'
                    + '<td><span class="smid-pd-album">'  + esc(p.albumName || '—') + '</span></td>'
                    + '</tr>';
            });
            html += '</tbody></table></div>';
        }

        // ── Select Quote ──────────────────────────────────────────
        if ( r.select_quote_selection ) {
            html += '<div class="smid-vm-section">'
                + '<h4 class="smid-vm-section-title"><span class="dashicons dashicons-format-quote"></span> Select Quote</h4>'
                + '<blockquote class="smid-vm-quote">' + esc(r.select_quote_selection) + '</blockquote>'
                + '</div>';
        }

        // ── Full Text Transcript ──────────────────────────────────
        if ( r.full_text_transcript ) {
            html += '<div class="smid-vm-section">'
                + '<h4 class="smid-vm-section-title"><span class="dashicons dashicons-editor-alignleft"></span> Full Text Transcript</h4>'
                + '<div class="smid-vm-transcript">' + esc(r.full_text_transcript) + '</div>'
                + '</div>';
        }

        html += '</div>';
        return html;
    }

    function vmField(label, value) {
        return '<div class="smid-vm-field"><span class="smid-vm-label">' + label + '</span><span class="smid-vm-value">' + esc(value) + '</span></div>';
    }

    // Pagination
    $(document).on('click', '.smid-pg-btn[data-list="oma"]', function () {
        if ( $(this).prop('disabled') ) return;
        omaPage = parseInt( $(this).data('page') );
        renderOmaPage();
    });

    // Empty state button
    $(document).on('click', '.smid-empty-add-oma', function () {
        $('#smid-open-oma-modal').trigger('click');
    });

    // ── Tag input ─────────────────────────────────────────────────
    var omaTagList = [];

    function renderTags() {
        var $container = $('#smid-tags-container');
        $container.empty();
        omaTagList.forEach(function (tag, idx) {
            $container.append(
                '<span class="smid-tag">' + esc(tag)
                + '<button type="button" class="smid-tag-remove" data-idx="' + idx + '">&times;</button>'
                + '</span>'
            );
        });
        $('#smid-oma-keywords').val( JSON.stringify(omaTagList) );
        if ( omaTagList.length ) { $('#smid-tag-wrap').removeClass('smid-kw-error'); }
    }

    $(document).on('keydown', '#smid-tag-input', function (e) {
        if ( e.key === 'Enter' || e.key === ',' ) {
            e.preventDefault();
            var val = $(this).val().trim().replace(/,$/, '');
            if ( val && omaTagList.indexOf(val) === -1 ) {
                omaTagList.push(val);
                renderTags();
            }
            $(this).val('');
            $('#smid-kw-suggestions').hide().empty();
        }
    });

    // Keywords autosuggest on input
    $(document).on('input', '#smid-tag-input', function () {
        var q   = $(this).val().trim().toLowerCase();
        var $sg = $('#smid-kw-suggestions');
        if ( ! q || q.length < 2 ) { $sg.hide().empty(); return; }
        var matches = omaExistingKws.filter(function (kw) {
            return kw.toLowerCase().indexOf(q) !== -1 && omaTagList.indexOf(kw) === -1;
        }).slice(0, 8);
        if ( ! matches.length ) { $sg.hide().empty(); $('#smid-tag-wrap').removeClass('smid-kw-open'); return; }
        $sg.empty();
        matches.forEach(function (kw) {
            $sg.append('<div class="smid-kw-sugg-item" data-kw="' + esc(kw) + '">' + esc(kw) + '</div>');
        });
        $sg.show();
        $('#smid-tag-wrap').addClass('smid-kw-open');
    });

    // Click a suggestion to add it as tag
    $(document).on('click', '.smid-kw-sugg-item', function () {
        var kw = $(this).data('kw');
        if ( kw && omaTagList.indexOf(kw) === -1 ) {
            omaTagList.push(kw);
            renderTags();
        }
        $('#smid-tag-input').val('');
        $('#smid-kw-suggestions').hide().empty();
        $('#smid-tag-wrap').removeClass('smid-kw-open');
    });

    // Clear pub date error on change
    $(document).on('change', '#smid-oma-pubdate', function () {
        if ( $(this).val() ) { $(this).removeClass('smid-field-error'); }
    });

    // Select Quote character counter
    $(document).on('input', '#smid-oma-select-quote-selection', function () {
        var len = $(this).val().length;
        var $counter = $('#smid-quote-char-count');
        $counter.text(len);
        if ( len > 280 ) {
            $counter.addClass('smid-char-over');
        } else {
            $counter.removeClass('smid-char-over');
        }
    });

    // Hide suggestions on blur
    $(document).on('blur', '#smid-tag-input', function () {
        setTimeout(function () {
            $('#smid-kw-suggestions').hide();
            $('#smid-tag-wrap').removeClass('smid-kw-open');
        }, 200);
    });

    $(document).on('click', '.smid-tag-remove', function () {
        var idx = parseInt( $(this).data('idx') );
        omaTagList.splice(idx, 1);
        renderTags();
    });

    // ── Open modal (Add) ─────────────────────────────────────────
    $(document).on('click', '#smid-open-oma-modal', function () {
        resetOmaModal();
        $('#smid-oma-modal-title').text('Add New Asset');
        $('#smid-oma-submit').text('Add Asset');
        $('#smid-oma-modal').fadeIn(180);
    });

    // ── Open modal (Edit) ─────────────────────────────────────────
    $(document).on('click', '.smid-btn-edit-oma', function () {
        var r;
        try { r = JSON.parse( $(this).attr('data-record').replace(/&#39;/g, "'") ); } catch(e) { return; }
        resetOmaModal();

        $('#smid-oma-modal-title').text('Edit Asset');
        $('#smid-oma-submit').text('Update Asset');
        $('#smid-oma-id').val(r.id);
        $('#smid-oma-title').val(r.asset_title || '');
        $('#smid-oma-url').val(r.public_url || '');
        $('#smid-oma-pubdate').val( (r.pub_date || '').substring(0, 10) );

        // Brand / category
        if ( $.fn.select2 ) {
            $('#smid-oma-brand').val(r.brand_id || '').trigger('change');
            $('#smid-oma-category').val(r.asset_category_id || '').trigger('change');
        } else {
            $('#smid-oma-brand').val(r.brand_id || '');
            $('#smid-oma-category').val(r.asset_category_id || '');
        }

        // Source / Useable Media
        $('#smid-oma-source').val(r.source || '');
        $('#smid-oma-useable-media').val(r.useable_media || '');

        // Full Text Transcript / Select Quote
        $('#smid-oma-full-text-transcript').val(r.full_text_transcript || '');
        var quoteVal = r.select_quote_selection || '';
        $('#smid-oma-select-quote-selection').val(quoteVal);
        $('#smid-quote-char-count').text(quoteVal.length);

        // Resolve artist+song pairs — use r.pairs (index-aligned, no dedup bug)
        var editPairs = [];
        if ( r.pairs && Array.isArray(r.pairs) && r.pairs.length ) {
            r.pairs.forEach(function (p) {
                if ( ! p.aId || parseInt(p.aId) === 0 ) return;
                editPairs.push({ aId: String(p.aId), aName: '', sId: p.sId ? String(p.sId) : null, albumId: p.albumId || 0, albumName: p.albumName || '' });
            });
        } else {
            // Fallback: old flat arrays
            var aIdsArr = Array.isArray(r.artist_ids) ? r.artist_ids : [];
            var sIdsArr = Array.isArray(r.song_ids)   ? r.song_ids   : [];
            aIdsArr.forEach(function (aId, idx) {
                if ( ! aId || parseInt(aId) === 0 ) return;
                editPairs.push({ aId: String(aId), aName: '', sId: sIdsArr[idx] ? String(sIdsArr[idx]) : null });
            });
        }

        // Re-init pairs with edit data (resetOmaModal already called initPairs with 1 empty row)
        if ( editPairs.length ) {
            pairsState = { rows: [], counter: 0, cache: pairsState.cache || {} };
            $('#smid-oma-pairs').empty();
            editPairs.forEach(function (p) {
                addPairRow(p.aId, p.aName, p.sId, p.albumId || null, p.albumName || null);
            });
        }

        // Tags
        try {
            omaTagList = r.keywords ? JSON.parse(r.keywords) : [];
            if ( ! Array.isArray(omaTagList) ) omaTagList = [];
        } catch(e) { omaTagList = []; }
        renderTags();

        $('#smid-oma-modal').fadeIn(180);
    });

    function resetOmaModal() {
        $('#smid-oma-id').val('');
        $('#smid-oma-title').val('');
        $('#smid-oma-url').val('');
        if ( $.fn.select2 ) {
            $('#smid-oma-brand').val(null).trigger('change');
            $('#smid-oma-category').val(null).trigger('change');
        } else {
            $('#smid-oma-brand').val('');
            $('#smid-oma-category').val('');
        }
        $('#smid-oma-source').val('');
        $('#smid-oma-useable-media').val('');
        $('#smid-oma-full-text-transcript').val('');
        $('#smid-oma-select-quote-selection').val('');
        $('#smid-quote-char-count').text('0');
        initPairs();
        // Default pub date to today
        var now     = new Date();
        var dateStr = now.getFullYear() + '-'
            + String(now.getMonth() + 1).padStart(2, '0') + '-'
            + String(now.getDate()).padStart(2, '0');
        $('#smid-oma-pubdate').val(dateStr);
        omaTagList = [];
        renderTags();
        $('#smid-tag-input').val('');
        $('#smid-kw-suggestions').hide().empty();
        $('#smid-oma-notice').hide();
    }

    // ── Close modal ───────────────────────────────────────────────
    function closeOmaModal() {
        // Clean up any orphaned Select2 dropdowns appended to body
        $('body > .select2-container--open').remove();
        $('body > .select2-dropdown').remove();
        $('#smid-oma-modal').fadeOut(180);
    }
    $('#smid-oma-modal-close, #smid-oma-modal-cancel').on('click', closeOmaModal);
    $('#smid-oma-modal').on('click', function (e) {
        if ( $(e.target).is('#smid-oma-modal') ) closeOmaModal();
    });

    // ── Submit ────────────────────────────────────────────────────
    $(document).on('submit', '#smid-oma-form', function (e) {
        e.preventDefault();
        var $btn     = $('#smid-oma-submit');
        var $spinner = $('.smid-saving-spinner', '#smid-oma-modal');
        var id       = $('#smid-oma-id').val();
        var url      = $('#smid-oma-url').val().trim();

        if ( ! url ) { showOmaNotice('Public URL is required.', 'error'); return; }
        var urlRegex = /^(https?:\/\/)?(www\.)[\w\-]+(\.[\w\-]+)+([\/?#].*)?$|^https?:\/\/[\w\-]+(\.[\w\-]+)+([\/?#].*)?$/i;
        if ( ! urlRegex.test(url) ) { showOmaNotice('Please enter a valid URL (e.g. www.youtube.com or https://youtube.com/watch?v=abc).', 'error'); return; }

        var brandId = $('#smid-oma-brand').val();
        var catId   = $('#smid-oma-category').val();
        if ( ! brandId ) { showOmaNotice('Brand is required.', 'error'); return; }
        if ( ! catId )   { showOmaNotice('Asset Category is required.', 'error'); return; }

        // Artist / Song fully optional — clear any leftover error highlights only
        $('.smid-pair-field-error').removeClass('smid-pair-field-error');

        // Keywords required
        if ( ! omaTagList.length ) {
            $('#smid-tag-wrap').addClass('smid-kw-error');
            showOmaNotice('At least one keyword is required.', 'error');
            return;
        }
        $('#smid-tag-wrap').removeClass('smid-kw-error');

        // Pub date required
        var pubDate = $('#smid-oma-pubdate').val().trim();
        if ( ! pubDate ) {
            $('#smid-oma-pubdate').addClass('smid-field-error');
            showOmaNotice('Published Date is required.', 'error');
            return;
        }
        $('#smid-oma-pubdate').removeClass('smid-field-error');

        // Collect artist+song+album pairs
        var pairsArr = [], artistIdsArr = [], songIdsArr = [];
        pairsState.rows.forEach(function (row) {
            if ( ! row.artistId ) return;
            var aM    = omaArtists.filter(function (a) { return String(a.id) === String(row.artistId); });
            var aName = aM.length ? aM[0].name : '';
            var $sOpt = $('.smid-pair-song-sel[data-row="' + row.id + '"] option[value="' + row.songId + '"]');
            var sName = $sOpt.length ? $sOpt.text() : '';
            pairsArr.push({
                artistId   : parseInt(row.artistId),
                artistName : aName,
                songId     : row.songId ? parseInt(row.songId) : 0,
                songName   : sName,
                albumId    : row.albumId ? parseInt(row.albumId) : 0,
                albumName  : (row.albumName || '').trim(),
            });
            artistIdsArr.push({ id: parseInt(row.artistId), name: aName });
            if ( row.songId ) songIdsArr.push({ id: parseInt(row.songId), name: sName });
        });
        var primaryArtistId = artistIdsArr.length ? artistIdsArr[0].id : 0;
        var primarySongId   = songIdsArr.length   ? songIdsArr[0].id   : 0;

        $btn.prop('disabled', true).hide();
        $spinner.show();
        $('#smid-oma-notice').hide();

        $.post(smidAjax.ajaxurl, {
            action      : 'smid_save_oma_record',
            nonce       : smidAjax.nonce,
            oma_id      : id,
            asset_title : $('#smid-oma-title').val().trim(),
            public_url  : url,
            brand_id    : brandId,
            cat_id      : catId,
            artist_id   : primaryArtistId,
            song_id     : primarySongId,
            pairs       : JSON.stringify(pairsArr),
            artist_ids  : JSON.stringify(artistIdsArr),
            song_ids    : JSON.stringify(songIdsArr),
            pub_date       : $('#smid-oma-pubdate').val(),
            keywords       : $('#smid-oma-keywords').val(),
            source                  : $('#smid-oma-source').val(),
            useable_media           : $('#smid-oma-useable-media').val(),
            full_text_transcript    : $('#smid-oma-full-text-transcript').val(),
            select_quote_selection  : $('#smid-oma-select-quote-selection').val(),
        }, function (res) {
            $btn.prop('disabled', false).show();
            $spinner.hide();
            if ( res.success ) {
                $('#smid-oma-modal').fadeOut(180);
                loadOmaRecords();
            } else {
                showOmaNotice(res.data.message, 'error');
            }
        }).fail(function () {
            $btn.prop('disabled', false).show();
            $spinner.hide();
            showOmaNotice('Request failed. Please try again.', 'error');
        });
    });

    function showOmaNotice(msg, type) {
        $('#smid-oma-notice')
            .removeClass('notice-success notice-error')
            .addClass(type === 'error' ? 'notice-error' : 'notice-success')
            .text(msg).show();
    }

    // ── Delete ────────────────────────────────────────────────────
    $(document).on('click', '.smid-btn-delete-oma', function () {
        if ( ! confirm('Delete this asset? This cannot be undone.') ) return;
        var btn = $(this);
        var id  = btn.data('id');
        btn.prop('disabled', true);

        $.post(smidAjax.ajaxurl, {
            action : 'smid_delete_oma_record',
            nonce  : smidAjax.nonce,
            oma_id : id,
        }, function (res) {
            if ( res.success ) {
                allOma = allOma.filter(function (r) { return r.id != id; });
                if ( omaPage > Math.max(1, Math.ceil(allOma.length / OMA_PER_PAGE)) ) omaPage--;
                renderOmaPage();
            } else {
                alert(res.data.message);
                btn.prop('disabled', false);
            }
        });
    });

    // ── Escape HTML ───────────────────────────────────────────────
    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})(jQuery);
