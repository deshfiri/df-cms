@extends('layouts.app')
@section('title', $brand->name . ' · Marketing')

@push('styles')
<style>
    .mk-context { display: flex; flex-wrap: wrap; gap: .6rem; align-items: center; }
    .mk-context .form-select, .mk-context .form-control { width: auto; min-width: 170px; }

    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(158px, 1fr)); gap: var(--space-3); }
    .kpi {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius-md); padding: var(--space-4);
    }
    .kpi-label { font-size: var(--fs-2xs); text-transform: uppercase; letter-spacing: .05em; color: var(--text3); }
    .kpi-value { font-size: 1.35rem; font-weight: 700; color: var(--text); line-height: 1.1; margin-top: 3px; }
    .kpi-value.muted { color: var(--text3); font-size: 1rem; font-weight: 500; }

    .sync-bar {
        display: flex; align-items: center; gap: .8rem; flex-wrap: wrap;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); padding: .7rem 1rem;
    }
    .sync-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
    .sync-dot.ok { background: var(--c-green); }
    .sync-dot.warn { background: var(--c-yellow); }
    .sync-dot.off { background: var(--text3); }
</style>
@endpush

@section('content')
{{-- Context: the user must always see which brand / platform / account they are looking at. --}}
<div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-megaphone me-2"></i>{{ $brand->name }}</h4>
        <small style="color:var(--text3)">
            {{ $brand->client->client_name ?? '—' }}
            &nbsp;·&nbsp; <a href="{{ route('marketing.index') }}" style="color:var(--primary)">Switch brand</a>
            &nbsp;·&nbsp; <a href="{{ route('marketing.browse', $brand) }}" style="color:var(--primary)">Campaigns, ad sets &amp; ads</a>
        </small>
    </div>
    <div class="mk-context">
        <select id="mkAdAccount" class="form-select form-select-sm">
            <option value="">All ad accounts</option>
            @foreach($adAccounts as $account)
                <option value="{{ $account->id }}">{{ $account->name ?: $account->external_id }}</option>
            @endforeach
        </select>
        <select id="mkRange" class="form-select form-select-sm">
            @foreach($presets as $key => $preset)
                <option value="{{ $key }}" @selected($key === 'last_30')>{{ $preset['label'] }}</option>
            @endforeach
            <option value="custom">Custom Range</option>
        </select>
        <input type="date" id="mkFrom" class="form-control form-control-sm d-none">
        <input type="date" id="mkTo" class="form-control form-control-sm d-none">
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success py-2" style="font-size:.84rem">{{ session('success') }}</div>
@endif
@error('integration')
    <div class="alert alert-danger py-2" style="font-size:.84rem">{{ $message }}</div>
@enderror

{{-- ── Connection + sync state ─────────────────────────────────────────── --}}
<div class="sync-bar mb-3" id="syncBar">
    @php
        $connected = $meta && $meta->status === 'connected';
        $expired   = $meta && ($meta->status === 'token_expired' || $meta->tokenHasExpired());
    @endphp
    <span class="sync-dot {{ $expired ? 'warn' : ($connected ? 'ok' : 'off') }}"></span>

    <div class="flex-grow-1">
        <div style="font-size:.85rem;font-weight:600;color:var(--text)">
            Meta —
            @if($expired) Needs reconnecting
            @elseif($connected) Connected{{ $meta->metadata['account_name'] ?? null ? ' as ' . $meta->metadata['account_name'] : '' }}
            @else Not connected
            @endif
        </div>
        <div style="font-size:.72rem;color:var(--text3)" id="syncMeta">
            @if($connected || $expired)
                Last sync: {{ $meta->last_synced_at?->format('d M Y, h:i A') ?? 'never' }}
                @if($meta->nextSyncAt()) &nbsp;·&nbsp; Next: {{ $meta->nextSyncAt()->format('d M Y, h:i A') }} @endif
            @else
                Connect Meta to pull campaigns, ads and daily performance into this dashboard.
            @endif
        </div>
        @if($meta?->last_error)
            <div style="font-size:.72rem;color:var(--c-red);margin-top:2px">{{ $meta->last_error }}</div>
        @endif
    </div>

    @can('manage', $brand)
        @if($connected && !$expired)
            <button class="btn btn-sm btn-outline-secondary" id="mkResources" data-integration="{{ $meta->id }}">
                <i class="bi bi-sliders me-1"></i>Resources
            </button>
            <button class="btn btn-sm btn-primary" id="mkSyncNow" data-integration="{{ $meta->id }}">
                <i class="bi bi-arrow-repeat me-1"></i>Sync now
            </button>
            <button class="btn btn-sm btn-outline-danger" id="mkDisconnect" data-integration="{{ $meta->id }}">
                <i class="bi bi-x-circle"></i>
            </button>
        @else
            <a href="{{ route('marketing.meta.connect', $brand) }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plug me-1"></i>{{ $expired ? 'Reconnect Meta' : 'Connect Meta' }}
            </a>
        @endif
    @endcan
