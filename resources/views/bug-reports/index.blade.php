@extends('layouts.app')
@section('title', 'Bug Reports')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0">Bug Reports</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Report something broken in the system</div>
    </div>
    @if($canCreate)
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newBugModal">
            <i class="bi bi-bug me-1"></i>Report a Bug
        </button>
    @endif
</div>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <button class="fpill" data-status="" id="pillAll">All</button>
    <button class="fpill" data-status="Open">
        <span class="spill spill-pending" style="padding:1px 7px;font-size:.65rem">Open</span>
    </button>
    <button class="fpill" data-status="Resolved">
        <span class="spill spill-approved" style="padding:1px 7px;font-size:.65rem">Resolved</span>
    </button>
    <button class="fpill" data-status="Closed">
        <span class="spill spill-rejected" style="padding:1px 7px;font-size:.65rem">Closed</span>
    </button>
    @if($canManage)
    <button class="fpill ms-auto" id="pillMine">
        <i class="bi bi-person me-1" style="font-size:.67rem"></i>Mine Only
    </button>
    @endif
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="bugReportsTable" class="table table-hover align-middle w-100 mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Subject</th>
                        @if($canManage)
                        <th>Reported By</th>
                        @endif
                        <th>Severity</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th width="160" class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

{{-- New Bug Report Modal --}}
<div class="modal fade" id="newBugModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold">Report a Bug</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Subject <span class="text-danger">*</span></label>
                    <input type="text" id="bugSubject" class="form-control" placeholder="Short summary of the problem">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">What happened? <span class="text-danger">*</span></label>
                    <textarea id="bugMessage" class="form-control" rows="4" placeholder="What you did, what you expected, and what actually happened..."></textarea>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold small">Severity</label>
                        <select id="bugSeverity" class="form-select">
                            @foreach(\App\Models\BugReport::$severities as $s)
                            <option value="{{ $s }}" {{ $s === 'Medium' ? 'selected' : '' }}>{{ $s }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold small">Page (optional)</label>
                        <input type="text" id="bugPageUrl" class="form-control" placeholder="Which page?">
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="saveBugReport" class="btn btn-sm btn-primary"><i class="bi bi-send me-1"></i>Submit</button>
            </div>
        </div>
    </div>
</div>

{{-- View Bug Report Modal --}}
<div class="modal fade" id="viewBugModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold" id="viewBugSubject"></h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewBugBody"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const canManageBugReports = @json($canManage);
let activeBugStatus = '';
let mineOnlyBugs = false;

$('.fpill[data-status]').on('click', function () {
    activeBugStatus = $(this).data('status');
    $('.fpill[data-status]').removeClass('active');
    $(this).addClass('active');
    if (window.bugTable) window.bugTable.ajax.reload();
});
$('#pillAll').addClass('active');

$('#pillMine').on('click', function () {
    mineOnlyBugs = !mineOnlyBugs;
    $(this).toggleClass('active');
    if (window.bugTable) window.bugTable.ajax.reload();
});

$(function () {
    // Prefills the page field with wherever the person actually came from,
    // so most reports never need it typed by hand.
    $('#bugPageUrl').val(document.referrer && !document.referrer.includes('/bug-reports') ? document.referrer : '');

    const columns = [
        { data: 'DT_RowIndex', orderable: false, searchable: false },
        { data: 'subject',     orderable: false, searchable: false },
    ];
    if (canManageBugReports) {
        columns.push({ data: 'reporter', orderable: false, searchable: false });
    }
    columns.push(
        { data: 'severity_badge', orderable: false, searchable: false },
        { data: 'status_badge',   orderable: false, searchable: false },
        { data: 'created',        orderable: false, searchable: false },
        { data: 'actions',        orderable: false, searchable: false }
    );

    window.bugTable = $('#bugReportsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ route("bug-reports.index") }}',
            data: function (d) {
                d.status    = activeBugStatus;
                d.mine_only = mineOnlyBugs ? 1 : 0;
            }
        },
        columns: columns,
        order: [[columns.length - 2, 'desc']],
        pageLength: 25,
        language: {
            processing: '<div class="d-flex align-items-center gap-2 justify-content-center py-3"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div><span style="font-size:.77rem;color:var(--text3)">Loading…</span></div>'
        },
        dom: '<"d-flex align-items-center justify-content-between px-3 py-2" lf>t<"d-flex align-items-center justify-content-between px-3 py-2 border-top" ip>',
    });
});

