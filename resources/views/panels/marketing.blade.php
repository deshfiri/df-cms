@extends('layouts.app')
@section('title', 'Marketing Panel')

@push('styles')
<style>
    .mkt-title { font-weight: 600; color: var(--text); }
    .mkt-empty { text-align: center; padding: 3rem 1rem; color: var(--text3); }
    .mkt-tabs .nav-link { cursor: pointer; }
    .mkt-kpi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: .6rem; margin-bottom: 1rem; }
    .mkt-kpi { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: .75rem .9rem; }
    .mkt-kpi-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; color: var(--text3); }
    .mkt-kpi-value { font-size: 1.3rem; font-weight: 700; color: var(--text); margin-top: 2px; }
    .mkt-kpi.is-primary .mkt-kpi-value { color: var(--primary); }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-megaphone me-2"></i>Marketing Panel</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Pre-publish check and brand-wise workload — Publishing Review stays on each Brand's own page</div>
    </div>
</div>

<ul class="nav nav-tabs mkt-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" data-tab="pending">Pre-Publish Check</a></li>
    <li class="nav-item"><a class="nav-link" data-tab="workload">Workload Dashboard</a></li>
    <li class="nav-item"><a class="nav-link" data-tab="conversations">Client Conversations</a></li>
</ul>

{{--
    Daily | Monthly | Yearly — governs every tab below, not just the Workload
    Dashboard. Pre-Publish Check and Client Conversations now follow the
    selected period too, same as every other panel's row lists.
--}}
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <select id="mktPeriodType" class="form-select form-select-sm" style="width:130px">
        <option value="daily">Daily</option>
        <option value="monthly" selected>Monthly</option>
        <option value="yearly">Yearly</option>
    </select>
    {{-- Defaults come from the server's Asia/Dhaka clock — the same calendar the report itself uses — not the browser's. --}}
    <input type="date" id="mktPeriodDate" value="{{ now('Asia/Dhaka')->format('Y-m-d') }}" class="form-control form-control-sm d-none" style="width:160px">
    <input type="month" id="mktPeriodMonth" value="{{ now('Asia/Dhaka')->format('Y-m') }}" class="form-control form-control-sm" style="width:160px">
    <input type="number" id="mktPeriodYear" value="{{ now('Asia/Dhaka')->format('Y') }}" class="form-control form-control-sm d-none" style="width:110px" min="2000" max="2100">
    <span id="mktPeriodLabel" class="small" style="color:var(--text3)"></span>
</div>

{{-- ── Pre-Publish Check: filtered to the period selected above ── --}}
<div class="card section-card" id="paneMktPending">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
                <thead><tr><th>Brand</th><th>Category</th><th>Title</th><th>Version</th><th>Submitted by</th><th>Submitted</th><th>Content</th><th class="text-end">Actions</th></tr></thead>
                <tbody id="mktPendingRows"><tr><td colspan="8" class="mkt-empty">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

{{-- ── SMM client conversations awaiting verification, filtered to the period selected above ── --}}
<div class="card section-card d-none" id="paneMktConversations">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
                <thead><tr><th>Brand</th><th>Product</th><th>Reference</th><th>Logged by</th><th>Logged</th><th>Screenshot</th><th>Status</th><th class="text-end">Verdict</th></tr></thead>
                <tbody id="mktConvRows"><tr><td colspan="8" class="mkt-empty">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

