@extends('layouts.app')
@section('title', 'Payments')

@push('styles')
<style>
    .pp-cats { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: .6rem; }
    .pp-cat { border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); padding: .6rem .8rem; cursor: pointer; transition: border-color .12s; }
    .pp-cat:hover, .pp-cat.active { border-color: var(--primary); }
    .pp-cat-name { font-weight: 600; font-size: .84rem; color: var(--text); }
    .pp-cat-line { font-size: .72rem; color: var(--text2); font-variant-numeric: tabular-nums; margin-top: .3rem; }
    .pp-bar { height: 5px; border-radius: 5px; background: var(--border); overflow: hidden; margin-top: .4rem; }
    .pp-bar > span { display: block; height: 100%; background: var(--c-green); }
    .pay-cat { display: inline-block; padding: 1px 7px; border-radius: 20px; font-size: .66rem; font-weight: 600; background: rgba(var(--primary-rgb), .1); color: var(--primary); white-space: nowrap; }
</style>
@endpush

@section('content')

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-cash-coin me-2"></i>Payments</h4>
        <small style="color:var(--text3)">Money received across every client, by category.</small>
    </div>
    @can('manage payments')
    <div class="d-flex gap-2">
        <a href="{{ route('payment-categories.index') }}" class="btn btn-sm btn-light border">
            <i class="bi bi-wallet2 me-1"></i>Categories
        </a>
        <button class="btn btn-sm btn-primary" id="newPaymentBtn">
            <i class="bi bi-plus-lg me-1"></i>Record Payment
        </button>
    </div>
    @endcan
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="fw-bold fs-4 mb-0 c-green">৳{{ number_format($totals['paid'], 0) }}</div>
            <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Total Received</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="fw-bold fs-4 mb-0 c-red">৳{{ number_format($totals['outstanding'], 0) }}</div>
            <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Outstanding · {{ $totals['open_charges'] }} open {{ Str::plural('charge', $totals['open_charges']) }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="fw-bold fs-4 mb-0 c-yellow">৳{{ number_format($totals['partial'], 0) }}</div>
            <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Marked Partial</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="fw-bold fs-4 mb-0" style="color:var(--text2)">{{ $totals['unpaid_count'] }}</div>
            <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Marked Unpaid</div>
        </div>
    </div>
</div>

@if(count($byCategory))
<div class="card section-card mb-3">
    <div class="card-header py-2 d-flex align-items-center justify-content-between">
        <h6 class="fw-bold mb-0" style="font-size:.85rem">By category</h6>
        <small style="color:var(--text3)">Click one to filter the list</small>
    </div>
    <div class="card-body">
        <div class="pp-cats">
            @foreach($byCategory as $row)
                @php $pct = $row['billed'] > 0 ? min(100, (int) round($row['received'] / $row['billed'] * 100)) : 0; @endphp
                <div class="pp-cat" data-category="{{ $row['id'] }}">
                    <div class="d-flex justify-content-between gap-2">
                        <span class="pp-cat-name">{{ $row['name'] }}</span>
                        @if($row['due'] > 0)
                            <span style="font-size:.7rem;font-weight:600;color:var(--c-red);white-space:nowrap">৳{{ number_format($row['due'], 0) }} due</span>
                        @endif
                    </div>
                    @if($row['billed'] > 0)
                        <div class="pp-bar"><span style="width:{{ $pct }}%"></span></div>
                    @endif
                    <div class="pp-cat-line">
                        <strong style="color:var(--text)">৳{{ number_format($row['received'], 0) }}</strong> received
                        @if($row['billed'] > 0) of ৳{{ number_format($row['billed'], 0) }} billed @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endif

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap" id="paymentPills">
    <button class="fpill" data-status="" id="pillAll">All</button>
    @php $statusCls = ['Paid' => 'spill-completed', 'Partial' => 'spill-warning', 'Unpaid' => 'spill-hold']; @endphp
    @foreach($statusCls as $st => $cls)
    <button class="fpill" data-status="{{ $st }}">
        <span class="spill {{ $cls }}" style="padding:1px 7px;font-size:.65rem">{{ $st }}</span>
    </button>
    @endforeach

    <div class="ms-auto d-flex gap-2 flex-wrap">
        <div style="width:200px">
            <select id="filterCategory" class="form-select form-select-sm">
                <option value="">All Categories</option>
                @foreach($categories as $c)
                <option value="{{ $c->id }}">{{ $c->name }}{{ $c->is_active ? '' : ' (inactive)' }}</option>
                @endforeach
                <option value="none">Uncategorised</option>
            </select>
        </div>
        <div style="width:240px">
            <select id="filterClient" class="form-select form-select-sm">
                <option value="">All Clients</option>
                @foreach($clients as $c)
                <option value="{{ $c->id }}">{{ $c->client_name }} ({{ $c->dfid_number }})</option>
                @endforeach
            </select>
        </div>
    </div>
</div>

<ul class="nav nav-tabs mb-3" id="paymentsViewTabs">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#paymentsPane" type="button">
            <i class="bi bi-cash-coin me-1"></i>Payments
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#chargesPane" type="button" id="chargesTabBtn">
            <i class="bi bi-receipt me-1"></i>Charges <small style="color:var(--text3)">(who owes / owes nothing)</small>
        </button>
    </li>
</ul>

<div class="tab-content">
<div class="tab-pane fade show active" id="paymentsPane">
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            @include('partials.live-counts')
            <table id="paymentsTable" class="table table-hover align-middle w-100 mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Client</th>
                        <th>Category</th>
                        <th>Against</th>
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
</div>

<div class="tab-pane fade" id="chargesPane">
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap" id="chargePills">
        <button class="fpill" data-status="" id="pillChargeAll">All</button>
        @php $chargeStatusCls = ['Unpaid' => 'spill-hold', 'Partially Paid' => 'spill-warning', 'Paid' => 'spill-completed']; @endphp
        @foreach($chargeStatusCls as $st => $cls)
        <button class="fpill" data-status="{{ $st }}">
            <span class="spill {{ $cls }}" style="padding:1px 7px;font-size:.65rem">{{ $st }}</span>
        </button>
        @endforeach
        <small class="ms-2" style="color:var(--text3)">Uses the same client/category filters above.</small>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="chargesTable" class="table table-hover align-middle w-100 mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Client</th>
                            <th>Category</th>
                            <th>Charge</th>
                            <th>Status</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Due</th>
                            <th>Due Date</th>
                            <th width="60" class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

@can('manage payments')
    @include('payments.partials.record-modal', ['modalId' => 'paymentModal', 'withClientPicker' => true, 'clients' => $clients])
@endcan
@include('payments.partials.correction')
@endsection

@push('scripts')
<script>
var activeStatus = '';
var activeChargeStatus = '';

function syncPaymentPills() {
    $('#paymentPills .fpill').removeClass('active');
    if (!activeStatus) { $('#pillAll').addClass('active'); return; }
    $('#paymentPills .fpill[data-status="' + activeStatus + '"]').addClass('active');
}
syncPaymentPills();

$('#paymentPills .fpill').on('click', function () {
    activeStatus = $(this).data('status') || '';
    syncPaymentPills();
    window.pTable.ajax.reload();
});

function syncChargePills() {
    $('#chargePills .fpill').removeClass('active');
    if (!activeChargeStatus) { $('#pillChargeAll').addClass('active'); return; }
    $('#chargePills .fpill[data-status="' + activeChargeStatus + '"]').addClass('active');
}
syncChargePills();

$('#chargePills .fpill').on('click', function () {
    activeChargeStatus = $(this).data('status') || '';
    syncChargePills();
    if (window.cTable) window.cTable.ajax.reload();
});

$('#filterClient, #filterCategory').on('change', function () {
    $('.pp-cat').removeClass('active')
        .filter('[data-category="' + $('#filterCategory').val() + '"]').addClass('active');
    window.pTable.ajax.reload();
    if (window.cTable) window.cTable.ajax.reload();
});

$(document).on('click', '.pp-cat', function () {
    const id = String($(this).data('category'));
    $('#filterCategory').val($('#filterCategory').val() === id ? '' : id).trigger('change');
});

$(function () {
    $('#filterClient').select2({ theme: 'bootstrap-5', width: '100%' });

    window.pTable = $('#paymentsTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[6, 'desc']],
        ajax: {
            url: '{{ route("payments.index") }}',
            data: function (d) {
                d.status      = activeStatus;
                d.client_id   = $('#filterClient').val();
                d.category_id = $('#filterCategory').val();
            }
        },
        columns: [
            { data: 'DT_RowIndex', orderable: false, searchable: false },
            { data: 'client', orderable: false },
            { data: 'category_name', orderable: false, searchable: false },
            { data: 'charge', orderable: false, searchable: false },
            { data: 'status_badge', orderable: false },
            { data: 'amount_fmt', orderable: false },
            { data: 'date_fmt' },
            { data: 'payment_method', orderable: false },
            { data: 'transaction_number', orderable: false },
            { data: 'created_by_name', orderable: false },
            { data: 'actions', orderable: false, searchable: false, className: 'text-end pe-3' },
        ]
    });

    livePillCounts('#paymentsTable', { pillSelector: '#paymentPills .fpill[data-status]' });

    $('#chargesTabBtn').one('shown.bs.tab', function () {
        window.cTable = $('#chargesTable').DataTable({
            processing: true,
            serverSide: true,
            order: [[8, 'asc']],
            ajax: {
                url: '{{ route("payments.charges") }}',
                data: function (d) {
                    d.status      = activeChargeStatus;
                    d.client_id   = $('#filterClient').val();
                    d.category_id = $('#filterCategory').val();
                }
            },
            columns: [
                { data: 'DT_RowIndex', orderable: false, searchable: false },
                { data: 'client', orderable: false },
                { data: 'category_name', orderable: false, searchable: false },
                { data: 'charge', orderable: false, searchable: false },
                { data: 'status_badge', orderable: false },
                { data: 'total_fmt', orderable: false },
                { data: 'paid_fmt', orderable: false },
                { data: 'due_fmt', orderable: false },
                { data: 'due_date_fmt' },
                { data: 'actions', orderable: false, searchable: false, className: 'text-end pe-3' },
            ]
        });

        livePillCounts('#chargesTable', { pillSelector: '#chargePills .fpill[data-status]' });
    });

    @can('manage payments')
    const $client = $('#paymentModal [data-rp="client"]');
    $client.select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#paymentModal') });

    const recorder = window.RecordPayment({
        modal: '#paymentModal',
        categories: {{ Js::from($categories->where('is_active', true)->values()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])) }},
        clientId: () => $client.val() || null,
        storeUrl: () => '{{ route("payments.store") }}',
        chargesUrl: id => '{{ url('clients') }}/' + id + '/invoices',
        sendClient: true,
        onSaved: () => window.pTable.ajax.reload(),
    });

    $('#newPaymentBtn').on('click', function () {
        // Start from whichever client the list is filtered to, if any.
        $client.val($('#filterClient').val() || '').trigger('change.select2');
        recorder.open({ categoryId: /^\d+$/.test($('#filterCategory').val()) ? $('#filterCategory').val() : null });
    });
    @endcan
});

$(document).on('click', '.payment-delete', function () {
    PaymentCorrection.remove('/payments/' + $(this).data('id'), () => window.pTable.ajax.reload(null, false));
});
$(document).on('click', '.payment-history', function () {
    PaymentCorrection.history('{{ url('clients') }}/' + $(this).data('client') + '/payments/' + $(this).data('id') + '/history');
});
</script>
@endpush
