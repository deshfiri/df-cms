@extends('layouts.app')
@section('title', $brand->name . ' · Campaigns')

@push('styles')
<style>
    .br-tabs { display: flex; gap: .3rem; margin-bottom: 1rem; flex-wrap: wrap; }
    .br-tab {
        cursor: pointer; border: 1px solid var(--border); background: var(--surface);
        color: var(--text2); font-size: .78rem; font-weight: 600;
        padding: .35rem .8rem; border-radius: var(--radius);
        display: inline-flex; align-items: center; gap: .35rem;
    }
    .br-tab.active { background: rgba(var(--primary-rgb), .12); border-color: var(--primary); color: var(--primary); }

    /* The trail of what you drilled through, and the way back out. */
    .br-crumb {
        display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
        font-size: .75rem; color: var(--text3); margin-bottom: .7rem;
    }
    .br-crumb .chip {
        display: inline-flex; align-items: center; gap: .4rem;
        background: var(--surface2); border: 1px solid var(--border);
        border-radius: 999px; padding: .15rem .6rem; color: var(--text2);
    }
    .br-crumb .chip button { border: none; background: none; color: var(--text3); padding: 0; line-height: 1; cursor: pointer; }

    .br-name { font-weight: 600; color: var(--text); }
    .br-sub { font-size: .68rem; color: var(--text3); font-family: Menlo, Consolas, monospace; }
    .br-drill { color: var(--primary); cursor: pointer; }
    .br-drill:hover { text-decoration: underline; }
    .br-creative { max-width: 340px; }
    .br-thumb { width: 40px; height: 40px; object-fit: cover; border-radius: 6px; flex-shrink: 0; }
</style>
@endpush

@section('content')
<div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-list-columns-reverse me-2"></i>{{ $brand->name }}</h4>
        <small style="color:var(--text3)">
            {{ $brand->client->client_name ?? '—' }}
            &nbsp;·&nbsp; <a href="{{ route('marketing.brand', $brand) }}" style="color:var(--primary)">Back to dashboard</a>
        </small>
    </div>
    <select id="brAdAccount" class="form-select form-select-sm" style="width:auto;min-width:190px">
        <option value="">All ad accounts</option>
        @foreach($adAccounts as $account)
            <option value="{{ $account->id }}">{{ $account->name ?: $account->external_id }}</option>
        @endforeach
    </select>
</div>

<div class="br-tabs">
    <button type="button" class="br-tab active" data-tab="campaigns"><i class="bi bi-collection"></i>Campaigns</button>
    <button type="button" class="br-tab" data-tab="adsets"><i class="bi bi-diagram-3"></i>Ad Sets</button>
    <button type="button" class="br-tab" data-tab="ads"><i class="bi bi-image"></i>Ads</button>
</div>

<div class="br-crumb d-none" id="brCrumb"></div>

<div class="card section-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
                <thead id="brHead"></thead>
                <tbody id="brBody">
                    <tr><td class="text-center py-4" style="color:var(--text3)">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer py-2 d-flex justify-content-between align-items-center" id="brFooter" style="display:none">
        <span style="font-size:.74rem;color:var(--text3)" id="brCount"></span>
        <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-secondary" id="brPrev">Previous</button>
            <button class="btn btn-outline-secondary" id="brNext">Next</button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const URLS = {
    campaigns: '{{ route('marketing.campaigns', $brand) }}',
    adsets:    '{{ route('marketing.ad-sets', $brand) }}',
    ads:       '{{ route('marketing.ads', $brand) }}',
};

let tab = 'campaigns';
let page = 1;
// What we drilled into. Cleared when switching tabs from the tab bar.
let context = { campaign_id: null, campaign_name: null, ad_set_id: null, ad_set_name: null };

const esc = s => $('<div>').text(s == null ? '' : s).html();
const money = v => (v === null || v === undefined) ? '—' : Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 });
const dt = s => s ? new Date(s).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

