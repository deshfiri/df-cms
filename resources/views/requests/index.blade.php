@extends('layouts.app')
@section('title', 'Requests')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0">Requests</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Ask Super Admin / Manager for anything you need</div>
    </div>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newRequestModal">
        <i class="bi bi-plus-lg me-1"></i>New Request
    </button>
</div>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <button class="fpill" data-status="" id="pillAll">All</button>
    <button class="fpill" data-status="Pending">
        <span class="spill spill-pending" style="padding:1px 7px;font-size:.65rem">Pending</span>
    </button>
    <button class="fpill" data-status="Approved">
        <span class="spill spill-approved" style="padding:1px 7px;font-size:.65rem">Approved</span>
    </button>
    <button class="fpill" data-status="Rejected">
        <span class="spill spill-rejected" style="padding:1px 7px;font-size:.65rem">Rejected</span>
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
            <table id="requestsTable" class="table table-hover align-middle w-100 mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Subject</th>
                        @if($canManage)
                        <th>Requested By</th>
                        @endif
                        <th>Client</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th width="140" class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

{{-- New Request Modal --}}
<div class="modal fade" id="newRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold">New Request</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Subject <span class="text-danger">*</span></label>
                    <input type="text" id="reqSubject" class="form-control" placeholder="What do you need?">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Message <span class="text-danger">*</span></label>
                    <textarea id="reqMessage" class="form-control" rows="4" placeholder="Explain your request..."></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small d-block">Request About <span class="text-danger">*</span></label>
                    <div class="d-flex gap-2">
                        <button type="button" class="fpill active" id="reqTypePersonal" data-type="personal" style="flex:1;justify-content:center">
                            <i class="bi bi-person"></i>Personal
                        </button>
                        <button type="button" class="fpill" id="reqTypeClient" data-type="client" style="flex:1;justify-content:center">
                            <i class="bi bi-building"></i>Client Related
                        </button>
                    </div>
                </div>
                <div class="mb-3 d-none" id="reqClientWrap">
                    <label class="form-label fw-semibold small">Client <span class="text-danger">*</span></label>
                    <select id="reqClient" class="form-select select2">
                        <option value="">Select a client...</option>
                        @foreach($clients as $c)
                        <option value="{{ $c->id }}">{{ $c->client_name }} ({{ $c->dfid_number }})</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="saveRequest" class="btn btn-sm btn-primary"><i class="bi bi-send me-1"></i>Submit</button>
            </div>
        </div>
    </div>
</div>

{{-- View Request Modal --}}
<div class="modal fade" id="viewRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold" id="viewReqSubject"></h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewReqBody"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const canManageRequests = @json($canManage);
let activeReqStatus = '';
let mineOnly = false;

$('.fpill[data-status]').on('click', function () {
    activeReqStatus = $(this).data('status');
    $('.fpill[data-status]').removeClass('active');
    $(this).addClass('active');
    if (window.reqTable) window.reqTable.ajax.reload();
});
$('#pillAll').addClass('active');

$('#pillMine').on('click', function () {
    mineOnly = !mineOnly;
    $(this).toggleClass('active');
    if (window.reqTable) window.reqTable.ajax.reload();
});