</div>

{{-- ── KPIs ────────────────────────────────────────────────────────────── --}}
<div class="kpi-grid mb-3" id="kpiGrid">
    <div class="kpi"><div class="kpi-label">Loading…</div></div>
</div>

{{-- Hand-entered figures, shown separately so the two sources never blur. --}}
<div class="card section-card mb-3 d-none" id="manualCard">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0"><i class="bi bi-pencil-square me-1"></i>Manual records</h6>
        <span class="spill spill-hold">Entered by hand · not from Meta</span>
    </div>
    <div class="card-body">
        <div class="kpi-grid" id="manualGrid"></div>
        <div style="font-size:.72rem;color:var(--text3);margin-top:.6rem">
            From the existing Ads module. Kept separate from the synced totals above —
            adding the two together would reconcile with neither source.
        </div>
    </div>
</div>

{{-- ── Charts ──────────────────────────────────────────────────────────── --}}
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card section-card h-100">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">Performance over time</h6>
                <select id="mkMetric" class="form-select form-select-sm" style="width:auto">
                    <option value="spend">Spend</option>
                    <option value="impressions">Impressions</option>
                    <option value="reach">Reach</option>
                    <option value="clicks">Clicks</option>
                    <option value="conversions">Conversions</option>
                    <option value="conversion_value">Conversion value</option>
                    <option value="roas">ROAS</option>
                    <option value="cpc">CPC</option>
                    <option value="cpm">CPM</option>
                </select>
            </div>
            <div class="card-body"><div style="height:260px"><canvas id="mkTrend"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card section-card h-100">
            <div class="card-header py-2"><h6 class="fw-bold mb-0">Recent syncs</h6></div>
            <div class="card-body p-0" id="syncLogList" style="max-height:300px;overflow-y:auto"></div>
        </div>
    </div>
</div>

{{-- ── Campaign table ──────────────────────────────────────────────────── --}}
<div class="card section-card">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">Campaign performance</h6>
        <span style="font-size:.72rem;color:var(--text3)" id="rangeLabel"></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
                <thead>
                    <tr>
                        <th>Campaign</th><th>Status</th><th class="text-end">Spend</th>
                        <th class="text-end">Impressions</th><th class="text-end">Reach</th>
                        <th class="text-end">Clicks</th><th class="text-end">CTR</th>
                        <th class="text-end">CPC</th><th class="text-end">CPM</th>
                        <th class="text-end">Conv.</th><th class="text-end">CPA</th><th class="text-end">ROAS</th>
                    </tr>
                </thead>
                <tbody id="campaignRows">
                    <tr><td colspan="12" class="text-center py-4" style="color:var(--text3)">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Resource selection --}}
<div class="modal fade" id="resourceModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2 px-3">
                <h6 class="modal-title fw-bold">Meta resources for {{ $brand->name }}</h6>
                <button class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-3 py-3" id="resourceBody">
                <div class="text-center py-4" style="color:var(--text3)">Loading…</div>
            </div>
            <div class="modal-footer py-2 px-3">
                <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-sm btn-primary" id="resourceSave">Save selection</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
{{-- Chart.js is not app-wide; this page draws charts. --}}
<script src="{{ App\Support\ShellAsset::url('vendor/js/chart.umd.min.js') }}"></script>
<script>
const BRAND_ID   = {{ $brand->id }};
const DASH_URL   = '{{ route('marketing.dashboard', $brand) }}';
const LOGS_URL   = '{{ route('marketing.sync-logs', $brand) }}';
let   trendChart = null;
let   lastSeries = null;

