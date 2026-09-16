@extends('layouts.app')
@section('title', 'Workflow Tracker')

@push('styles')
<style>
    .wt-tiles { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: .75rem; }
    @media (max-width: 991.98px) { .wt-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    .wt-tile {
        background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
        padding: .8rem .5rem; text-align: center; box-shadow: var(--shadow-sm);
    }
    .wt-tile-v { font-size: 1.5rem; font-weight: 700; line-height: 1.2; font-variant-numeric: tabular-nums; }
    .wt-tile-k { font-size: .66rem; color: var(--text3); text-transform: uppercase; letter-spacing: .04em; margin-top: 2px; }

    /* Progress bar reused from the Payments tab's styling vocabulary. */
    .pay-bar { height: 5px; border-radius: 5px; background: var(--border); overflow: hidden; }
    .pay-bar > span { display: block; height: 100%; background: var(--primary); border-radius: 5px; }

    #wtPanel { width: min(560px, 100%); }
    .wt-meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .6rem .9rem; }
    .wt-k { font-size: .64rem; text-transform: uppercase; letter-spacing: .05em; color: var(--text3); font-weight: 600; }
    .wt-v { font-size: .84rem; color: var(--text); word-break: break-word; }
    .wt-section { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text3); margin: 1.1rem 0 .45rem; }

    .wt-stage { display: flex; align-items: flex-start; gap: .55rem; padding: .35rem 0; }
    .wt-dot { width: 10px; height: 10px; border-radius: 50%; margin-top: .3rem; flex-shrink: 0; background: var(--border); }
    .wt-stage.done .wt-dot { background: var(--c-green); }
    .wt-stage.current .wt-dot { background: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb), .2); }
    .wt-stage.current .wt-stage-name { font-weight: 700; color: var(--primary); }
    .wt-stage-name { font-size: .82rem; color: var(--text); }
    .wt-stage-people { font-size: .68rem; color: var(--text3); }

    .wt-row { border: 1px solid var(--border); border-radius: var(--radius-sm); padding: .5rem .65rem; margin-bottom: .4rem; background: var(--surface2); }
    .wt-row-head { font-size: .78rem; color: var(--text); font-weight: 600; }
    .wt-row-sub { font-size: .68rem; color: var(--text3); }
    .wt-row-body { font-size: .8rem; color: var(--text2); white-space: pre-wrap; margin-top: 2px; }
    .wt-empty { font-size: .78rem; color: var(--text3); }
</style>
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        @if($canManage)
            <a href="{{ route('workflows.index') }}" class="small text-decoration-none" style="color:var(--text3)"><i class="bi bi-arrow-left me-1"></i>Workflows</a>
        @endif
        <h4 class="page-title mb-0 mt-1"><i class="bi bi-list-task me-2"></i>Workflow Tracker</h4>
        <small style="color:var(--text3)">Every item running across every workflow — where it is, who has it, and what has happened to it.</small>
    </div>
</div>

<div class="wt-tiles mb-3">
    <div class="wt-tile">
        <div class="wt-tile-v" style="color:var(--primary)" data-tile="running">0</div>
        <div class="wt-tile-k">Running</div>
    </div>
    <div class="wt-tile">
        <div class="wt-tile-v" style="color:var(--c-yellow)" data-tile="unclaimed">0</div>
        <div class="wt-tile-k">Unclaimed</div>
    </div>
    <div class="wt-tile">
        <div class="wt-tile-v c-red" data-tile="overdue">0</div>
        <div class="wt-tile-k">Overdue</div>
    </div>
    <div class="wt-tile">
        <div class="wt-tile-v" style="color:var(--c-yellow)" data-tile="stranded">0</div>
        <div class="wt-tile-k">Stranded</div>
    </div>
    <div class="wt-tile">
        <div class="wt-tile-v" style="color:var(--c-green)" data-tile="completed">0</div>
        <div class="wt-tile-k">Completed</div>
    </div>
</div>