$(function () {
    const columns = [
        { data: 'DT_RowIndex', orderable: false, searchable: false },
        { data: 'subject',     orderable: false, searchable: false },
    ];
    if (canManageRequests) {
        columns.push({ data: 'requester', orderable: false, searchable: false });
    }
    columns.push(
        { data: 'client',       orderable: false, searchable: false },
        { data: 'status_badge', orderable: false, searchable: false },
        { data: 'created',      orderable: false, searchable: false },
        { data: 'actions',      orderable: false, searchable: false }
    );

    window.reqTable = $('#requestsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ route("requests.index") }}',
            data: function (d) {
                d.status    = activeReqStatus;
                d.mine_only = mineOnly ? 1 : 0;
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

$('#reqTypePersonal, #reqTypeClient').on('click', function () {
    $('#reqTypePersonal, #reqTypeClient').removeClass('active');
    $(this).addClass('active');
    const isClient = $(this).data('type') === 'client';
    $('#reqClientWrap').toggleClass('d-none', !isClient);
    if (!isClient) {
        $('#reqClient').val('').trigger('change');
    }
});

$('#newRequestModal').on('hidden.bs.modal', function () {
    $('#reqSubject,#reqMessage').val('');
    $('#reqClient').val('').trigger('change');
    $('#reqTypeClient').removeClass('active');
    $('#reqTypePersonal').addClass('active');
    $('#reqClientWrap').addClass('d-none');
});

$('#saveRequest').on('click', function () {
    const subject   = $('#reqSubject').val().trim();
    const message   = $('#reqMessage').val().trim();
    const isClient  = $('#reqTypeClient').hasClass('active');
    const clientId  = $('#reqClient').val() || null;

    if (!subject || !message) {
        Swal.fire('Missing', 'Subject and message are required.', 'warning');
        return;
    }
    if (isClient && !clientId) {
        Swal.fire('Missing', 'Please select a client for this request.', 'warning');
        return;
    }

    $.post('{{ route("requests.store") }}', {
        subject: subject,
        message: message,
        client_id: isClient ? clientId : null,
    }).done(function () {
        bootstrap.Modal.getInstance('#newRequestModal').hide();
        window.reqTable.ajax.reload();
        Swal.fire({ icon: 'success', title: 'Request submitted', timer: 1200, showConfirmButton: false });
    }).fail(function (xhr) {
        Swal.fire('Error', xhr.responseJSON?.message || 'Could not submit request.', 'error');
    });
});

$(document).on('click', '.req-approve', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Approve this request?', input: 'text', inputPlaceholder: 'Note (optional)', icon: 'question', showCancelButton: true, confirmButtonText: 'Approve' })
    .then(function (r) {
        if (!r.isConfirmed) return;
        $.post('{{ url("requests") }}/' + id + '/respond', { status: 'Approved', note: r.value || '' })
        .done(function () { window.reqTable.ajax.reload(); Swal.fire({ icon: 'success', title: 'Approved', timer: 1200, showConfirmButton: false }); })
        .fail(function (x) { Swal.fire('Error', x.responseJSON?.message || 'Could not approve.', 'error'); });
    });
});

$(document).on('click', '.req-reject', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Reject this request?', input: 'text', inputPlaceholder: 'Reason (optional)', icon: 'warning', showCancelButton: true, confirmButtonText: 'Reject', confirmButtonColor: '#dc3545' })
    .then(function (r) {
        if (!r.isConfirmed) return;
        $.post('{{ url("requests") }}/' + id + '/respond', { status: 'Rejected', note: r.value || '' })
        .done(function () { window.reqTable.ajax.reload(); Swal.fire({ icon: 'success', title: 'Rejected', timer: 1200, showConfirmButton: false }); })
        .fail(function (x) { Swal.fire('Error', x.responseJSON?.message || 'Could not reject.', 'error'); });
    });
});

$(document).on('click', '.req-delete', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Delete this request?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' })
    .then(function (r) {
        if (!r.isConfirmed) return;
        $.ajax({ url: '{{ url("requests") }}/' + id, type: 'DELETE' })
        .done(function () { window.reqTable.ajax.reload(); Swal.fire({ icon: 'success', title: 'Deleted', timer: 1200, showConfirmButton: false }); })
        .fail(function (x) { Swal.fire('Error', x.responseJSON?.message || 'Could not delete.', 'error'); });
    });
});

$(document).on('click', '.req-view', function () {
    const row = window.reqTable.row($(this).closest('tr')).data();
    if (!row) return;
    $('#viewReqSubject').text(row.subject);
    let html = '<div class="mb-2 small" style="color:var(--text3)">' + (row.requester || '') + ' &middot; ' + row.created + '</div>'
        + '<div class="mb-3">' + row.status_badge + '</div>'
        + '<div class="mb-3" style="white-space:pre-wrap">' + $('<div>').text(row.message || '').html() + '</div>';
    if (row.response_note) {
        html += '<div class="pt-2 border-top small"><span class="fw-semibold" style="color:var(--text2)">Response:</span> ' + $('<div>').text(row.response_note).html() + '</div>';
    }
    $('#viewReqBody').html(html);
    new bootstrap.Modal('#viewRequestModal').show();
});
</script>
@endpush
