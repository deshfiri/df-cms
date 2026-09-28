@extends('layouts.app')
@section('title', 'Activity Log')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0">Activity Log</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Every traced movement across the system, in one place</div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold small mb-1">Module</label>
                <select id="logModule" class="form-select form-select-sm select2">
                    <option value="">All modules</option>
                    @foreach($modules as $m)
                    <option value="{{ $m }}">{{ $m }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold small mb-1">User</label>
                <select id="logUser" class="form-select form-select-sm select2">
                    <option value="">Everyone</option>
                    @foreach($users as $u)
                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">Action contains</label>
                <input type="text" id="logAction" class="form-control form-control-sm" placeholder="e.g. Deleted">
            </div>
            <div class="col-3 col-md-2">
                <label class="form-label fw-semibold small mb-1">From</label>
                <input type="date" id="logDateFrom" class="form-control form-control-sm">
            </div>
            <div class="col-3 col-md-2">
                <label class="form-label fw-semibold small mb-1">To</label>
                <input type="date" id="logDateTo" class="form-control form-control-sm">
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="activityLogTable" class="table table-hover align-middle w-100 mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Module</th>
                        <th>Action</th>
                        <th>User</th>
                        <th>Client</th>
                        <th>When</th>
                        <th>IP</th>
                        <th width="70" class="text-end pe-3">Details</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

{{-- Details Modal --}}
<div class="modal fade" id="viewLogModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold" id="viewLogTitle"></h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewLogBody"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// The browser's own zone (e.g. "Asia/Dhaka") — every timestamp on this page
// is rendered in it, never the server's own (UTC) clock.
const logTz = (Intl.DateTimeFormat().resolvedOptions() || {}).timeZone || '';

$(function () {
    $('#logModule, #logUser').select2({ theme: 'bootstrap-5', width: '100%' });

    const columns = [
        { data: 'DT_RowIndex', orderable: false, searchable: false },
        { data: 'module_badge', orderable: false, searchable: false },
        { data: 'action', orderable: false, searchable: false },
        { data: 'user', orderable: false, searchable: false },
        { data: 'client', orderable: false, searchable: false },
        { data: 'when', orderable: false, searchable: false },
        { data: 'ip', orderable: false, searchable: false },
        { data: 'details', orderable: false, searchable: false },
    ];

    window.logTable = $('#activityLogTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ route("activity-log.index") }}',
            data: function (d) {
                d.module    = $('#logModule').val();
                d.user_id   = $('#logUser').val();
                d.action    = $('#logAction').val();
                d.date_from = $('#logDateFrom').val();
                d.date_to   = $('#logDateTo').val();
                d.tz        = logTz;
            }
        },
        columns: columns,
        order: [],
        pageLength: 25,
        language: {
            processing: '<div class="d-flex align-items-center gap-2 justify-content-center py-3"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div><span style="font-size:.77rem;color:var(--text3)">Loading…</span></div>'
        },
        dom: '<"d-flex align-items-center justify-content-between px-3 py-2" lf>t<"d-flex align-items-center justify-content-between px-3 py-2 border-top" ip>',
    });

    $('#logModule, #logUser').on('change', () => window.logTable.ajax.reload());
    let logFilterTimer;
    $('#logAction').on('input', function () {
        clearTimeout(logFilterTimer);
        logFilterTimer = setTimeout(() => window.logTable.ajax.reload(), 350);
    });
    $('#logDateFrom, #logDateTo').on('change', () => window.logTable.ajax.reload());
});

$(document).on('click', '.log-view', function () {
    const id = $(this).data('id');
    $('#viewLogBody').html('<div class="text-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div></div>');
    new bootstrap.Modal('#viewLogModal').show();

    $.get('{{ url("activity-log") }}/' + id, { tz: logTz }).done(function (log) {
        $('#viewLogTitle').text(log.module + ' — ' + log.action);
        let html = '<div class="mb-2 small" style="color:var(--text3)">'
            + (log.user || 'System') + ' &middot; ' + log.created_at
            + (log.client ? ' &middot; ' + $('<div>').text(log.client).html() : '')
            + '</div>';
        if (log.ip_address) {
            html += '<div class="mb-2 small" style="color:var(--text3)">IP: ' + $('<div>').text(log.ip_address).html() + '</div>';
        }
        html += renderLogDetail(log.old_value, log.new_value);
        $('#viewLogBody').html(html);
    }).fail(function () {
        $('#viewLogBody').html('<div class="text-center py-4 small c-red"><i class="bi bi-exclamation-circle me-1"></i>Failed to load this entry.</div>');
    });
});

/** Field-by-field, human-readable — never a raw JSON dump. */
function renderLogDetail(oldRaw, newRaw) {
    const oldData = parseLogValue(oldRaw);
    const newData = parseLogValue(newRaw);
    const oldIsObject = oldData !== null && typeof oldData === 'object' && !Array.isArray(oldData);
    const newIsObject = newData !== null && typeof newData === 'object' && !Array.isArray(newData);

    if (!oldIsObject && !newIsObject) {
        // A plain scalar on one or both sides (e.g. a status change) — a
        // simple before/after line, not a table.
        if (oldRaw == null && newRaw == null) {
            return '<p class="text-muted small mb-0">No additional detail recorded for this entry.</p>';
        }
        let html = '';
        if (oldRaw != null) {
            html += '<div class="mb-1 small"><span style="color:var(--text3)">Before:</span> ' + escapeHtml(formatLogValue(oldData)) + '</div>';
        }
        if (newRaw != null) {
            html += '<div class="small"><span style="color:var(--text3)">After:</span> ' + escapeHtml(formatLogValue(newData)) + '</div>';
        }
        return html;
    }

    // One or both sides are a field => value object — render a field-by-field
    // table (Before/After when both exist, a single Value column otherwise)
    // instead of dumping the raw JSON.
    const oldFields = oldIsObject ? oldData : {};
    const newFields = newIsObject ? newData : {};
    const keys = Array.from(new Set([...Object.keys(oldFields), ...Object.keys(newFields)]));
    const showBoth = oldIsObject && newIsObject;

    let rows = '';
    keys.forEach(function (key) {
        const before = formatLogValue(oldFields[key]);
        const after = formatLogValue(newFields[key]);
        if (showBoth && before === after) {
            return; // unchanged field — not worth showing in a diff
        }
        // With only one real side (a Created/Deleted-style entry), show
        // whichever value actually exists — old for Deleted, new for Created.
        const soleValue = newIsObject ? after : before;
        rows += '<tr><td class="small fw-semibold" style="color:var(--text2);white-space:nowrap">' + escapeHtml(humanizeLogKey(key)) + '</td>'
            + (showBoth ? '<td class="small" style="color:var(--text3)">' + escapeHtml(before) + '</td>' : '')
            + '<td class="small">' + escapeHtml(showBoth ? after : soleValue) + '</td></tr>';
    });

    if (!rows) {
        return '<p class="text-muted small mb-0">No fields changed.</p>';
    }

    return '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>'
        + '<th class="small">Field</th>'
        + (showBoth ? '<th class="small">Before</th><th class="small">After</th>' : '<th class="small">Value</th>')
        + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
}

function parseLogValue(raw) {
    if (raw == null) return null;
    try {
        return JSON.parse(raw);
    } catch (e) {
        return raw; // a plain, non-JSON scalar
    }
}

function formatLogValue(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (Array.isArray(value)) return value.length ? value.join(', ') : '—';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

function humanizeLogKey(key) {
    return key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function escapeHtml(text) {
    return $('<div>').text(text).html();
}
</script>
@endpush