{{-- ── ACTIVITY / PERFORMANCE: Daily | Monthly | Yearly ── --}}
<div class="card section-card d-none" id="paneMktWorkload">
    <div class="card-body">
        <div class="mkt-kpi-grid">
            <div class="mkt-kpi is-primary"><div class="mkt-kpi-label">Received</div><div class="mkt-kpi-value" id="mktTotalReceived">—</div></div>
            <div class="mkt-kpi is-primary"><div class="mkt-kpi-label">Handed Over</div><div class="mkt-kpi-value" id="mktTotalHandedOver">—</div></div>
            <div class="mkt-kpi is-primary"><div class="mkt-kpi-label">Completed</div><div class="mkt-kpi-value" id="mktTotalCompleted">—</div></div>
            <div class="mkt-kpi"><div class="mkt-kpi-label">Pending Pre-Publish</div><div class="mkt-kpi-value" id="mktTotalPendingCheck">—</div></div>
            <div class="mkt-kpi"><div class="mkt-kpi-label">Pending Final Review</div><div class="mkt-kpi-value" id="mktTotalPendingReview">—</div></div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.8rem">
                <thead>
                    <tr>
                        <th>Brand</th><th class="text-end">Received</th><th class="text-end">Pending Check</th>
                        <th class="text-end">Handed Over</th><th class="text-end">Returned</th>
                        <th class="text-end">Pending Review</th><th class="text-end">Completed</th>
                        <th class="text-end">Revision Req.</th><th></th>
                    </tr>
                </thead>
                <tbody id="mktWorkloadRows"><tr><td colspan="9" class="mkt-empty">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

