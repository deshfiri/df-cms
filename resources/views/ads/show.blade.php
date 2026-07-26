@extends('layouts.app')
@section('title', $campaign->name)

@section('content')
@php
    $statusPill = ['Active' => 'spill-running', 'Paused' => 'spill-warning', 'Completed' => 'spill-completed', 'Cancelled' => 'spill-cancelled'][$campaign->status] ?? 'spill-hold';
    $canEdit = auth()->user()->can('update', $campaign);
@endphp

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0">{{ $campaign->name }}</h4>
        <div class="text-muted small">
            <a href="{{ route('clients.show', $campaign->client_id) }}">{{ $campaign->client->client_name }}</a>
            @if($campaign->brand) &middot; {{ $campaign->brand->name }} @endif
            @if($campaign->platform) &middot; {{ $campaign->platform }} @endif
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="spill {{ $statusPill }}">{{ $campaign->status }}</span>
        @can('assign', \App\Models\AdCampaign::class)
        <button class="btn btn-sm btn-outline-secondary" id="assignBtn" data-bs-toggle="modal" data-bs-target="#assignModal">
            <i class="bi bi-person-check me-1"></i><span id="assignBtnLabel">{{ $campaign->assigned_to ? 'Reassign' : 'Assign' }}</span>
        </button>
        @endcan
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-2">
        <div class="card text-center py-3">
            <div class="fw-bold fs-5 mb-0" style="color:var(--primary)">৳{{ number_format((float) $campaign->budget, 0) }}</div>
            <div style="font-size:.65rem;color:var(--text3);text-transform:uppercase">Budget</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center py-3">
            <div class="fw-bold fs-5 mb-0 c-red" id="statSpend">৳{{ number_format($campaign->total_spend, 0) }}</div>
            <div style="font-size:.65rem;color:var(--text3);text-transform:uppercase">Total Spend</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center py-3">
            <div class="fw-bold fs-5 mb-0 c-green" id="statSales">৳{{ number_format($campaign->total_sales, 0) }}</div>
            <div style="font-size:.65rem;color:var(--text3);text-transform:uppercase">Total Sales</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center py-3">
            <div class="fw-bold fs-5 mb-0 c-yellow" id="statRoas">{{ $campaign->roas ?? '—' }}</div>
            <div style="font-size:.65rem;color:var(--text3);text-transform:uppercase">ROAS</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center py-3">
            <div class="fw-bold fs-5 mb-0 c-blue" id="statCpl">{{ $campaign->cpl !== null ? '৳'.number_format($campaign->cpl, 2) : '—' }}</div>
            <div style="font-size:.65rem;color:var(--text3);text-transform:uppercase">CPL</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center py-3">
            <div class="fw-bold fs-5 mb-0 c-purple" id="statCpa">{{ $campaign->cpa !== null ? '৳'.number_format($campaign->cpa, 2) : '—' }}</div>
            <div style="font-size:.65rem;color:var(--text3);text-transform:uppercase">CPA</div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3">
        <h6 class="fw-bold mb-0">Daily Trend</h6>
    </div>
    <div class="card-body">
        <div style="height:260px"><canvas id="trendChart"></canvas></div>
    </div>
</div>

