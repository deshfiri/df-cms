@extends('layouts.app')
@section('title', 'Pending Changes')

@section('content')
<div class="mb-3">
    <h4 class="page-title mb-0"><i class="bi bi-hourglass-split me-2"></i>Pending Changes</h4>
    <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Edits from non-admin/editor users wait here until approved</div>
</div>

<div class="card section-card">
    <div class="card-body p-0" id="pcList">
        <div class="text-center py-5">
            <div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const pcListUrl    = '{{ route('pending-changes.index') }}';
const pcApproveBase = '{{ url('pending-changes') }}';

function pcEsc(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function pcLoad() {
    $('#pcList').html('<div class="text-center py-5"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div></div>');
    $.get(pcListUrl)
    .done(function (res) {
        pcRender(res.data || []);
    })
    .fail(function () {
        $('#pcList').html('<div class="text-center py-4 small c-red"><i class="bi bi-exclamation-circle me-1"></i>Failed to load pending changes.</div>');
    });
}

function pcDiffRows(oldValues, newValues) {
    const keys = Array.from(new Set([...Object.keys(oldValues || {}), ...Object.keys(newValues || {})]));
    let html = '';
    keys.forEach(function (key) {
        const oldVal = oldValues ? oldValues[key] : undefined;
        const newVal = newValues ? newValues[key] : undefined;
        if (JSON.stringify(oldVal) === JSON.stringify(newVal)) return;
        html += '<div class="d-flex align-items-start gap-2 py-1" style="font-size:.78rem">'
            + '<span class="fw-semibold" style="min-width:120px;color:var(--text2)">' + pcEsc(key) + '</span>'
            + '<span class="text-decoration-line-through" style="color:var(--text3)">' + pcEsc(JSON.stringify(oldVal)) + '</span>'
            + '<i class="bi bi-arrow-right" style="color:var(--text3)"></i>'
            + '<span style="color:var(--primary);font-weight:600">' + pcEsc(JSON.stringify(newVal)) + '</span>'
            + '</div>';
    });
    return html || '<div class="small" style="color:var(--text3)">No visible field differences.</div>';
}

function pcRender(items) {
    if (!items.length) {
        $('#pcList').html('<div class="text-center py-5" style="color:var(--text3)"><i class="bi bi-check2-circle" style="font-size:2rem"></i><div class="mt-2" style="font-size:.82rem">No pending changes right now.</div></div>');
        return;
    }

    let html = '';
    items.forEach(function (item) {
        html += '<div class="p-3" style="border-bottom:1px solid var(--border)" data-id="' + item.id + '">'
            + '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">'
            + '<div>'
            + '<span class="fw-semibold">' + pcEsc(item.model_label) + ' #' + item.model_id + '</span>'
            + '<span class="ms-2 small" style="color:var(--text3)">Requested by ' + pcEsc(item.requested_by || 'Unknown') + ' &middot; ' + pcEsc(item.created_at_human) + '</span>'
            + '</div>'
            + '<div class="d-flex gap-2">'
            + '<button class="btn btn-sm btn-success pc-approve" data-id="' + item.id + '"><i class="bi bi-check-lg me-1"></i>Approve</button>'
            + '<button class="btn btn-sm btn-outline-danger pc-reject" data-id="' + item.id + '"><i class="bi bi-x-lg me-1"></i>Reject</button>'
            + '</div>'
            + '</div>'
            + pcDiffRows(item.old_values, item.new_values)
            + '</div>';
    });

    $('#pcList').html(html);
}

$(document).on('click', '.pc-approve', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Approve this change?', text: 'It will be applied immediately.', icon: 'question', showCancelButton: true, confirmButtonText: 'Approve' })
    .then(function (r) {
        if (!r.isConfirmed) return;
        $.post(pcApproveBase + '/' + id + '/approve')
        .done(function () { pcLoad(); Swal.fire({ icon: 'success', title: 'Approved', timer: 1200, showConfirmButton: false }); })
        .fail(function (x) { Swal.fire('Error', x.responseJSON?.message || 'Could not approve.', 'error'); });
    });
});

$(document).on('click', '.pc-reject', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Reject this change?', input: 'text', inputPlaceholder: 'Reason (optional)', icon: 'warning', showCancelButton: true, confirmButtonText: 'Reject', confirmButtonColor: '#dc3545' })
    .then(function (r) {
        if (!r.isConfirmed) return;
        $.post(pcApproveBase + '/' + id + '/reject', { note: r.value || '' })
        .done(function () { pcLoad(); Swal.fire({ icon: 'success', title: 'Rejected', timer: 1200, showConfirmButton: false }); })
        .fail(function (x) { Swal.fire('Error', x.responseJSON?.message || 'Could not reject.', 'error'); });
    });
});

pcLoad();
</script>
@endpush