$('#newBugModal').on('hidden.bs.modal', function () {
    $('#bugSubject,#bugMessage,#bugPageUrl').val('');
    $('#bugSeverity').val('Medium');
});

$('#saveBugReport').on('click', function () {
    const subject = $('#bugSubject').val().trim();
    const message = $('#bugMessage').val().trim();

    if (!subject || !message) {
        Swal.fire('Missing', 'Subject and description are required.', 'warning');
        return;
    }

    $.post('{{ route("bug-reports.store") }}', {
        subject: subject,
        message: message,
        severity: $('#bugSeverity').val(),
        page_url: $('#bugPageUrl').val().trim() || null,
    }).done(function () {
        bootstrap.Modal.getInstance('#newBugModal').hide();
        window.bugTable.ajax.reload();
        Swal.fire({ icon: 'success', title: 'Thanks — report submitted', timer: 1200, showConfirmButton: false });
    }).fail(function (xhr) {
        Swal.fire('Error', xhr.responseJSON?.message || 'Could not submit the report.', 'error');
    });
});

$(document).on('click', '.bug-resolve', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Mark this as resolved?', input: 'text', inputPlaceholder: 'Note (optional)', icon: 'question', showCancelButton: true, confirmButtonText: 'Resolve' })
    .then(function (r) {
        if (!r.isConfirmed) return;
        $.post('{{ url("bug-reports") }}/' + id + '/respond', { status: 'Resolved', note: r.value || '' })
        .done(function () { window.bugTable.ajax.reload(); Swal.fire({ icon: 'success', title: 'Resolved', timer: 1200, showConfirmButton: false }); })
        .fail(function (x) { Swal.fire('Error', x.responseJSON?.message || 'Could not resolve.', 'error'); });
    });
});

$(document).on('click', '.bug-close', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Close this report?', text: 'For a duplicate, or something that isn\'t actually a bug.', input: 'text', inputPlaceholder: 'Reason (optional)', icon: 'warning', showCancelButton: true, confirmButtonText: 'Close', confirmButtonColor: '#dc3545' })
    .then(function (r) {
        if (!r.isConfirmed) return;
        $.post('{{ url("bug-reports") }}/' + id + '/respond', { status: 'Closed', note: r.value || '' })
        .done(function () { window.bugTable.ajax.reload(); Swal.fire({ icon: 'success', title: 'Closed', timer: 1200, showConfirmButton: false }); })
        .fail(function (x) { Swal.fire('Error', x.responseJSON?.message || 'Could not close.', 'error'); });
    });
});

$(document).on('click', '.bug-delete', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Delete this report?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' })
    .then(function (r) {
        if (!r.isConfirmed) return;
        $.ajax({ url: '{{ url("bug-reports") }}/' + id, type: 'DELETE' })
        .done(function () { window.bugTable.ajax.reload(); Swal.fire({ icon: 'success', title: 'Deleted', timer: 1200, showConfirmButton: false }); })
        .fail(function (x) { Swal.fire('Error', x.responseJSON?.message || 'Could not delete.', 'error'); });
    });
});

$(document).on('click', '.bug-view', function () {
    const row = window.bugTable.row($(this).closest('tr')).data();
    if (!row) return;
    $('#viewBugSubject').text(row.subject);
    let html = '<div class="mb-2 small" style="color:var(--text3)">' + (row.reporter || '') + ' &middot; ' + row.created + '</div>'
        + '<div class="mb-3 d-flex gap-2">' + row.severity_badge + row.status_badge + '</div>'
        + '<div class="mb-3" style="white-space:pre-wrap">' + $('<div>').text(row.message || '').html() + '</div>';
    if (row.page_url) {
        html += '<div class="mb-3 small"><span class="fw-semibold" style="color:var(--text2)">Page:</span> ' + $('<div>').text(row.page_url).html() + '</div>';
    }
    if (row.response_note) {
        html += '<div class="pt-2 border-top small"><span class="fw-semibold" style="color:var(--text2)">Response:</span> ' + $('<div>').text(row.response_note).html() + '</div>';
    }
    $('#viewBugBody').html(html);
    new bootstrap.Modal('#viewBugModal').show();
});
</script>
@endpush
