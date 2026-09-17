@extends('layouts.app')
@section('title', 'Refunds')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-arrow-counterclockwise me-2"></i>Refunds</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">
            Money going back to clients — requested, decided by someone else, paid out with a reference.
            @can('request refunds') Ask for one from a payment on the client's Payments tab. @endcan
        </div>
    </div>
</div>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <button class="fpill active" data-status="">All</button>
    @foreach($statuses as $value => $label)
        <button class="fpill" data-status="{{ $value }}">{{ $label }} <span class="fcnt">0</span></button>
    @endforeach
    <button class="fpill" id="pillMine" title="Refunds I asked for"><i class="bi bi-person me-1" style="font-size:.67rem"></i>Mine</button>

    <div class="ms-auto">
        <select id="filterClient" class="form-select form-select-sm" style="width:200px">
            <option value="">All clients</option>
            @foreach($clients as $c)
                <option value="{{ $c->id }}">{{ $c->client_name }} ({{ $c->dfid_number }})</option>
            @endforeach
        </select>
    </div>
</div>

<div class="card section-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            @include('partials.live-counts')
            @include('refunds.partials.dialogs')
            <table id="refundsTable" class="table table-hover align-middle w-100 mb-0" style="font-size:.85rem">
                <thead>
                    <tr>
                        <th class="ps-3">Refund</th>
                        <th>Client</th>
                        <th>Payment</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>Requested</th>
                        <th class="text-end pe-3"></th>
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
    let status = '', mine = false;

    const table = $('#refundsTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[5, 'desc']],
        ajax: {
            url: '{{ route('refunds.index') }}',
            data: function (d) {
                d.status = status;
                d.mine = mine ? 1 : 0;
                d.client_id = $('#filterClient').val();
            },
        },
        columns: [
            { data: 'number', name: 'refund_number', className: 'ps-3' },
            { data: 'client', orderable: false, searchable: false },
            { data: 'payment', orderable: false, searchable: false },
            { data: 'amount_fmt', name: 'amount', searchable: false, className: 'text-end' },
            { data: 'status_badge', orderable: false, searchable: false },
            { data: 'requested', name: 'created_at', searchable: false },
            { data: 'actions', orderable: false, searchable: false, className: 'text-end pe-3' },
        ],
    });
    livePillCounts('#refundsTable');

    $('.fpill[data-status]').on('click', function () {
        status = $(this).data('status');
        $('.fpill[data-status]').removeClass('active');
        $(this).addClass('active');
        table.ajax.reload();
    });
    $('#pillMine').on('click', function () {
        mine = !mine;
        $(this).toggleClass('active', mine);
        table.ajax.reload();
    });
    $('#filterClient').on('change', () => table.ajax.reload());

    $(document).on('click', '.refund-open', function () {
        Refunds.open($(this).data('id'), () => table.ajax.reload(null, false));
    });

    // Arriving from a notification: /refunds?refund=12 opens it.
    const params = new URLSearchParams(location.search);
    if (/^\d+$/.test(params.get('refund') || '')) {
        Refunds.open(params.get('refund'), () => table.ajax.reload(null, false));
        history.replaceState(null, '', location.pathname);
    }
});
</script>
@endpush