function money(v, currency) {
    if (v === null || v === undefined) return '—';
    return (currency ? currency + ' ' : '') + Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 });
}
function num(v) { return (v === null || v === undefined) ? '—' : Number(v).toLocaleString(); }
function rate(v, suffix) { return (v === null || v === undefined) ? '—' : Number(v).toFixed(2) + (suffix || ''); }

function filters() {
    const range = $('#mkRange').val();
    return {
        range: range,
        from: range === 'custom' ? $('#mkFrom').val() : null,
        to:   range === 'custom' ? $('#mkTo').val() : null,
        ad_account_id: $('#mkAdAccount').val() || null,
    };
}

// Custom range only makes sense with both ends filled in.
$('#mkRange').on('change', function () {
    const custom = $(this).val() === 'custom';
    $('#mkFrom,#mkTo').toggleClass('d-none', !custom);
    if (!custom) loadDashboard();
});
$('#mkFrom,#mkTo').on('change', function () {
    if ($('#mkFrom').val() && $('#mkTo').val()) loadDashboard();
});
$('#mkAdAccount').on('change', loadDashboard);
$('#mkMetric').on('change', () => drawChart($('#mkMetric').val()));

function loadDashboard() {
    $.get(DASH_URL, filters()).done(function (r) {
        $('#rangeLabel').text(r.range.label + ' · ' + r.range.from + ' → ' + r.range.to);
        renderKpis(r.summary);
        renderManual(r.manual, r.summary.currency);
        renderCampaigns(r.campaigns, r.summary.currency);
        lastSeries = r.series;
        drawChart($('#mkMetric').val());
    }).fail(function (x) {
        $('#kpiGrid').html('<div class="kpi"><div class="kpi-label">Error</div><div class="kpi-value muted">'
            + (x.responseJSON?.message || 'Could not load the dashboard.') + '</div></div>');
    });
}

function renderKpis(s) {
    // No synced rows at all: say so rather than showing a grid of zeros.
    if (!s.has_data) {
        $('#kpiGrid').html('<div class="kpi" style="grid-column:1/-1">'
            + '<div class="kpi-label">No data yet</div>'
            + '<div class="kpi-value muted">Nothing has been synced for this range. '
            + 'Connect Meta and run a sync, or widen the date range.</div></div>');
        return;
    }

    const c = s.currency;
    const cards = [
        ['Spend', money(s.spend, c)],
        ['Impressions', num(s.impressions)],
        ['Reach', num(s.reach)],
        ['Clicks', num(s.clicks)],
        ['CTR', rate(s.ctr, '%')],
        ['CPC', money(s.cpc, c)],
        ['CPM', money(s.cpm, c)],
        ['Conversions', num(s.conversions)],
        ['Cost / conv.', money(s.cost_per_conversion, c)],
        ['Conv. value', money(s.conversion_value, c)],
        ['ROAS', rate(s.roas, 'x')],
    ];

    $('#kpiGrid').html(cards.map(function (card) {
        const isMissing = card[1] === '—';
        return '<div class="kpi"><div class="kpi-label">' + card[0] + '</div>'
             + '<div class="kpi-value' + (isMissing ? ' muted' : '') + '">' + card[1] + '</div></div>';
    }).join(''));
}

// Only shown when there actually are hand-entered rows for the range.
function renderManual(m, currency) {
    if (!m || !m.has_data) { $('#manualCard').addClass('d-none'); return; }

    $('#manualCard').removeClass('d-none');
    $('#manualGrid').html([
        ['Spend', money(m.spend, currency)],
        ['Sales', money(m.sales, currency)],
        ['Leads', num(m.leads)],
        ['Orders', num(m.orders)],
        ['ROAS', rate(m.roas, 'x')],
        ['Days recorded', num(m.days)],
    ].map(function (card) {
        return '<div class="kpi"><div class="kpi-label">' + card[0] + '</div>'
             + '<div class="kpi-value' + (card[1] === '—' ? ' muted' : '') + '">' + card[1] + '</div></div>';
    }).join(''));
}