<div class="row g-3">
    @if($canEdit)
    <div class="col-md-4">
        <div class="card">
            <div class="card-header py-3"><h6 class="fw-bold mb-0">Log Daily Report</h6></div>
            <div class="card-body">
                <div class="mb-2">
                    <label class="form-label fw-semibold small">Date</label>
                    <input type="date" id="repDate" class="form-control" value="{{ date('Y-m-d') }}">
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold small">Spend</label>
                    <input type="number" id="repSpend" class="form-control" step="0.01" placeholder="0.00">
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold small">Sales</label>
                    <input type="number" id="repSales" class="form-control" step="0.01" placeholder="0.00">
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold small">Leads</label>
                    <input type="number" id="repLeads" class="form-control" placeholder="0">
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold small">Orders</label>
                    <input type="number" id="repOrders" class="form-control" placeholder="0">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Remarks</label>
                    <textarea id="repRemarks" class="form-control" rows="2"></textarea>
                </div>
                <button id="saveReport" class="btn btn-primary btn-sm w-100"><i class="bi bi-check me-1"></i>Save Report</button>
            </div>
        </div>
    </div>
    @endif

    <div class="{{ $canEdit ? 'col-md-8' : 'col-12' }}">
        <div class="card">
            <div class="card-header py-3"><h6 class="fw-bold mb-0">Daily Reports</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" style="font-size:.83rem">
                        <thead><tr><th>Date</th><th>Spend</th><th>Sales</th><th>Leads</th><th>Orders</th><th>ROAS</th>@if($canEdit)<th></th>@endif</tr></thead>
                        <tbody id="reportsTableBody">
                        @forelse($campaign->dailyReports->sortByDesc('report_date') as $r)
                            <tr>
                                <td>{{ $r->report_date->format('d M Y') }}</td>
                                <td>৳{{ number_format((float) $r->spend, 2) }}</td>
                                <td>৳{{ number_format((float) $r->sales, 2) }}</td>
                                <td>{{ $r->leads }}</td>
                                <td>{{ $r->orders }}</td>
                                <td>{{ $r->roas ?? '—' }}</td>
                                @if($canEdit)
                                <td><button class="btn btn-xs btn-outline-danger delete-report" data-id="{{ $r->id }}"><i class="bi bi-trash"></i></button></td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canEdit ? 7 : 6 }}" class="text-center py-4 text-muted">No daily reports logged yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@can('assign', \App\Models\AdCampaign::class)
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold">Assign Campaign</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold small">Assign To</label>
                <select id="assignNewUser" class="form-select mb-3">
                    @foreach($users as $u)
                    <option value="{{ $u->id }}" {{ $campaign->assigned_to === $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                    @endforeach
                </select>
                <label class="form-label fw-semibold small">Note</label>
                <textarea id="assignNote" class="form-control" rows="2"></textarea>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="saveAssign" class="btn btn-sm btn-primary">Assign</button>
            </div>
        </div>
    </div>
</div>
@endcan
@endsection

@push('scripts')
<script>
var campaignBase = '/clients/{{ $campaign->client_id }}/ads/{{ $campaign->id }}';
var canEditReports = @json($canEdit);
var reports = @json($campaign->dailyReports->sortByDesc('report_date')->values());
var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function dateParts(iso) {
    var p = iso.substring(0, 10).split('-');
    return { y: p[0], m: parseInt(p[1], 10) - 1, d: parseInt(p[2], 10) };
}
function formatDate(iso) {
    var p = dateParts(iso);
    return String(p.d).padStart(2, '0') + ' ' + MONTHS[p.m] + ' ' + p.y;
}
function formatDateShort(iso) {
    var p = dateParts(iso);
    return String(p.d).padStart(2, '0') + ' ' + MONTHS[p.m];
}
function formatMoney(v) {
    return v.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}
function setBtnLoading($btn, loading) {
    if (loading) {
        $btn.data('orig-html', $btn.html());
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    } else {
        $btn.prop('disabled', false).html($btn.data('orig-html'));
    }
}

var ct = chartTheme();
Chart.defaults.color = ct.textColor;
Chart.defaults.borderColor = ct.gridColor;
Chart.defaults.font = { family: 'Inter, sans-serif', size: 11 };

var trendCtx = document.getElementById('trendChart').getContext('2d');
var trendChart = new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: @json($chart['labels']),
        datasets: [
            { label: 'Spend', data: @json($chart['spend']), borderColor: '#dc2626', tension: .35, pointRadius: 3 },
            { label: 'Sales', data: @json($chart['sales']), borderColor: '#059669', tension: .35, pointRadius: 3 },
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: true } },
        scales: {
            x: { grid: { color: ct.gridColor, drawTicks: false }, ticks: { color: ct.textColor } },
            y: { beginAtZero: true, grid: { color: ct.gridColor }, ticks: { color: ct.textColor } }
        }
    }
});

function renderReportsTable() {
    var rowsHtml = reports.map(function (r) {
        var spend = parseFloat(r.spend), sales = parseFloat(r.sales);
        var roas = spend > 0 ? (sales / spend).toFixed(2) : '—';
        var delCell = canEditReports ? '<td><button class="btn btn-xs btn-outline-danger delete-report" data-id="' + r.id + '"><i class="bi bi-trash"></i></button></td>' : '';
        return '<tr>' +
            '<td>' + formatDate(r.report_date) + '</td>' +
            '<td>৳' + spend.toFixed(2) + '</td>' +
            '<td>৳' + sales.toFixed(2) + '</td>' +
            '<td>' + r.leads + '</td>' +
            '<td>' + r.orders + '</td>' +
            '<td>' + roas + '</td>' + delCell +
            '</tr>';
    }).join('');
    if (!rowsHtml) {
        rowsHtml = '<tr><td colspan="' + (canEditReports ? 7 : 6) + '" class="text-center py-4 text-muted">No daily reports logged yet.</td></tr>';
    }
    $('#reportsTableBody').html(rowsHtml);
}

