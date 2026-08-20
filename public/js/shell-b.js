/*
 * Application shell — AJAX defaults, notification bell, bulk actions, search
 *
 * Extracted from layouts/app.blade.php so it is cached once instead of
 * re-sent inside every page. Values that vary per request or per session
 * (CSRF token, route URLs, asset paths) are read from window.DFCP, which
 * the layout emits inline just before this file loads.
 *
 * Edit this file directly; the layout busts the cache from its mtime.
 */

        $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': window.DFCP.csrf } });

        // ── Global "unauthorized" handler ───────────────────────────────────
        // Catches every 403 from any AJAX call app-wide, even ones whose own
        // .fail() handler doesn't specifically account for it — a consistent,
        // friendly message instead of a silent failure or raw error.
        $(document).ajaxError(function (event, xhr) {
            if (xhr.status === 403) {
                Swal.fire({
                    icon: 'error',
                    title: 'Unauthorized',
                    text: xhr.responseJSON?.message || "You don't have permission to do this.",
                });
            }
        });

        // ── Dark mode ──────────────────────────────────────────────────────
        function updateDarkIcon() {
            var dark = document.documentElement.getAttribute('data-theme') === 'dark';
            document.getElementById('darkIcon').className = 'bi ' + (dark ? 'bi-sun' : 'bi-moon-stars');
        }
        updateDarkIcon();
        document.getElementById('darkToggle').addEventListener('click', function () {
            var dark = document.documentElement.getAttribute('data-theme') === 'dark';
            document.documentElement.setAttribute('data-theme', dark ? 'light' : 'dark');
            localStorage.setItem('dfcp_theme', dark ? 'light' : 'dark');
            updateDarkIcon();
        });

        // ── Notifications ──────────────────────────────────────────────────
        // Sound lives here rather than in the broadcast handler so it fires on both
        // paths — the live Reverb push and the 60s poll — without double-playing,
        // and so alerts still make a noise when Reverb is unreachable.
        var lastUnread = null;

        function loadNotifications() {
            $.get(window.DFCP.routes['notifications.index']).done(function (r) {
                if (lastUnread !== null && r.unread_count > lastUnread) {
                    if (window.AppSound) window.AppSound.notification();

                    // Also surface it at OS level, so it reaches the user with
                    // the browser minimised.
                    var newest = (r.notifications || [])[0];
                    if (newest && window.AppNotify) {
                        window.AppNotify.notify({
                            title: newest.title || 'New notification',
                            body: newest.message || '',
                            tag: 'notif-' + newest.id,
                            url: newest.url,
                        });
                    }
                }
                lastUnread = r.unread_count;

                $('#notifBadge').toggleClass('d-none', r.unread_count === 0).text(r.unread_count);
                if (!r.notifications.length) {
                    $('#notifList').html('<div class="text-center py-4 text-muted" style="font-size:.78rem">No notifications yet.</div>');
                    return;
                }
                var html = '';
                r.notifications.forEach(function (n) {
                    html += '<a href="' + n.url + '" class="d-block px-3 py-2 notif-item" data-id="' + n.id + '" '
                        + 'style="text-decoration:none;border-bottom:1px solid var(--border);' + (n.read ? '' : 'background:rgba(var(--primary-rgb),.05)') + '">'
                        + '<div style="font-size:.78rem;font-weight:600;color:var(--text)">' + n.title + '</div>'
                        + '<div style="font-size:.72rem;color:var(--text2)">' + n.message + '</div>'
                        + '<div style="font-size:.66rem;color:var(--text3)" class="mt-1">' + n.created_at + '</div>'
                        + '</a>';
                });
                $('#notifList').html(html);
            });
        }
        loadNotifications();
        setInterval(loadNotifications, 60000);

        $(document).on('click', '.notif-item', function () {
            $.post(window.DFCP.urls['notifications'] + '/' + $(this).data('id') + '/read');
        });

        $('#notifMarkAll').on('click', function (e) {
            e.stopPropagation();
            $.post(window.DFCP.routes['notifications.read-all']).done(loadNotifications);
        });

        // ── Sidebar collapse ───────────────────────────────────────────────
        const $sb = $('#sidebar'), $tb = $('#topbar'), $mn = $('#main');
        const isMobile = () => $(window).width() < 992;

        function applyCollapsed(collapsed, animate) {
            if (!animate) { $sb.css('transition', 'none'); $tb.css('transition', 'none'); $mn.css('transition', 'none'); }
            collapsed ? $sb.addClass('collapsed') : $sb.removeClass('collapsed');
            collapsed ? $tb.addClass('collapsed') : $tb.removeClass('collapsed');
            collapsed ? $mn.addClass('collapsed') : $mn.removeClass('collapsed');
            $sb.find('[data-bs-toggle="tooltip"]').each(function () {
                var inst = bootstrap.Tooltip.getInstance(this);
                if (collapsed) { if (!inst) bootstrap.Tooltip.getOrCreateInstance(this, { trigger: 'hover' }); }
                else { inst?.dispose(); }
            });
            if (!animate) requestAnimationFrame(() => { $sb.css('transition', ''); $tb.css('transition', ''); $mn.css('transition', ''); });
        }

        if (!isMobile()) applyCollapsed(localStorage.getItem('sidebar_collapsed') === '1', false);

        $('#sidebarToggle').on('click', function () {
            if (isMobile()) { $sb.toggleClass('open'); }
            else {
                const now = !$sb.hasClass('collapsed');
                applyCollapsed(now, true);
                localStorage.setItem('sidebar_collapsed', now ? '1' : '0');
            }
        });
        $('#sidebarOverlay').on('click', () => $sb.removeClass('open'));
        $(window).on('resize', function () {
            if (isMobile()) { $tb.removeClass('collapsed'); $mn.removeClass('collapsed'); }
            else { $sb.removeClass('open'); applyCollapsed(localStorage.getItem('sidebar_collapsed') === '1', false); }
        });

        // ── Quick View Drawer ──────────────────────────────────────────────
        const spColors = { Running: 'spill-running', Warning: 'spill-warning', Completed: 'spill-completed', Hold: 'spill-hold', Cancelled: 'spill-cancelled', Terminated: 'spill-terminated' };

        function openDrawer(clientId) {
            $('#qvDrawer').addClass('open');
            $('#qvBackdrop').addClass('show');
            $('#qvBody').html('<div class="text-center py-5"><div class="spinner-border spinner-border-sm" style="color:var(--text3)"></div></div>');
            $('#qvName').text('Loading…');
            $('#qvDfid, #qvStatus').html('');
            $('#qvAvatar').text('…');

            $.get('/clients/' + clientId + '/quick-view').done(function (d) {
                var initials = d.name.split(' ').slice(0, 2).map(w => w[0] || '').join('').toUpperCase();
                $('#qvAvatar').text(initials);
                $('#qvName').text(d.name);
                $('#qvDfid').text(d.dfid);
                $('#qvStatus').html('<span class="spill ' + (spColors[d.status] || 'spill-hold') + '">' + d.status + '</span>');
                $('#qvEditLink').attr('href', d.edit_url);

                var acts = '';
                if (d.activities && d.activities.length) {
                    acts = '<div class="tl">';
                    d.activities.forEach(function (a) {
                        acts += '<div class="tl-item"><div class="tl-dot done"></div>'
                            + '<div style="font-size:.75rem;color:var(--text)">' + $('<span>').text(a.action).html() + '</div>'
                            + '<div style="font-size:.67rem;color:var(--text3)">' + $('<span>').text(a.user).html() + ' · ' + a.time + '</div>'
                            + '</div>';
                    });
                    acts += '</div>';
                } else {
                    acts = '<div style="font-size:.75rem;color:var(--text3)">No activity yet.</div>';
                }

                var progressBar = '<div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height:5px">'
                    + '<div class="progress-bar" style="width:' + d.progress + '%;background:var(--primary)"></div>'
                    + '</div><span style="font-size:.72rem;color:var(--text2);white-space:nowrap">' + d.progress + '% (' + d.done_stages + '/' + d.total_stages + ')</span></div>';

                var html = '<div class="qv-sec">'
                    + '<div class="qv-sec-title">Info</div>'
                    + '<div class="qv-info-grid">'
                    + '<div class="qv-info-box"><div class="qv-info-lbl">Category</div><div class="qv-info-val">' + $('<span>').text(d.category || '—').html() + '</div></div>'
                    + '<div class="qv-info-box"><div class="qv-info-lbl">Joined</div><div class="qv-info-val">' + $('<span>').text(d.joined || '—').html() + '</div></div>'
                    + '<div class="qv-info-box"><div class="qv-info-lbl">Assigned To</div><div class="qv-info-val">' + $('<span>').text(d.assigned || '—').html() + '</div></div>'
                    + '<div class="qv-info-box"><div class="qv-info-lbl">Doc Status</div><div class="qv-info-val">' + $('<span>').text(d.doc_status || '—').html() + '</div></div>'
                    + '</div></div>'

                    + '<div class="qv-sec"><div class="qv-sec-title">Workflow Progress</div>' + progressBar + '</div>'

                    + (d.latest_update ? '<div class="qv-sec"><div class="qv-sec-title">Latest Product Update</div>'
                        + '<div class="qv-info-box"><div class="qv-info-val">' + $('<span>').text(d.latest_update.status).html() + '</div>'
                        + '<div class="qv-info-lbl">' + d.latest_update.time + '</div></div></div>' : '')

                    + (d.website ? '<div class="qv-sec"><div class="qv-sec-title">Website</div>'
                        + '<a href="' + $('<span>').text(d.website_url).html() + '" target="_blank" style="font-size:.78rem;color:var(--primary)" class="text-truncate d-block">' + $('<span>').text(d.website).html() + '</a></div>' : '')
                    + (d.designs_link ? '<div class="qv-sec"><div class="qv-sec-title">Designs</div>'
                        + '<a href="' + $('<span>').text(d.designs_link_url).html() + '" target="_blank" style="font-size:.78rem;color:var(--primary)" class="text-truncate d-block"><i class="bi bi-palette me-1"></i>' + $('<span>').text(d.designs_link).html() + '</a></div>' : '')

                    + '<div class="qv-sec"><div class="qv-sec-title">Recent Activity</div>' + acts + '</div>'

                    + '<div class="d-flex gap-2 flex-wrap mt-2">'
                    + '<a href="' + d.show_url + '" class="btn btn-primary btn-sm"><i class="bi bi-eye me-1"></i>Full Profile</a>'
                    + '<a href="' + d.edit_url + '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>'
                    + '</div>';

                $('#qvBody').html(html);
            }).fail(function () {
                $('#qvBody').html('<div class="text-center py-4 text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Failed to load.</div>');
            });
        }

        function closeDrawer() {
            $('#qvDrawer').removeClass('open');
            $('#qvBackdrop').removeClass('show');
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeDrawer();
        });

        // ── Bulk bar ───────────────────────────────────────────────────────
        function updateBulkBar() {
            var cnt = $('.row-check:checked').length;
            $('#bulkCount').text(cnt);
            cnt > 0 ? $('#bulkBar').addClass('show') : $('#bulkBar').removeClass('show');
        }
        $(document).on('change', '#selectAll', function () {
            $('.row-check').prop('checked', $(this).is(':checked'));
            updateBulkBar();
        });
        $(document).on('change', '.row-check', updateBulkBar);

        $('#bulkDeleteBtn').on('click', function () {
            var ids = $('.row-check:checked').map((i, el) => el.value).get();
            if (!ids.length) return;
            Swal.fire({ title: 'Delete ' + ids.length + ' clients?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' })
                .then(r => {
                    if (r.isConfirmed) {
                        $.post(window.DFCP.routes['clients.bulk-delete'], { ids })
                            .done(res => { Swal.fire('Deleted', res.message, 'success'); if (window.dfTable || window.table) (window.dfTable || window.table).ajax.reload(); });
                    }
                });
        });

        $('#bulkTerminateBtn').on('click', function () {
            var ids = $('.row-check:checked').map((i, el) => el.value).get();
            if (!ids.length) return;
            Swal.fire({
                title: 'Terminate ' + ids.length + ' clients?',
                text: 'This will permanently lock the workflow for these clients — no further stage progress will be possible.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Terminate'
            }).then(r => {
                if (r.isConfirmed) {
                    $.post(window.DFCP.routes['clients.bulk-terminate'], { ids })
                        .done(res => { Swal.fire('Terminated', res.message, 'success'); if (window.dfTable || window.table) (window.dfTable || window.table).ajax.reload(); })
                        .fail(xhr => Swal.fire('Error', xhr.responseJSON?.message || 'Could not terminate clients.', 'error'));
                }
            });
        });

        $('#bulkAssignBtn').on('click', function () {
            var ids = $('.row-check:checked').map((i, el) => el.value).get();
            if (!ids.length) return;
            if (!$('#bulkAssignOwner').data('populated')) {
                var opts = $('#filterUser option').filter(function () { return this.value && this.value !== 'none'; })
                    .map(function () { return this.outerHTML; }).get().join('');
                $('#bulkAssignOwner').html('<option value="">Select staff…</option>' + opts).data('populated', true);
            }
            $('#bulkAssignOwner').val('');
            $('#bulkAssignNote').val('');
            $('#bulkAssignCount').text(ids.length);
            new bootstrap.Modal('#bulkAssignModal').show();
        });

        $('#bulkAssignConfirm').on('click', function () {
            var ids = $('.row-check:checked').map((i, el) => el.value).get();
            var ownerId = $('#bulkAssignOwner').val();
            if (!ownerId) { Swal.fire('Select a staff member', '', 'warning'); return; }
            $.post(window.DFCP.routes['clients.bulk-assign'], { ids: ids, new_owner_id: ownerId, note: $('#bulkAssignNote').val() })
                .done(function (res) {
                    bootstrap.Modal.getInstance('#bulkAssignModal').hide();
                    Swal.fire('Assigned', res.message, 'success');
                    if (window.dfTable || window.table) (window.dfTable || window.table).ajax.reload();
                    $('#selectAll').prop('checked', false).trigger('change');
                })
                .fail(function (xhr) { Swal.fire('Error', xhr.responseJSON?.message || 'Failed to assign clients.', 'error'); });
        });

        // ── Export modal ───────────────────────────────────────────────────
        $('#exportSidebarBtn, #exportNavBtn').on('click', function (e) { e.preventDefault(); new bootstrap.Modal('#exportModal').show(); });
        $('#doExport').on('click', function () {
            var fmt = $('input[name="exportFmt"]:checked').val();
            var search = window._dtSearch || '';
            var status = window._dtStatus || '';
            var cat = window._dtCat || '';
            window.location = window.DFCP.routes['export.clients'] + '?format=' + fmt + '&search=' + encodeURIComponent(search) + '&status=' + encodeURIComponent(status) + '&category_id=' + cat;
            bootstrap.Modal.getInstance('#exportModal')?.hide();
        });

        // ── Global search ──────────────────────────────────────────────────
        let searchTimer;
        const $gsInput = $('#globalSearch');
        const $gsDrop = $('#searchDropdown');

        $gsInput.on('input', function () {
            clearTimeout(searchTimer);
            var q = $.trim($(this).val());
            if (q.length < 2) { $gsDrop.hide(); return; }
            searchTimer = setTimeout(function () {
                $.get(window.DFCP.routes['search.global'], { q: q }).done(function (results) {
                    if (!results.length) {
                        $gsDrop.html('<div class="px-3 py-3 text-center" style="font-size:.77rem;color:var(--text3)">No results</div>').show();
                        return;
                    }
                    var html = '';
                    results.forEach(function (r) {
                        var sc = spColors[r.client_status] || 'spill-hold';
                        html += '<a href="' + r.url + '" class="sr-item">'
                            + '<div class="flex-fill min-w-0">'
                            + '<div style="font-size:.77rem;font-weight:600;color:var(--text)" class="text-truncate">' + $('<span>').text(r.client_name).html() + '</div>'
                            + '<div style="font-size:.68rem;color:var(--text3)">' + $('<span>').text(r.dfid_number).html()
                            + (r.brand_name ? ' · ' + $('<span>').text(r.brand_name).html() : '') + '</div>'
                            + '</div><span class="spill ' + sc + '">' + r.client_status + '</span></a>';
                    });
                    html += '<a href="' + window.DFCP.routes['clients.index'] + '?search=' + encodeURIComponent(q) + '" style="display:block;text-align:center;padding:9px;font-size:.73rem;color:var(--primary);border-top:1px solid var(--border)">View all results →</a>';
                    $gsDrop.html(html).show();
                });
            }, 280);
        });

        $gsInput.on('keydown', function (e) {
            if (e.key === 'Enter') { var q = $.trim($(this).val()); if (q) window.location = window.DFCP.routes['clients.index'] + '?search=' + encodeURIComponent(q); }
            if (e.key === 'Escape') { $gsDrop.hide(); $(this).blur(); }
        });
        $(document).on('click', function (e) { if (!$(e.target).closest('.tb-search').length) $gsDrop.hide(); });
        $gsInput.on('focus', function () { if ($gsDrop.children().length) $gsDrop.show(); });

        // ── Keyboard shortcuts ─────────────────────────────────────────────
        var gPressed = false, gTimer;
        document.addEventListener('keydown', function (e) {
            var tag = document.activeElement.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || document.activeElement.isContentEditable) return;
            if (e.key === '/') { e.preventDefault(); document.getElementById('globalSearch').focus(); return; }
            if (e.key === 'Escape') { document.getElementById('globalSearch').blur(); $gsDrop.hide(); closeDrawer(); return; }
            if (e.key.toLowerCase() === 'g') { gPressed = true; clearTimeout(gTimer); gTimer = setTimeout(function () { gPressed = false; }, 1000); return; }
            if (gPressed) {
                if (e.key.toLowerCase() === 'd') window.location = window.DFCP.routes['dashboard'];
                if (e.key.toLowerCase() === 'c') window.location = window.DFCP.routes['clients.index'];
                gPressed = false;
            }
        });

        // Auto-dismiss success alerts
        setTimeout(function () { $('.alert-success').fadeOut(400, function () { $(this).remove(); }); }, 4000);

        // ── Chart.js theme helper ──────────────────────────────────────────
        window.chartTheme = function () {
            var dark = document.documentElement.getAttribute('data-theme') === 'dark';
            return {
                gridColor: dark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.05)',
                textColor: dark ? '#94A3B8' : '#94a3b8',
                borderColor: dark ? 'rgba(255,255,255,.08)' : '#e2e8f0',
                colors: dark
                    ? ['#3B82F6', '#22C55E', '#F59E0B', '#EF4444', '#A78BFA', '#06B6D4', '#F43F5E', '#FB923C', '#34D399', '#60A5FA']
                    : ['#2563eb', '#059669', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#e11d48', '#ea580c', '#10b981', '#3b82f6'],
            };
        };
        // Re-render charts when theme toggles
        document.getElementById('darkToggle').addEventListener('click', function () {
            if (window._charts) window._charts.forEach(function (c) { try { c.update(); } catch (e) { } });
        });

        // CSS cannot wrap an element, and 11 list pages render a bare <table>
        // with nothing around it to scroll. Wrapping them here fixes every such
        // page at once, including any added later.
        //
        // Runs on 'load' rather than DOMContentLoaded so DataTables has already
        // built its own wrapper — those are skipped and handled by CSS instead.
        window.addEventListener('load', function () {
            document.querySelectorAll('table').forEach(function (table) {
                if (table.closest('.table-responsive') || table.closest('.dataTables_wrapper')) return;

                var wrap = document.createElement('div');
                wrap.className = 'table-responsive';
                table.parentNode.insertBefore(wrap, table);
                wrap.appendChild(table);
            });
        });