function statusPill(status) {
    const map = { ACTIVE: 'spill-completed', PAUSED: 'spill-warning', DELETED: 'spill-cancelled', ARCHIVED: 'spill-hold' };
    return '<span class="spill ' + (map[status] || 'spill-hold') + '">' + esc(status || '—') + '</span>';
}

const COLUMNS = {
    campaigns: ['Campaign', 'Status', 'Objective', 'Ad account', 'Daily budget', 'Lifetime budget', ''],
    adsets:    ['Ad set', 'Status', 'Campaign', 'Optimisation', 'Daily budget', 'Schedule', ''],
    ads:       ['Ad', 'Status', 'Ad set', 'Creative', 'Destination', ''],
};

function renderCrumb() {
    const parts = [];

    if (context.campaign_name) {
        parts.push('<span class="chip">Campaign: <span class="br-name">' + esc(context.campaign_name) + '</span>'
                 + '<button data-clear="campaign" title="Clear"><i class="bi bi-x-lg"></i></button></span>');
    }
    if (context.ad_set_name) {
        parts.push('<span class="chip">Ad set: <span class="br-name">' + esc(context.ad_set_name) + '</span>'
                 + '<button data-clear="adset" title="Clear"><i class="bi bi-x-lg"></i></button></span>');
    }

    $('#brCrumb').toggleClass('d-none', parts.length === 0).html(
        parts.length ? '<i class="bi bi-funnel"></i>' + parts.join('') : ''
    );
}

function load() {
    $('#brHead').html('<tr>' + COLUMNS[tab].map(c => '<th>' + c + '</th>').join('') + '</tr>');
    $('#brBody').html('<tr><td colspan="' + COLUMNS[tab].length + '" class="text-center py-4" style="color:var(--text3)">Loading…</td></tr>');

    const params = { page: page, ad_account_id: $('#brAdAccount').val() || null };
    if (tab === 'adsets' && context.campaign_id) params.campaign_id = context.campaign_id;
    if (tab === 'ads' && context.ad_set_id) params.ad_set_id = context.ad_set_id;

    $.get(URLS[tab], params).done(function (r) {
        renderCrumb();
        renderRows(r.data);

        $('#brFooter').css('display', r.total > 0 ? 'flex' : 'none');
        $('#brCount').text(r.total ? ('Showing ' + r.from + '–' + r.to + ' of ' + r.total) : '');
        $('#brPrev').prop('disabled', r.current_page <= 1);
        $('#brNext').prop('disabled', r.current_page >= r.last_page);
    }).fail(function (x) {
        $('#brBody').html('<tr><td colspan="' + COLUMNS[tab].length + '" class="text-center py-4" style="color:var(--c-red)">'
            + esc(x.responseJSON?.message || 'Could not load that list.') + '</td></tr>');
    });
}

function emptyRow(message) {
    return '<tr><td colspan="' + COLUMNS[tab].length + '" class="text-center py-5" style="color:var(--text3)">'
         + '<i class="bi bi-inbox d-block mb-2" style="font-size:1.6rem"></i>' + message + '</td></tr>';
}