function renderTrendChart() {
    var sorted = reports.slice().sort(function (a, b) { return a.report_date < b.report_date ? -1 : 1; });
    trendChart.data.labels = sorted.map(function (r) { return formatDateShort(r.report_date); });
    trendChart.data.datasets[0].data = sorted.map(function (r) { return parseFloat(r.spend); });
    trendChart.data.datasets[1].data = sorted.map(function (r) { return parseFloat(r.sales); });
    trendChart.update();
}

function renderStats() {
    var totalSpend = 0, totalSales = 0, totalLeads = 0, totalOrders = 0;
    reports.forEach(function (r) {
        totalSpend += parseFloat(r.spend);
        totalSales += parseFloat(r.sales);
        totalLeads += parseInt(r.leads, 10);
        totalOrders += parseInt(r.orders, 10);
    });
    var roas = totalSpend > 0 ? (totalSales / totalSpend).toFixed(2) : null;
    var cpl = totalLeads > 0 ? (totalSpend / totalLeads).toFixed(2) : null;
    var cpa = totalOrders > 0 ? (totalSpend / totalOrders).toFixed(2) : null;

    $('#statSpend').text('৳' + formatMoney(totalSpend));
    $('#statSales').text('৳' + formatMoney(totalSales));
    $('#statRoas').text(roas !== null ? roas : '—');
    $('#statCpl').text(cpl !== null ? '৳' + cpl : '—');
    $('#statCpa').text(cpa !== null ? '৳' + cpa : '—');
}

function refreshReportViews() {
    reports.sort(function (a, b) { return a.report_date < b.report_date ? 1 : -1; });
    renderReportsTable();
    renderTrendChart();
    renderStats();
}

function upsertReportRow(report) {
    var idx = reports.findIndex(function (r) { return r.id === report.id; });
    if (idx === -1) idx = reports.findIndex(function (r) { return r.report_date === report.report_date; });
    if (idx !== -1) reports[idx] = report; else reports.push(report);
    refreshReportViews();
}

function removeReportRow(id) {
    reports = reports.filter(function (r) { return r.id !== id; });
    refreshReportViews();
}

@if($canEdit)
$('#saveReport').on('click', function () {
    var $btn = $(this);
    setBtnLoading($btn, true);
    $.post(campaignBase + '/reports', {
        report_date: $('#repDate').val(),
        spend: $('#repSpend').val() || 0,
        sales: $('#repSales').val() || 0,
        leads: $('#repLeads').val() || 0,
        orders: $('#repOrders').val() || 0,
        remarks: $('#repRemarks').val()
    }).done(function (r) {
        upsertReportRow(r.data);
        $('#repDate').val('{{ date('Y-m-d') }}');
        $('#repSpend,#repSales,#repLeads,#repOrders,#repRemarks').val('');
        Swal.fire({ icon: 'success', title: 'Saved', timer: 1000, showConfirmButton: false });
    }).fail(function (r) {
        Swal.fire('Error', r.responseJSON?.message || 'Could not save report.', 'error');
    }).always(function () {
        setBtnLoading($btn, false);
    });
});

$(document).on('click', '.delete-report', function () {
    var id = $(this).data('id');
    var $btn = $(this);
    Swal.fire({ title: 'Delete this report?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545' })
    .then(function (r) {
        if (r.isConfirmed) {
            $btn.prop('disabled', true);
            $.ajax({ url: campaignBase + '/reports/' + id, type: 'DELETE' }).done(function () {
                removeReportRow(id);
            }).fail(function (r) {
                $btn.prop('disabled', false);
                Swal.fire('Error', r.responseJSON?.message || 'Could not delete report.', 'error');
            });
        }
    });
});
@endif

$('#saveAssign').on('click', function () {
    var $btn = $(this);
    setBtnLoading($btn, true);
    $.post(campaignBase + '/assign', {
        new_assignee_id: $('#assignNewUser').val(),
        note: $('#assignNote').val()
    }).done(function (r) {
        bootstrap.Modal.getInstance('#assignModal').hide();
        $('#assignBtnLabel').text(r.data.assigned_to ? 'Reassign' : 'Assign');
        $('#assignNewUser').val(r.data.assigned_to);
        $('#assignNote').val('');
        Swal.fire({ icon: 'success', title: 'Assigned', timer: 1000, showConfirmButton: false });
    }).fail(function (r) {
        Swal.fire('Error', r.responseJSON?.message || 'Could not assign campaign.', 'error');
    }).always(function () {
        setBtnLoading($btn, false);
    });
});
</script>
@endpush
