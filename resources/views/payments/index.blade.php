@extends('layouts.app')
@section('title', 'Payments')

@section('content')

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-cash-coin me-2"></i>Payments</h4>
    </div>
    @can('manage payments')
    <button class="btn btn-sm btn-primary" id="newPaymentBtn" data-bs-toggle="modal" data-bs-target="#paymentModal">
        <i class="bi bi-plus-lg me-1"></i>Record Payment
    </button>
    @endcan
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="card text-center py-3">
            <div class="fw-bold fs-4 mb-0 c-green">৳{{ number_format($totals['paid'], 0) }}</div>
            <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Total Paid</div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card text-center py-3">
            <div class="fw-bold fs-4 mb-0 c-yellow">৳{{ number_format($totals['partial'], 0) }}</div>
            <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Total Partial</div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card text-center py-3">
            <div class="fw-bold fs-4 mb-0 c-red">{{ $totals['unpaid_count'] }}</div>
            <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Unpaid Invoices</div>
        </div>
    </div>
</div>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <button class="fpill" data-status="" id="pillAll">All</button>
    @php $statusCls = ['Paid' => 'spill-completed', 'Partial' => 'spill-warning', 'Unpaid' => 'spill-hold']; @endphp
    @foreach($statusCls as $st => $cls)
    <button class="fpill" data-status="{{ $st }}">
        <span class="spill {{ $cls }}" style="padding:1px 7px;font-size:.65rem">{{ $st }}</span>
    </button>
    @endforeach

    <div class="ms-auto" style="width:240px">
        <select id="filterClient" class="form-select form-select-sm">
            <option value="">All Clients</option>
            @foreach($clients as $c)
            <option value="{{ $c->id }}">{{ $c->client_name }} ({{ $c->dfid_number }})</option>
            @endforeach
        </select>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="paymentsTable" class="table table-hover align-middle w-100 mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Client</th>
                        <th>Status</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th>Method</th>
                        <th>Txn #</th>
                        <th>Recorded By</th>
                        <th width="90" class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

{{-- Record Payment Modal --}}
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold"><i class="bi bi-plus-lg me-2"></i>Record Payment</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Client <span class="text-danger">*</span></label>
                        <select id="payClient" class="form-select">
                            <option value="">Select client...</option>
                            @foreach($clients as $c)
                            <option value="{{ $c->id }}">{{ $c->client_name }} ({{ $c->dfid_number }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Status <span class="text-danger">*</span></label>
                        <select id="payStatus" class="form-select">
                            @foreach(\App\Models\Payment::$statuses as $s)
                            <option value="{{ $s }}">{{ $s }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Amount</label>
                        <input type="number" id="payAmount" class="form-control" placeholder="0.00" step="0.01">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Payment Date</label>
                        <input type="date" id="payDate" class="form-control" value="{{ date('Y-m-d') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Method</label>
                        <select id="payMethod" class="form-select">
                            <option value="">Select...</option>
                            @foreach(\App\Models\Payment::$methods as $m)
                            <option value="{{ $m }}">{{ $m }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Transaction Number</label>
                        <input type="text" id="payTxn" class="form-control" placeholder="Ref / Transaction ID">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Remarks</label>
                        <textarea id="payRemarks" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="savePaymentBtn" class="btn btn-sm btn-primary"><i class="bi bi-check me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
var activeStatus = '';

function syncPaymentPills() {
    $('.fpill').removeClass('active');
    if (!activeStatus) { $('#pillAll').addClass('active'); return; }
    $('.fpill[data-status="' + activeStatus + '"]').addClass('active');
}
syncPaymentPills();

$('.fpill').on('click', function () {
    activeStatus = $(this).data('status') || '';
    syncPaymentPills();
    window.pTable.ajax.reload();
});

$('#filterClient').on('change', function () { window.pTable.ajax.reload(); });

$(function () {
    $('#filterClient').select2({ theme: 'bootstrap-5', width: '100%' });
    $('#payClient').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#paymentModal') });

    window.pTable = $('#paymentsTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[4, 'desc']],
        ajax: {
            url: '{{ route("payments.index") }}',
            data: function (d) {
                d.status    = activeStatus;
                d.client_id = $('#filterClient').val();
            }
        },
        columns: [
            { data: 'DT_RowIndex', orderable: false, searchable: false },
            { data: 'client', orderable: false },
            { data: 'status_badge', orderable: false },
            { data: 'amount_fmt', orderable: false },
            { data: 'date_fmt' },
            { data: 'payment_method', orderable: false },
            { data: 'transaction_number', orderable: false },
            { data: 'created_by_name', orderable: false },
            { data: 'actions', orderable: false, searchable: false, className: 'text-end pe-3' },
        ]
    });
});

$('#newPaymentBtn').on('click', function () {
    $('#payClient').val('').trigger('change');
    $('#payStatus').val('Paid');
    $('#payAmount,#payTxn,#payRemarks').val('');
    $('#payDate').val('{{ date("Y-m-d") }}');
    $('#payMethod').val('');
});

$('#savePaymentBtn').on('click', function () {
    var clientId = $('#payClient').val();
    if (!clientId) {
        Swal.fire('Missing client', 'Please select a client.', 'warning');
        return;
    }
    $.post('{{ route("payments.store") }}', {
        client_id: clientId,
        status: $('#payStatus').val(),
        amount: $('#payAmount').val(),
        payment_date: $('#payDate').val(),
        payment_method: $('#payMethod').val(),
        transaction_number: $('#payTxn').val(),
        remarks: $('#payRemarks').val()
    }).done(function () {
        bootstrap.Modal.getInstance('#paymentModal').hide();
        window.pTable.ajax.reload();
        Swal.fire({ icon: 'success', title: 'Saved', timer: 1200, showConfirmButton: false });
    }).fail(function (r) {
        Swal.fire('Error', r.responseJSON?.message || 'Could not save payment.', 'error');
    });
});

$(document).on('click', '.payment-delete', function () {
    var id = $(this).data('id');
    Swal.fire({ title: 'Delete payment?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545' })
    .then(function (r) {
        if (r.isConfirmed) {
            $.ajax({ url: '/payments/' + id, type: 'DELETE' }).done(function () {
                window.pTable.ajax.reload();
            });
        }
    });
});
</script>
@endpush
