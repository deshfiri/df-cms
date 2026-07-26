@extends('layouts.app')
@section('title', 'Payment Proofs')

@section('content')
<h4 class="page-title mb-3">Payment Proof Submissions</h4>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="proofsTable" class="table table-hover align-middle w-100 mb-0">
                <thead>
                    <tr>
                        <th>#</th><th>Client</th><th>Submitted By</th><th>Amount</th><th>Date</th><th width="120" class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    window.proofTable = $('#proofsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: { url: '{{ route("payment-proofs.index") }}' },
        columns: [
            { data: 'DT_RowIndex', orderable: false, searchable: false },
            { data: 'client', orderable: false, searchable: false },
            { data: 'submitted_by', orderable: false, searchable: false },
            { data: 'amount', orderable: false, searchable: false },
            { data: 'created', orderable: false, searchable: false },
            { data: null, orderable: false, searchable: false, render: function (row) {
                return '<div class="text-end">'
                    + '<button class="btn btn-sm px-2 py-1 proof-verify" data-id="' + row.id + '" style="background:rgba(5,150,105,.08);color:#059669"><i class="bi bi-check-lg"></i></button> '
                    + '<button class="btn btn-sm px-2 py-1 proof-reject" data-id="' + row.id + '" style="background:rgba(239,68,68,.08);color:#dc2626"><i class="bi bi-x-lg"></i></button>'
                    + '</div>';
            } },
        ],
    });
});

$(document).on('click', '.proof-verify', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Verify this payment?', input: 'text', inputPlaceholder: 'Note (optional)', showCancelButton: true, confirmButtonText: 'Verify' })
    .then(function (r) {
        if (!r.isConfirmed) return;
        $.post('{{ url("payment-proofs") }}/' + id + '/verify', { note: r.value || '' })
        .done(function () { window.proofTable.ajax.reload(); Swal.fire({ icon: 'success', title: 'Verified', timer: 1200, showConfirmButton: false }); })
        .fail(function (x) { Swal.fire('Error', x.responseJSON?.message || 'Could not verify.', 'error'); });
    });
});

$(document).on('click', '.proof-reject', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Reject this submission?', input: 'text', inputPlaceholder: 'Reason', showCancelButton: true, confirmButtonText: 'Reject', confirmButtonColor: '#dc3545' })
    .then(function (r) {
        if (!r.isConfirmed || !r.value) return;
        $.post('{{ url("payment-proofs") }}/' + id + '/reject', { note: r.value })
        .done(function () { window.proofTable.ajax.reload(); Swal.fire({ icon: 'success', title: 'Rejected', timer: 1200, showConfirmButton: false }); })
        .fail(function (x) { Swal.fire('Error', x.responseJSON?.message || 'Could not reject.', 'error'); });
    });
});
</script>
@endpush
