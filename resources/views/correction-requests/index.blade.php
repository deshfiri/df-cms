@extends('layouts.app')
@section('title', 'Correction Requests')

@section('content')
<h4 class="page-title mb-3">Client Correction Requests</h4>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="correctionsTable" class="table table-hover align-middle w-100 mb-0">
                <thead>
                    <tr><th>#</th><th>Client</th><th>Field</th><th>Status</th><th>Requested</th><th width="140" class="text-end pe-3">Actions</th></tr>
                </thead>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    window.crTable = $('#correctionsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: { url: '{{ route("correction-requests.index") }}' },
        columns: [
            { data: 'DT_RowIndex', orderable: false, searchable: false },
            { data: 'client', orderable: false, searchable: false },
            { data: 'field', orderable: false, searchable: false },
            { data: 'status', orderable: false, searchable: false },
            { data: 'created', orderable: false, searchable: false },
            { data: null, orderable: false, searchable: false, render: function (row) {
                if (row.status !== 'Pending') return '';
                return '<div class="text-end">'
                    + '<button class="btn btn-sm px-2 py-1 cr-approve" data-id="' + row.id + '" style="background:rgba(5,150,105,.08);color:#059669"><i class="bi bi-check-lg"></i></button> '
                    + '<button class="btn btn-sm px-2 py-1 cr-reject" data-id="' + row.id + '" style="background:rgba(239,68,68,.08);color:#dc2626"><i class="bi bi-x-lg"></i></button>'
                    + '</div>';
            } },
        ],
    });
});

$(document).on('click', '.cr-approve', function () {
    const id = $(this).data('id');
    $.post('{{ url("correction-requests") }}/' + id + '/respond', { status: 'Approved' })
    .done(function () { window.crTable.ajax.reload(); Swal.fire({ icon: 'success', title: 'Approved', timer: 1200, showConfirmButton: false }); });
});
$(document).on('click', '.cr-reject', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Reject this request?', input: 'text', inputPlaceholder: 'Reason', showCancelButton: true, confirmButtonColor: '#dc3545' })
    .then(function (r) {
        if (!r.isConfirmed) return;
        $.post('{{ url("correction-requests") }}/' + id + '/respond', { status: 'Rejected', note: r.value || '' })
        .done(function () { window.crTable.ajax.reload(); Swal.fire({ icon: 'success', title: 'Rejected', timer: 1200, showConfirmButton: false }); });
    });
});
</script>
@endpush