function renderCampaigns(rows, currency) {
    if (!rows.length) {
        $('#campaignRows').html('<tr><td colspan="12" class="text-center py-4" style="color:var(--text3)">'
            + 'No campaign data for this range.</td></tr>');
        return;
    }

    $('#campaignRows').html(rows.map(function (r) {
        return '<tr>'
            + '<td>' + $('<div>').text(r.campaign).html() + '<div style="font-size:.68rem;color:var(--text3)">'
              + $('<div>').text(r.objective || '').html() + '</div></td>'
            + '<td><span class="spill ' + (r.status === 'ACTIVE' ? 'spill-completed' : 'spill-hold') + '">'
              + $('<div>').text(r.status || '—').html() + '</span></td>'
            + '<td class="text-end">' + money(r.spend, currency) + '</td>'
            + '<td class="text-end">' + num(r.impressions) + '</td>'
            + '<td class="text-end">' + num(r.reach) + '</td>'
            + '<td class="text-end">' + num(r.clicks) + '</td>'
            + '<td class="text-end">' + rate(r.ctr, '%') + '</td>'
            + '<td class="text-end">' + money(r.cpc, currency) + '</td>'
            + '<td class="text-end">' + money(r.cpm, currency) + '</td>'
            + '<td class="text-end">' + num(r.conversions) + '</td>'
            + '<td class="text-end">' + money(r.cpa, currency) + '</td>'
            + '<td class="text-end">' + rate(r.roas, 'x') + '</td>'
            + '</tr>';
    }).join(''));
}

function drawChart(metric) {
    if (!lastSeries) return;

    const theme = window.chartTheme ? window.chartTheme() : { grid: '#e2e8f0', text: '#475569' };
    const ctx = document.getElementById('mkTrend');

    if (trendChart) trendChart.destroy();

    trendChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: lastSeries.labels,
            datasets: [{
                label: $('#mkMetric option:selected').text(),
                data: lastSeries.series[metric],
                borderColor: getComputedStyle(document.documentElement).getPropertyValue('--primary').trim(),
                backgroundColor: 'rgba(var(--primary-rgb), .12)',
                fill: true, tension: .3, spanGaps: true,
            }],
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { color: theme.grid }, ticks: { color: theme.text } },
                y: { grid: { color: theme.grid }, ticks: { color: theme.text }, beginAtZero: true },
            },
        },
    });

    window._charts = [trendChart];
}

function loadSyncLogs() {
    $.get(LOGS_URL).done(function (r) {
        if (!r.logs.length) {
            $('#syncLogList').html('<div class="p-3" style="font-size:.78rem;color:var(--text3)">No syncs yet.</div>');
            return;
        }

        $('#syncLogList').html(r.logs.map(function (log) {
            const pill = { success: 'spill-completed', failed: 'spill-cancelled', running: 'spill-running' }[log.status] || 'spill-hold';
            return '<div class="p-2 px-3 border-bottom" style="font-size:.75rem">'
                + '<div class="d-flex justify-content-between align-items-center gap-2">'
                + '<span class="spill ' + pill + '">' + log.status + '</span>'
                + '<span style="color:var(--text3)">' + log.started_human + '</span></div>'
                + '<div style="color:var(--text2);margin-top:2px">'
                + log.records_processed + ' record(s)'
                + (log.sync_type === 'manual' ? ' · manual' : '')
                + (log.triggered_by ? ' · ' + $('<div>').text(log.triggered_by).html() : '')
                + '</div>'
                + (log.error_message ? '<div style="color:var(--c-red)">' + $('<div>').text(log.error_message).html() + '</div>' : '')
                + '</div>';
        }).join(''));
    });
}