function renderRows(rows) {
    if (!rows.length) {
        $('#brBody').html(emptyRow(
            'Nothing synced here yet. Connect Meta and run a sync from the dashboard.'
        ));
        return;
    }

    let html = '';

    if (tab === 'campaigns') {
        rows.forEach(function (c) {
            html += '<tr>'
                + '<td><div class="br-name">' + esc(c.name) + '</div><div class="br-sub">' + esc(c.external_id) + '</div></td>'
                + '<td>' + statusPill(c.status) + '</td>'
                + '<td>' + esc(c.objective || '—') + '</td>'
                + '<td>' + esc(c.ad_account?.name || '—') + '</td>'
                + '<td>' + money(c.daily_budget) + '</td>'
                + '<td>' + money(c.lifetime_budget) + '</td>'
                + '<td class="text-end"><span class="br-drill" data-drill="adsets" data-id="' + c.id + '" '
                  + 'data-name="' + esc(c.name) + '">Ad sets <i class="bi bi-chevron-right"></i></span></td>'
                + '</tr>';
        });
    }

    if (tab === 'adsets') {
        rows.forEach(function (s) {
            html += '<tr>'
                + '<td><div class="br-name">' + esc(s.name) + '</div><div class="br-sub">' + esc(s.external_id) + '</div></td>'
                + '<td>' + statusPill(s.status) + '</td>'
                + '<td>' + esc(s.campaign?.name || '—') + '</td>'
                + '<td>' + esc(s.optimization_goal || '—') + '</td>'
                + '<td>' + money(s.daily_budget) + '</td>'
                + '<td>' + dt(s.starts_at) + ' → ' + dt(s.ends_at) + '</td>'
                + '<td class="text-end"><span class="br-drill" data-drill="ads" data-id="' + s.id + '" '
                  + 'data-name="' + esc(s.name) + '">Ads <i class="bi bi-chevron-right"></i></span></td>'
                + '</tr>';
        });
    }

    if (tab === 'ads') {
        rows.forEach(function (a) {
            const thumb = a.thumbnail_url
                ? '<img src="' + esc(a.thumbnail_url) + '" class="br-thumb" alt="">'
                : '';

            html += '<tr>'
                + '<td><div class="d-flex align-items-center gap-2">' + thumb
                  + '<div><div class="br-name">' + esc(a.name) + '</div>'
                  + '<div class="br-sub">' + esc(a.external_id) + '</div></div></div></td>'
                + '<td>' + statusPill(a.status) + '</td>'
                + '<td>' + esc(a.ad_set?.name || '—') + '</td>'
                + '<td class="br-creative">'
                  + (a.headline ? '<div class="br-name">' + esc(a.headline) + '</div>' : '')
                  + (a.primary_text ? '<div style="color:var(--text3);font-size:.74rem">' + esc(a.primary_text) + '</div>' : '')
                  + (a.call_to_action ? '<span class="spill spill-hold mt-1">' + esc(a.call_to_action) + '</span>' : '')
                  + (!a.headline && !a.primary_text ? '—' : '')
                + '</td>'
                + '<td>' + (a.destination_url
                    ? '<a href="' + esc(a.destination_url) + '" target="_blank" rel="noopener" style="color:var(--primary)">Open <i class="bi bi-box-arrow-up-right"></i></a>'
                    : '—') + '</td>'
                + '<td class="text-end">' + (a.preview_url
                    ? '<a href="' + esc(a.preview_url) + '" target="_blank" rel="noopener" class="btn btn-sm px-2 py-1" '
                      + 'style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Preview on Meta"><i class="bi bi-eye"></i></a>'
                    : '') + '</td>'
                + '</tr>';
        });
    }

    $('#brBody').html(html);
}

// ── Navigation ───────────────────────────────────────────────────────────
$('.br-tab').on('click', function () {
    $('.br-tab').removeClass('active');
    $(this).addClass('active');

    // Picking a tab directly is a fresh start, not a drill-down.
    tab = $(this).data('tab');
    context = { campaign_id: null, campaign_name: null, ad_set_id: null, ad_set_name: null };
    page = 1;
    load();
});

$(document).on('click', '.br-drill', function () {
    const target = $(this).data('drill');

    if (target === 'adsets') {
        context.campaign_id = $(this).data('id');
        context.campaign_name = $(this).data('name');
        context.ad_set_id = context.ad_set_name = null;
    } else {
        context.ad_set_id = $(this).data('id');
        context.ad_set_name = $(this).data('name');
    }

    tab = target;
    page = 1;
    $('.br-tab').removeClass('active').filter('[data-tab="' + target + '"]').addClass('active');
    load();
});

$(document).on('click', '#brCrumb button', function () {
    if ($(this).data('clear') === 'campaign') {
        context = { campaign_id: null, campaign_name: null, ad_set_id: null, ad_set_name: null };
    } else {
        context.ad_set_id = context.ad_set_name = null;
    }
    page = 1;
    load();
});

$('#brAdAccount').on('change', function () { page = 1; load(); });
$('#brPrev').on('click', function () { if (page > 1) { page--; load(); } });
$('#brNext').on('click', function () { page++; load(); });

load();
</script>
@endpush