{{-- Request revision (pre-publish) --}}
<div class="modal fade" id="mktRevisionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2"><h6 class="modal-title fw-bold" id="mktRevisionTitle">Send back for revision</h6><button class="btn-close btn-sm" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="mktRevisionBrand"><input type="hidden" id="mktRevisionItem">
                <label class="form-label small fw-semibold">Reason <span class="text-danger">*</span></label>
                <textarea id="mktRevisionNote" class="form-control form-control-sm" rows="3" placeholder="What needs to change before this can be approved?"></textarea>
                <label class="form-label small fw-semibold mt-3">Send it to <span class="fw-normal" style="color:var(--text3)">(optional)</span></label>
                <select id="mktRevisionAssign" class="form-select form-select-sm">
                    <option value="">Anyone in the Content or Design team (they claim it)</option>
                    @foreach ($makerUsers as $maker)
                        <option value="{{ $maker->id }}">{{ $maker->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-sm btn-danger" id="mktRevisionSave"><i class="bi bi-arrow-counterclockwise me-1"></i>Send back</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const catLabel = { raw_content: 'Raw content', advertising_content: 'Advertising content', poster: 'Poster' };
const escMkt = s => $('<div>').text(s == null ? '' : s).html();
const panes = { pending: '#paneMktPending', workload: '#paneMktWorkload', conversations: '#paneMktConversations' };
const smmUsers = @json($smmUsers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values());

$('.mkt-tabs .nav-link').on('click', function () {
    $('.mkt-tabs .nav-link').removeClass('active');
    $(this).addClass('active');
    const tab = $(this).data('tab');
    Object.values(panes).forEach(sel => $(sel).addClass('d-none'));
    $(panes[tab]).removeClass('d-none');
    $('#mktPeriodLabel').text(displayPeriodLabel());
    if (tab === 'pending') loadPending();
    if (tab === 'workload') loadWorkload();
    if (tab === 'conversations') loadConversations();
});

function submissionLink(sub, brandId, itemId) {
    if (!sub) return '—';
    if (sub.link_url) return '<a href="' + escMkt(sub.link_url) + '" target="_blank" rel="noopener">Link</a>';
    if (!sub.file_path) return '—';

    const baseUrl = '/marketing/brands/' + brandId + '/content-items/' + itemId + '/submissions/' + sub.id;
    // previewable is a cheap, extension-only UI hint — the preview endpoint
    // itself re-checks the real bytes before ever streaming anything.
    return (sub.previewable ? '<a href="' + escMkt(baseUrl + '/preview') + '" target="_blank" rel="noopener noreferrer">View</a> · ' : '')
        + '<a href="' + escMkt(baseUrl + '/download') + '">Download file</a>';
}

function loadPending() {
    $.get('{{ route('panels.marketing.pending-check') }}', periodParams()).done(function (r) {
        const rows = r.data || [];
        if (!rows.length) { $('#mktPendingRows').html('<tr><td colspan="8" class="mkt-empty">Nothing waiting on Marketing right now.</td></tr>'); return; }
        $('#mktPendingRows').html(rows.map(it => {
            const sub = it.submission;
            return '<tr>'
                + '<td>' + escMkt(it.brand) + '</td>'
                + '<td>' + catLabel[it.category] + '</td>'
                + '<td><span class="mkt-title">' + escMkt(it.title) + '</span></td>'
                + '<td>V' + (it.version || 1) + '</td>'
                + '<td>' + (sub?.submitted_by?.name ? escMkt(sub.submitted_by.name) : '—') + '</td>'
                + '<td>' + (it.created_at ? escMkt(it.created_at) : '—') + '</td>'
                + '<td>' + submissionLink(sub, it.brand_id, it.id) + '</td>'
                + '<td class="text-end">'
                + ownerBadge(it.owner)
                + (it.owner ? '' : '<button class="btn btn-sm btn-outline-primary mkt-claim-btn me-1" data-id="' + it.id + '" data-brand="' + it.brand_id + '" data-submission="' + (sub ? sub.id : '') + '"><i class="bi bi-hand-index"></i> Claim</button>')
                + (it.owner?.is_me ? '<button class="btn btn-sm btn-success mkt-approve-btn me-1" data-id="' + it.id + '" data-brand="' + it.brand_id + '" data-submission="' + (sub ? sub.id : '') + '"><i class="bi bi-check-lg"></i> Approve</button>' : '')
                + (it.owner?.is_me ? '<button class="btn btn-sm btn-outline-danger mkt-revision-btn me-1" data-id="' + it.id + '" data-brand="' + it.brand_id + '" data-title="' + escMkt(it.title) + '"><i class="bi bi-arrow-counterclockwise"></i></button>' : '')
                + '<a class="btn btn-sm btn-outline-secondary" href="/marketing/brands/' + it.brand_id + '/checklist" title="View this brand\'s full content checklist"><i class="bi bi-list-check"></i></a>'
                + '</td></tr>';
        }).join(''));
    });
}

$('#mktPendingRows').on('click', '.mkt-approve-btn', function () {
    const id = $(this).data('id'), brand = $(this).data('brand'), submission = $(this).data('submission');
    if (!submission) { Swal.fire('Error', 'No submission on record for this item.', 'error'); return; }
    // Optional: name one SMM user. Without one, the next SMM user to collect it claims it.
    const inputOptions = Object.fromEntries(smmUsers.map(u => [u.id, u.name]));
    Swal.fire({
        title: 'Approve and hand over to SMM?', icon: 'question', showCancelButton: true, confirmButtonText: 'Approve',
        input: 'select', inputOptions: inputOptions, inputPlaceholder: 'Anyone in SMM may claim it',
    }).then(r => {
        if (!r.isConfirmed) return;
        const assign = r.value ? { assign_smm_user_id: r.value } : {};
        $.post('/marketing/brands/' + brand + '/content-items/' + id + '/submissions/' + submission + '/approve', Object.assign({
            _token: $('meta[name=csrf-token]').attr('content'),
        }, assign))
            .done(function () { Swal.fire({ icon: 'success', title: 'Approved', timer: 1200, showConfirmButton: false }); loadPending(); })
            .fail(x => Swal.fire('Error', x.responseJSON?.message || Object.values(x.responseJSON?.errors || {}).flat().join(' ') || 'Could not approve.', 'error'));
    });
});

// Who owns this stage. Only the owner may act on it; an unowned stage must be claimed first.
function ownerBadge(owner) {
    if (!owner) return '<span class="spill spill-hold me-1">Unclaimed</span>';
    const text = owner.is_me ? 'You own this' : 'Owned by ' + escMkt(owner.name);
    return '<span class="spill ' + (owner.is_me ? 'spill-completed' : 'spill-running') + ' me-1">' + text + '</span>';
}

$('#mktPendingRows').on('click', '.mkt-claim-btn', function () {
    const id = $(this).data('id'), brand = $(this).data('brand'), submission = $(this).data('submission');
    $.post('/marketing/brands/' + brand + '/content-items/' + id + '/submissions/' + submission + '/claim', {
        _token: $('meta[name=csrf-token]').attr('content'),
    })
        .done(function () { Swal.fire({ icon: 'success', title: 'Claimed — it is yours now', timer: 1200, showConfirmButton: false }); loadPending(); })
        .fail(x => Swal.fire('Not claimed', x.responseJSON?.message || Object.values(x.responseJSON?.errors || {}).flat().join(' ') || 'Could not claim this.', 'warning'));
});

// ── Client conversations: verify SMM evidence, filtered to the selected period ──
function loadConversations() {
    $.get('{{ route('smm-conversations.index') }}', Object.assign({ status: 'pending' }, periodParams())).done(function (r) {
        const rows = r.data || [];
        if (!rows.length) { $('#mktConvRows').html('<tr><td colspan="8" class="mkt-empty">No conversations waiting for verification.</td></tr>'); return; }
        $('#mktConvRows').html(rows.map(c => '<tr>'
            + '<td>' + escMkt(c.brand) + '</td>'
            + '<td>' + (c.product ? escMkt(c.product) : '—') + '</td>'
            + '<td><span class="mkt-title">' + escMkt(c.reference) + '</span>' + (c.note ? '<div class="small" style="color:var(--text3)">' + escMkt(c.note) + '</div>' : '') + '</td>'
            + '<td>' + escMkt(c.submitted_by) + '</td>'
            + '<td>' + escMkt(c.submitted_at) + '</td>'
            + '<td><a href="' + escMkt(c.evidence_url) + '" target="_blank" rel="noopener">View</a> · <a href="' + escMkt(c.evidence_download_url) + '">Download</a></td>'
            + '<td><span class="spill spill-hold">Pending</span></td>'
            + '<td class="text-end">'
            + '<button class="btn btn-sm btn-success mkt-conv-btn me-1" data-id="' + c.id + '" data-decision="approved"><i class="bi bi-check-lg"></i> Potential client</button>'
            + '<button class="btn btn-sm btn-outline-danger mkt-conv-btn" data-id="' + c.id + '" data-decision="rejected"><i class="bi bi-x-lg"></i> Not potential</button>'
            + '</td></tr>').join(''));
    });
}

$('#mktConvRows').on('click', '.mkt-conv-btn', function () {
    const id = $(this).data('id'), decision = $(this).data('decision');
    Swal.fire({ title: decision === 'approved' ? 'Approve as a Potential Client?' : 'Not a Potential Client?', icon: 'question', showCancelButton: true, confirmButtonText: 'Confirm' }).then(r => {
        if (!r.isConfirmed) return;
        $.post('/smm-conversations/' + id + '/decision', { decision: decision, _token: $('meta[name=csrf-token]').attr('content') })
            .done(function () { Swal.fire({ icon: 'success', title: 'Recorded', timer: 1000, showConfirmButton: false }); loadConversations(); })
            .fail(x => Swal.fire('Error', x.responseJSON?.message || Object.values(x.responseJSON?.errors || {}).flat().join(' ') || 'Could not record this.', 'error'));
    });
});

$('#mktPendingRows').on('click', '.mkt-revision-btn', function () {
    $('#mktRevisionItem').val($(this).data('id'));
    $('#mktRevisionBrand').val($(this).data('brand'));
    $('#mktRevisionTitle').text('Send back — ' + $(this).data('title'));
    $('#mktRevisionNote').val('');
    bootstrap.Modal.getOrCreateInstance('#mktRevisionModal').show();
});

$('#mktRevisionSave').on('click', function () {
    const note = $('#mktRevisionNote').val().trim();
    if (note.length < 3) { Swal.fire('Reason required', 'Say what needs to change before sending it back.', 'warning'); return; }

    const $btn = $(this).prop('disabled', true);
    $.post('/marketing/brands/' + $('#mktRevisionBrand').val() + '/content-items/' + $('#mktRevisionItem').val() + '/request-revision', {
        note: note, assign_to: $('#mktRevisionAssign').val() || null, _token: $('meta[name=csrf-token]').attr('content'),
    }).done(function () {
        bootstrap.Modal.getInstance('#mktRevisionModal').hide();
        Swal.fire({ icon: 'success', title: 'Sent back', timer: 1200, showConfirmButton: false });
        loadPending();
    }).fail(x => Swal.fire('Error', x.responseJSON?.message || Object.values(x.responseJSON?.errors || {}).flat().join(' ') || 'Could not send back.', 'error'))
      .always(() => $btn.prop('disabled', false));
});

// ── Period picker: shared by Pre-Publish Check, Client Conversations and
// the Workload Dashboard — see ReportingPeriod's own parameter names
// (period/date/month/year), unchanged here. ──────────────────────────────
function syncPeriodInputs() {
    const type = $('#mktPeriodType').val();
    $('#mktPeriodDate').toggleClass('d-none', type !== 'daily');
    $('#mktPeriodMonth').toggleClass('d-none', type !== 'monthly');
    $('#mktPeriodYear').toggleClass('d-none', type !== 'yearly');
}

function periodParams() {
    const type = $('#mktPeriodType').val();
    const params = { period: type };
    if (type === 'daily') params.date = $('#mktPeriodDate').val();
    if (type === 'monthly') params.month = $('#mktPeriodMonth').val();
    if (type === 'yearly') params.year = $('#mktPeriodYear').val();
    return params;
}

// Cosmetic only — the real filtering is the server-side query above, built
// from these same input values. Used for tabs whose endpoint doesn't hand
// back a formatted label (loadWorkload overwrites this with the server's own).
function displayPeriodLabel() {
    const type = $('#mktPeriodType').val();
    if (type === 'daily') {
        const d = $('#mktPeriodDate').val();
        if (!d) return '';
        const [y, m, day] = d.split('-');
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return day + ' ' + months[parseInt(m, 10) - 1] + ' ' + y;
    }
    if (type === 'yearly') return $('#mktPeriodYear').val();
    const m = $('#mktPeriodMonth').val();
    if (!m) return '';
    const [y, mm] = m.split('-');
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    return months[parseInt(mm, 10) - 1] + ' ' + y;
}

function activeMktTab() {
    return Object.keys(panes).find(k => !$(panes[k]).hasClass('d-none')) || 'pending';
}

function reloadActiveMktTab() {
    $('#mktPeriodLabel').text(displayPeriodLabel());
    const tab = activeMktTab();
    if (tab === 'pending') loadPending();
    if (tab === 'workload') loadWorkload();
    if (tab === 'conversations') loadConversations();
}

$('#mktPeriodType').on('change', function () { syncPeriodInputs(); reloadActiveMktTab(); });
$('#mktPeriodDate,#mktPeriodMonth,#mktPeriodYear').on('change', reloadActiveMktTab);

function loadWorkload() {
    $.get('{{ route('panels.marketing.workload') }}', periodParams()).done(function (r) {
        const rows = r.data || [];
        $('#mktPeriodLabel').text(r.period ? r.period.label : '');
        $('#mktTotalReceived').text(r.totals.received);
        $('#mktTotalHandedOver').text(r.totals.handed_over);
        $('#mktTotalCompleted').text(r.totals.completed);
        $('#mktTotalPendingCheck').text(r.totals.pending_pre_publish);
        $('#mktTotalPendingReview').text(r.totals.pending_final_review);

        if (!rows.length) { $('#mktWorkloadRows').html('<tr><td colspan="9" class="mkt-empty">No brands yet.</td></tr>'); return; }
        $('#mktWorkloadRows').html(rows.map(row => '<tr>'
            + '<td>' + escMkt(row.brand) + '</td>'
            + '<td class="text-end">' + row.received + '</td>'
            + '<td class="text-end">' + (row.pending_pre_publish ? '<span class="spill spill-warning">' + row.pending_pre_publish + '</span>' : row.pending_pre_publish) + '</td>'
            + '<td class="text-end">' + row.handed_over + '</td>'
            + '<td class="text-end">' + row.returned_for_final_check + '</td>'
            + '<td class="text-end">' + (row.pending_final_review ? '<span class="spill spill-warning">' + row.pending_final_review + '</span>' : row.pending_final_review) + '</td>'
            + '<td class="text-end">' + row.completed + '</td>'
            + '<td class="text-end">' + row.revision_requested + '</td>'
            + '<td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/marketing/brands/' + row.brand_id + '" title="Open this Brand\'s Marketing page"><i class="bi bi-arrow-up-right"></i></a></td>'
            + '</tr>').join(''));
    });
}

syncPeriodInputs();
$('#mktPeriodLabel').text(displayPeriodLabel());

loadPending();
</script>
@endpush
