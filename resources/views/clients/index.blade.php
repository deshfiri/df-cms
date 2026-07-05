@extends('layouts.app')
@section('title', 'Clients')

@push('styles')
<style>
.client-row { cursor: pointer; }
.client-row td:first-child, .client-row td:last-child { cursor: default; }

.status-dd { position: relative; display: inline-block; }
.status-dd .sd-menu {
    position: absolute; top: calc(100% + 4px); left: 0;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 8px; box-shadow: var(--shadow-lg);
    z-index: 999; min-width: 140px; display: none; padding: 4px;
}
.status-dd.open .sd-menu { display: block; }
.sd-item {
    display: flex; align-items: center; gap: 7px;
    padding: 6px 9px; border-radius: 5px; cursor: pointer;
    font-size: .73rem; font-weight: 500; color: var(--text2);
    transition: background .1s;
}
.sd-item:hover { background: var(--surface2); color: var(--text); }
.sd-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }

.row-acts { opacity: 0; transition: opacity .15s; }
.client-row:hover .row-acts { opacity: 1; }
</style>
@endpush

@section('content')

{{-- Page header --}}
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0">Clients</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">{{ $totalClients }} total</div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" id="exportNavBtn">
            <i class="bi bi-download me-1"></i>Export
        </button>
        @can('manage clients')
        <a href="{{ route('clients.create') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>New Client
        </a>
        @endcan
    </div>
</div>

{{-- Filter pills --}}
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <button class="fpill" data-status="" id="pillAll">
        All <span class="fcnt">{{ $totalClients }}</span>
    </button>
    @php $pillCls = ['Running'=>'spill-running','Warning'=>'spill-warning','Completed'=>'spill-completed','Hold'=>'spill-hold','Cancelled'=>'spill-cancelled']; @endphp
    @foreach($pillCls as $st => $cls)
    <button class="fpill" data-status="{{ $st }}">
        <span class="spill {{ $cls }}" style="padding:1px 7px;font-size:.65rem">{{ $st }}</span>
        <span class="fcnt">{{ $statusCounts[$st] ?? 0 }}</span>
    </button>
    @endforeach
    <button class="fpill" id="pillNoUpdate">
        <i class="bi bi-clock-history" style="font-size:.67rem"></i>
        No Update <span class="fcnt d-none d-sm-inline">30d</span>
    </button>

    <div class="ms-auto d-flex gap-2">
        <button id="advToggle" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1">
            <i class="bi bi-sliders"></i>
            <span class="d-none d-sm-inline">Filters</span>
        </button>
    </div>
</div>

{{-- Advanced filters --}}
<div id="advFilters" style="display:none">
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-sm-4">
                    <label class="form-label">Category</label>
                    <select id="filterCategory" class="form-select form-select-sm">
                        <option value="">All Categories</option>
                        @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-4">
                    <label class="form-label">Assigned To</label>
                    <select id="filterUser" class="form-select form-select-sm">
                        <option value="">All Users</option>
                        <option value="none">Unassigned</option>
                        @foreach($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-4">
                    <button id="applyFilters" class="btn btn-sm btn-primary me-1">
                        <i class="bi bi-funnel me-1"></i>Apply
                    </button>
                    <button id="clearFilters" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-x-lg"></i> Clear
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- DataTable --}}
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="clientsTable" class="table table-hover align-middle w-100 mb-0">
                <thead>
                    <tr>
                        <th width="32"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                        <th>#</th>
                        <th>DFID</th>
                        <th>Client / Brand</th>
                        <th>Category</th>
                        <th>Joined</th>
                        <th>Progress</th>
                        <th>Status</th>
                        <th>Product</th>
                        <th>Payment</th>
                        <th width="90" class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
var activeStatus = '';
var noUpdate     = false;

// Read URL params on load
(function () {
    var p = new URLSearchParams(window.location.search);
    if (p.get('status'))       { activeStatus = p.get('status'); }
    if (p.get('no_update') === '1') { noUpdate = true; }
    if (p.get('search'))       { $('#globalSearch').val(p.get('search')); }
    if (p.get('assigned_to')) { $('#filterUser').val(p.get('assigned_to')); }
    syncPills();
})();

function syncPills() {
    $('.fpill').removeClass('active');
    if (noUpdate) { $('#pillNoUpdate').addClass('active'); return; }
    if (!activeStatus) { $('#pillAll').addClass('active'); return; }
    $('.fpill[data-status="' + activeStatus + '"]').addClass('active');
}