{{-- Filters --}}
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <button class="fpill active" data-status="Open">Running</button>
    <button class="fpill" data-status="Completed">Completed</button>
    <button class="fpill" data-status="Cancelled">Cancelled</button>
    <button class="fpill" data-status="">All</button>

    <span style="width:1px;height:22px;background:var(--border)"></span>

    <button class="fpill" id="pillOverdue"><i class="bi bi-exclamation-triangle" style="font-size:.67rem"></i> Overdue</button>
    <button class="fpill" id="pillUnclaimed"><i class="bi bi-hand-index" style="font-size:.67rem"></i> Unclaimed</button>
    <button class="fpill" id="pillStranded" title="Sitting at a stage nobody is assigned to"><i class="bi bi-sign-stop" style="font-size:.67rem"></i> Stranded</button>

    <div class="ms-auto d-flex gap-2 flex-wrap">
        <select id="filterFlow" class="form-select form-select-sm" style="width:170px">
            <option value="">All workflows</option>
            @foreach($flows as $f)
                <option value="{{ $f->id }}" @selected((string) $flowId === (string) $f->id)>{{ $f->name }}</option>
            @endforeach
        </select>
        <select id="filterStage" class="form-select form-select-sm" style="width:170px">
            <option value="">Any stage</option>
            @foreach($stages->groupBy(fn ($s) => $s->flow->name ?? '—') as $flowName => $group)
                <optgroup label="{{ $flowName }}">
                    @foreach($group as $stage)
                        <option value="{{ $stage->id }}" data-flow="{{ $stage->flow_id }}">{{ $stage->position }}. {{ $stage->name }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        <select id="filterUser" class="form-select form-select-sm" style="width:170px">
            <option value="">Anyone</option>
            <option value="none">Unclaimed</option>
            @foreach($users as $u)
                <option value="{{ $u->id }}">{{ $u->name }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="card section-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            @include('partials.live-counts')
            <table id="itemsTable" class="table table-hover align-middle w-100 mb-0" style="font-size:.85rem">
                <thead>
                    <tr>
                        <th class="ps-3">Item</th>
                        <th>Priority</th>
                        <th>Workflow</th>
                        <th style="min-width:170px">Stage</th>
                        <th>With</th>
                        <th>Due</th>
                        <th>Status</th>
                        <th class="text-end pe-3">Details</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

{{-- Details panel --}}
<div class="offcanvas offcanvas-end" tabindex="-1" id="wtPanel" aria-labelledby="wtPanelTitle"
     style="background:var(--surface);color:var(--text)">
    <div class="offcanvas-header" style="border-bottom:1px solid var(--border)">
        <div class="min-w-0">
            <h6 class="offcanvas-title fw-bold text-truncate" id="wtPanelTitle">Item</h6>
            <div id="wtPanelBadges" class="d-flex gap-1 flex-wrap mt-1"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body" id="wtPanelBody">
        <div class="text-center py-5"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div></div>
    </div>
</div>
@endsection

@push('scripts')
<script>
var wtStatus = 'Open', wtOverdue = false, wtUnclaimed = false, wtStranded = false;
const wtEsc = s => $('<div>').text(s == null ? '' : s).html();

function wtSyncPills() {
    $('.fpill').removeClass('active');
    $('.fpill[data-status="' + wtStatus + '"]').addClass('active');
    $('#pillOverdue').toggleClass('active', wtOverdue);
    $('#pillUnclaimed').toggleClass('active', wtUnclaimed);
    $('#pillStranded').toggleClass('active', wtStranded);
}

$('.fpill[data-status]').on('click', function () {
    wtStatus = String($(this).data('status'));
    wtSyncPills();
    window.wtTable.ajax.reload();
});

$('#pillOverdue, #pillUnclaimed, #pillStranded').on('click', function () {
    const which = this.id;
    if (which === 'pillOverdue')   wtOverdue = !wtOverdue;
    if (which === 'pillUnclaimed') wtUnclaimed = !wtUnclaimed;
    if (which === 'pillStranded')  wtStranded = !wtStranded;
    // These only describe work in flight.
    if (wtOverdue || wtUnclaimed || wtStranded) wtStatus = 'Open';
    wtSyncPills();
    window.wtTable.ajax.reload();
});

$('#filterFlow, #filterStage, #filterUser').on('change', function () {
    // Stages belong to a workflow: keep the pair sensible.
    if (this.id === 'filterFlow') {
        const flow = $('#filterFlow').val();
        $('#filterStage option[data-flow]').each(function () {
            $(this).prop('hidden', !!flow && String($(this).data('flow')) !== String(flow));
        });
        if (flow && $('#filterStage option:selected').data('flow') && String($('#filterStage option:selected').data('flow')) !== String(flow)) {
            $('#filterStage').val('');
        }
    }
    window.wtTable.ajax.reload();
});

$(function () {
    wtSyncPills();
    livePillCounts('#itemsTable', {
        extra: [
            { selector: '#pillOverdue',   key: 'overdue'   },
            { selector: '#pillUnclaimed', key: 'unclaimed' },
            { selector: '#pillStranded',  key: 'stranded'  },
        ],
    });

    // The tiles say the same thing as the pills, in the language of a summary.
    $('#itemsTable').on('xhr.dt', function (e, settings, json) {
        const c = (json && json.counts) || {};
        $('[data-tile="running"]').text((c.status && c.status.Open) || 0);
        $('[data-tile="completed"]').text((c.status && c.status.Completed) || 0);
        $('[data-tile="unclaimed"]').text(c.unclaimed || 0);
        $('[data-tile="overdue"]').text(c.overdue || 0);
        $('[data-tile="stranded"]').text(c.stranded || 0);
    });

    window.wtTable = $('#itemsTable').DataTable({
        processing: true,
        serverSide: true,
        order: [],
        pageLength: 25,
        lengthChange: false,
        language: { search: '', searchPlaceholder: 'Search items…' },
        ajax: {
            url: '{{ route("workflows.items") }}',
            data: function (d) {
                d.status      = wtStatus === '' ? 'all' : wtStatus;
                d.overdue     = wtOverdue ? 1 : 0;
                d.unclaimed   = wtUnclaimed ? 1 : 0;
                d.stranded    = wtStranded ? 1 : 0;
                d.flow        = $('#filterFlow').val();
                d.stage       = $('#filterStage').val();
                d.assigned_to = $('#filterUser').val();
            }
        },
        columns: [
            { data: 'item',           name: 'title', className: 'ps-3' },
            { data: 'priority_badge', orderable: false, searchable: false },
            { data: 'flow_name',      orderable: false, searchable: false },
            { data: 'stage',          orderable: false, searchable: false },
            { data: 'who',            orderable: false, searchable: false },
            { data: 'due' },
            { data: 'status_badge',   orderable: false, searchable: false },
            { data: 'actions',        orderable: false, searchable: false, className: 'text-end pe-3' },
        ],
    });
});

// ── Details panel ────────────────────────────────────────────────────────────
$(document).on('click', '.item-details', function () {
    const id = $(this).data('id');
    const panel = bootstrap.Offcanvas.getOrCreateInstance('#wtPanel');
    $('#wtPanelTitle').text('Loading…');
    $('#wtPanelBadges').empty();
    $('#wtPanelBody').html('<div class="text-center py-5"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div></div>');
    panel.show();

    $.get('{{ url('workflows/items') }}/' + id + '/details')
        .done(renderDetails)
        .fail(() => $('#wtPanelBody').html('<div class="wt-empty text-center py-5">Could not load this item.</div>'));
});

function wtPill(text, cls) {
    return '<span class="spill ' + cls + '">' + wtEsc(text) + '</span>';
}

function renderDetails(d) {
    $('#wtPanelTitle').text(d.title);

    const statusCls = { Open: 'spill-running', Completed: 'spill-completed', Cancelled: 'spill-cancelled' }[d.status] || 'spill-hold';
    const prioCls   = { Urgent: 'spill-cancelled', High: 'spill-warning', Normal: 'spill-running' }[d.priority] || 'spill-hold';
    $('#wtPanelBadges').html(
        wtPill(d.status, statusCls) + ' ' + wtPill(d.priority, prioCls)
        + (d.is_overdue ? ' ' + wtPill('Overdue', 'spill-cancelled') : '')
    );

    const meta = [
        ['Client', d.client ? '<a href="' + d.client.url + '">' + wtEsc(d.client.name) + '</a>' : 'Internal'],
        ['Workflow', wtEsc(d.flow || '—')],
        ['With', wtEsc(d.assignee || 'Unclaimed')],
        ['Started by', wtEsc(d.creator || '—')],
        ['Due', d.due_date ? wtEsc(d.due_date) : '—'],
        [d.completed_at ? 'Completed' : 'Created', wtEsc(d.completed_at || d.created_at || '—')],
    ].map(([k, v]) => '<div><div class="wt-k">' + k + '</div><div class="wt-v">' + v + '</div></div>').join('');

    let html = '<div class="wt-meta">' + meta + '</div>';

    if (d.description) {
        html += '<div class="wt-section">Description</div><div class="wt-row-body">' + wtEsc(d.description) + '</div>';
    }

    html += '<div class="wt-section">Stages</div>';
    html += (d.stages || []).map(s =>
        '<div class="wt-stage ' + s.state + '"><span class="wt-dot"></span><div class="min-w-0">'
        + '<div class="wt-stage-name">' + wtEsc(s.name) + (s.state === 'current' ? ' — here now' : '') + '</div>'
        + '<div class="wt-stage-people">' + (s.people.length ? wtEsc(s.people.join(', ')) : 'nobody assigned') + '</div>'
        + '</div></div>').join('') || '<div class="wt-empty">No stages.</div>';

    html += '<div class="wt-section">Attachments (' + (d.attachments || []).length + ')</div>';
    html += (d.attachments || []).map(a => '<div class="wt-row">'
        + (a.kind === 'note'
            ? '<div class="wt-row-head">' + wtEsc(a.label || 'Note') + '</div><div class="wt-row-body">' + wtEsc(a.body) + '</div>'
            : '<a class="wt-row-head text-decoration-none" href="' + wtEsc(a.url) + '"' + (a.kind === 'link' ? ' target="_blank" rel="noopener"' : '') + '>'
              + '<i class="bi ' + (a.kind === 'link' ? 'bi-link-45deg' : 'bi-paperclip') + ' me-1"></i>' + wtEsc(a.label) + '</a>')
        + '<div class="wt-row-sub">added by ' + wtEsc(a.by || '—') + '</div></div>').join('')
        || '<div class="wt-empty">None.</div>';

    html += '<div class="wt-section">Discussion (' + (d.comments || []).length + ')</div>';
    html += (d.comments || []).map(c => '<div class="wt-row">'
        + '<div class="wt-row-head">' + wtEsc(c.by) + ' <span class="wt-row-sub">· ' + wtEsc(c.at) + '</span></div>'
        + '<div class="wt-row-body">' + wtEsc(c.body) + '</div></div>').join('')
        || '<div class="wt-empty">Nothing said yet.</div>';

    html += '<div class="wt-section">History</div>';
    html += (d.history || []).map(h => '<div class="wt-row">'
        + '<div class="wt-row-head">' + wtEsc(h.from || 'Started') + ' → ' + wtEsc(h.to || 'Completed') + '</div>'
        + '<div class="wt-row-sub">' + wtEsc(h.by) + ' · ' + wtEsc(h.at) + '</div>'
        + (h.note ? '<div class="wt-row-body">' + wtEsc(h.note) + '</div>' : '')
        + '</div>').join('') || '<div class="wt-empty">No moves yet.</div>';

    html += '<a href="' + d.item_url + '" class="btn btn-sm btn-primary w-100 mt-3">'
         +  '<i class="bi bi-box-arrow-up-right me-1"></i>Open the full item page</a>';

    $('#wtPanelBody').html(html);
}
</script>
@endpush