// ── Manual sync ──────────────────────────────────────────────────────────
$('#mkSyncNow').on('click', function () {
    const $btn = $(this).prop('disabled', true).html('<i class="bi bi-arrow-repeat me-1"></i>Queued…');

    $.post('/marketing/integrations/' + $(this).data('integration') + '/sync')
        .done(function () {
            Swal.fire({ icon: 'success', title: 'Sync queued', text: 'Data refreshes once the worker picks it up.', timer: 2200, showConfirmButton: false });
            // Give the worker a moment before re-reading.
            setTimeout(function () { loadSyncLogs(); loadDashboard(); }, 4000);
        })
        .fail(x => Swal.fire('Not started', x.responseJSON?.message || 'Could not queue the sync.', 'warning'))
        .always(() => $btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat me-1"></i>Sync now'));
});

$('#mkDisconnect').on('click', function () {
    const id = $(this).data('integration');
    Swal.fire({
        icon: 'warning', title: 'Disconnect Meta?',
        text: 'Syncing stops. Data already pulled in stays.',
        showCancelButton: true, confirmButtonText: 'Disconnect', confirmButtonColor: '#dc3545',
    }).then(function (r) {
        if (!r.isConfirmed) return;
        $.post('/marketing/integrations/' + id + '/disconnect').done(() => location.reload());
    });
});

// ── Resource selection ───────────────────────────────────────────────────
const RESOURCE_LABELS = {
    ad_account: 'Ad accounts', page: 'Facebook pages', instagram: 'Instagram accounts',
    pixel: 'Pixels / datasets', business: 'Business accounts',
};

$('#mkResources').on('click', function () {
    const id = $(this).data('integration');
    $('#resourceSave').data('integration', id);
    $('#resourceBody').html('<div class="text-center py-4" style="color:var(--text3)">Loading…</div>');
    new bootstrap.Modal('#resourceModal').show();

    $.get('/marketing/integrations/' + id + '/discover').done(function (r) {
        let html = '';
        Object.keys(RESOURCE_LABELS).forEach(function (type) {
            const rows = r.available[type] || [];
            const selected = r.selected[type] || [];

            html += '<div class="mb-3"><div class="fw-semibold mb-1" style="font-size:.8rem">'
                  + RESOURCE_LABELS[type] + '</div>';

            if (!rows.length) {
                html += '<div style="font-size:.75rem;color:var(--text3)">None available on this account.</div></div>';
                return;
            }

            rows.forEach(function (row) {
                const checked = selected.indexOf(row.external_id) !== -1 ? 'checked' : '';
                html += '<label class="d-flex align-items-center gap-2 py-1" style="font-size:.8rem;cursor:pointer">'
                     + '<input type="checkbox" class="form-check-input mt-0 res-check" data-type="' + type + '" '
                     + 'value="' + $('<div>').text(row.external_id).html() + '" ' + checked + '>'
                     + $('<div>').text(row.name).html()
                     + ' <span style="color:var(--text3);font-size:.7rem">' + $('<div>').text(row.external_id).html() + '</span>'
                     + '</label>';
            });

            html += '</div>';
        });

        $('#resourceBody').html(html || '<div style="color:var(--text3)">Nothing available.</div>');
    }).fail(function (x) {
        $('#resourceBody').html('<div class="alert alert-danger py-2" style="font-size:.8rem">'
            + (x.responseJSON?.message || 'Could not read resources from Meta.') + '</div>');
    });
});

$('#resourceSave').on('click', function () {
    const id = $(this).data('integration');
    const selection = {};

    $('.res-check:checked').each(function () {
        const type = $(this).data('type');
        (selection[type] = selection[type] || []).push($(this).val());
    });

    const $btn = $(this).prop('disabled', true);

    $.post('/marketing/integrations/' + id + '/resources', { selection: selection })
        .done(function () {
            bootstrap.Modal.getInstance('#resourceModal').hide();
            Swal.fire({ icon: 'success', title: 'Saved', timer: 1300, showConfirmButton: false });
        })
        .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Could not save.', 'error'))
        .always(() => $btn.prop('disabled', false));
});

loadDashboard();
loadSyncLogs();
</script>
@endpush