// Pill clicks
$('.fpill[data-status]').on('click', function () {
    activeStatus = $(this).data('status');
    noUpdate = false;
    syncPills();
    if (window.dfTable) window.dfTable.ajax.reload();
});

$('#pillNoUpdate').on('click', function () {
    noUpdate = !noUpdate;
    activeStatus = '';
    syncPills();
    if (window.dfTable) window.dfTable.ajax.reload();
});

// Advanced filter
$('#advToggle').on('click', function () {
    var $af = $('#advFilters');
    $af.is(':visible') ? $af.slideUp(150) : $af.slideDown(150);
    $(this).toggleClass('btn-outline-secondary btn-secondary');
});
$('#applyFilters').on('click',  function () { if (window.dfTable) window.dfTable.ajax.reload(); });
$('#clearFilters').on('click', function () {
    $('#filterCategory,#filterUser').val('');
    activeStatus = ''; noUpdate = false; syncPills();
    if (window.dfTable) window.dfTable.search('').ajax.reload();
});

$(function () {
    // DataTable init
    window.dfTable = $('#clientsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ route("clients.index") }}',
            data: function (d) {
                d.status      = activeStatus;
                d.category_id = $('#filterCategory').val();
                d.assigned_to = $('#filterUser').val();
                d.no_update   = noUpdate ? 1 : 0;
                var gs = $('#globalSearch').val();
                if (gs) d.search.value = gs;
                window._dtSearch = d.search.value;
                window._dtStatus = d.status;
                window._dtCat    = d.category_id;
            }
        },
        columns: [
            { data: null,                  orderable: false, searchable: false,
              render: (d, t, row) => `<input type="checkbox" class="form-check-input row-check" value="${row.id}">` },
            { data: 'DT_RowIndex',         orderable: false,  searchable: false },
            { data: 'dfid',                name: 'dfid_number', searchable: false },
            { data: 'client',              name: 'client_name', searchable: false },
            { data: 'category',            orderable: false,  searchable: false },
            { data: 'joining',             name: 'joining_date', searchable: false },
            { data: 'progress',            orderable: false,  searchable: false },
            { data: 'client_status_badge', name: 'client_status', searchable: false },
            { data: 'product_status',      orderable: false,  searchable: false },
            { data: 'payment_status',      orderable: false,  searchable: false },
            { data: 'actions',             orderable: false,  searchable: false },
        ],
        order: [[5, 'desc']],
        pageLength: 25,
        language: {
            processing: '<div class="d-flex align-items-center gap-2 justify-content-center py-3"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div><span style="font-size:.77rem;color:var(--text3)">Loading…</span></div>'
        },
        dom: '<"d-flex align-items-center justify-content-between px-3 py-2" lf>t<"d-flex align-items-center justify-content-between px-3 py-2 border-top" ip>',
        createdRow: function (row, data) {
            $(row).addClass('client-row').attr('data-client-id', data.id);
        }
    });

    // Row click → quick view
    $('#clientsTable tbody').on('click', 'tr.client-row', function (e) {
        if ($(e.target).closest('input,a,button,.status-dd').length) return;
        var id = $(this).data('client-id');
        if (id) openDrawer(id);
    });

    // Global search integration
    var gsDebounce;
    $('#globalSearch').on('input', function () {
        clearTimeout(gsDebounce);
        gsDebounce = setTimeout(function () { if (window.dfTable) window.dfTable.ajax.reload(); }, 400);
    });

    // Inline status dropdown
    $(document).on('click', '.status-trigger', function (e) {
        e.stopPropagation();
        var $dd = $(this).closest('.status-dd');
        $('.status-dd.open').not($dd).removeClass('open');
        $dd.toggleClass('open');
    });
    $(document).on('click', '.sd-item', function (e) {
        e.stopPropagation();
        var $dd = $(this).closest('.status-dd');
        var id  = $dd.data('client-id');
        var st  = $(this).data('status');
        $.post('/clients/' + id + '/status', { status: st })
         .done(function () { window.dfTable.ajax.reload(null, false); });
        $dd.removeClass('open');
    });
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.status-dd').length) $('.status-dd').removeClass('open');
    });

    // Row delete
    $(document).on('click', '.btn-delete', function (e) {
        e.stopPropagation();
        var id = $(this).data('id');
        Swal.fire({ title: 'Delete client?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' })
        .then(function (r) {
            if (r.isConfirmed) {
                $.ajax({ url: '/clients/' + id, type: 'DELETE' })
                 .done(function () { Swal.fire('Deleted', '', 'success'); window.dfTable.ajax.reload(); });
            }
        });
    });
});
</script>
@endpush
