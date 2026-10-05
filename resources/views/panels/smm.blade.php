@extends('layouts.app')
@section('title', 'SMM Panel')

@push('styles')
<style>
    .smm-title { font-weight: 600; color: var(--text); }
    .smm-empty { text-align: center; padding: 3rem 1rem; color: var(--text3); }
    .smm-tabs .nav-link { cursor: pointer; }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-share me-2"></i>SMM Panel</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Collect submitted content and publish it, brand by brand</div>
    </div>
</div>

<ul class="nav nav-tabs smm-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" data-tab="available">Available</a></li>
    <li class="nav-item"><a class="nav-link" data-tab="collected">Collected</a></li>
    <li class="nav-item"><a class="nav-link" data-tab="published">Published</a></li>
</ul>

<div class="card section-card" id="paneAvailable">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
                <thead><tr><th>Brand</th><th>Product</th><th>Category</th><th>Title</th><th>Submitted</th><th class="text-end">Actions</th></tr></thead>
                <tbody id="availRows"><tr><td colspan="6" class="smm-empty">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div class="card section-card d-none" id="paneCollected">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
                <thead><tr><th>Brand</th><th>Product</th><th>Title</th><th>Collected by</th><th>Collected at</th><th class="text-end">Actions</th></tr></thead>
                <tbody id="collRows"><tr><td colspan="6" class="smm-empty">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div class="card section-card d-none" id="panePublished">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
                <thead><tr><th>Brand</th><th>Title</th><th>Facebook post</th><th>Published by</th><th>Published at</th><th>Review</th><th class="text-end">Actions</th></tr></thead>
                <tbody id="pubRows"><tr><td colspan="7" class="smm-empty">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

{{-- Publish --}}
<div class="modal fade" id="smmPublishModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2"><h6 class="modal-title fw-bold" id="smmPublishTitle">Publish</h6><button class="btn-close btn-sm" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="smmPublishBrand"><input type="hidden" id="smmPublishItem"><input type="hidden" id="smmPublishSubmission">
                <label class="form-label small fw-semibold">Facebook post URL <span class="text-danger">*</span></label>
                <input type="url" id="smmPublishUrl" class="form-control form-control-sm" placeholder="https://facebook.com/…">
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-sm btn-primary" id="smmPublishSave"><i class="bi bi-check me-1"></i>Publish</button>
            </div>
        </div>
    </div>
</div>

{{-- Request revision --}}
<div class="modal fade" id="smmRevisionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2"><h6 class="modal-title fw-bold" id="smmRevisionTitle">Send back for revision</h6><button class="btn-close btn-sm" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="smmRevisionBrand"><input type="hidden" id="smmRevisionItem">
                <label class="form-label small fw-semibold">Reason <span class="text-danger">*</span></label>
                <textarea id="smmRevisionNote" class="form-control form-control-sm" rows="3" placeholder="What needs to change?"></textarea>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-sm btn-danger" id="smmRevisionSave"><i class="bi bi-arrow-counterclockwise me-1"></i>Send back</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const catLabel = { raw_content: 'Raw content', advertising_content: 'Advertising content', poster: 'Poster' };
const escSmm = s => $('<div>').text(s == null ? '' : s).html();
const panes = { available: '#paneAvailable', collected: '#paneCollected', published: '#panePublished' };

$('.smm-tabs .nav-link').on('click', function () {
    $('.smm-tabs .nav-link').removeClass('active');
    $(this).addClass('active');
    const tab = $(this).data('tab');
    Object.values(panes).forEach(sel => $(sel).addClass('d-none'));
    $(panes[tab]).removeClass('d-none');
    if (tab === 'available') loadAvailable();
    if (tab === 'collected') loadCollected();
    if (tab === 'published') loadPublished();
});

function submissionLink(sub, brandId, itemId) {
    if (!sub) return '—';
    if (sub.link_url) return '<a href="' + escSmm(sub.link_url) + '" target="_blank" rel="noopener">Link</a>';
    if (!sub.file_path) return '—';

    const url = '/marketing/brands/' + brandId + '/content-items/' + itemId + '/submissions/' + sub.id + '/download';

    return '<a href="' + escSmm(url) + '">Download file</a>';
}

function loadAvailable() {
    $.get('{{ route('panels.smm.available') }}').done(function (r) {
        const rows = r.data || [];
        if (!rows.length) { $('#availRows').html('<tr><td colspan="6" class="smm-empty">Nothing waiting to be collected.</td></tr>'); return; }
        $('#availRows').html(rows.map(it => '<tr>'
            + '<td>' + escSmm(it.brand) + '</td>'
            + '<td>' + (it.product ? escSmm(it.product) : '<span style="color:var(--text3)">—</span>') + '</td>'
            + '<td>' + catLabel[it.category] + '</td>'
            + '<td><span class="smm-title">' + escSmm(it.title) + '</span></td>'
            + '<td>' + submissionLink(it.submission, it.brand_id, it.id) + '</td>'
            + '<td class="text-end">'
            + '<button class="btn btn-sm btn-primary smm-collect-btn me-1" data-id="' + it.id + '" data-brand="' + it.brand_id + '"><i class="bi bi-hand-index"></i> Collect</button>'
            + '<button class="btn btn-sm btn-outline-danger smm-revision-btn me-1" data-id="' + it.id + '" data-brand="' + it.brand_id + '" data-title="' + escSmm(it.title) + '"><i class="bi bi-arrow-counterclockwise"></i></button>'
            + '<a class="btn btn-sm btn-outline-secondary" href="/marketing/brands/' + it.brand_id + '/checklist" title="View this brand\'s full content checklist"><i class="bi bi-list-check"></i></a>'
            + '</td></tr>').join(''));
    });
}

function loadCollected() {
    $.get('{{ route('panels.smm.collected') }}').done(function (r) {
        const rows = r.data || [];
        if (!rows.length) { $('#collRows').html('<tr><td colspan="6" class="smm-empty">Nothing collected right now.</td></tr>'); return; }
        $('#collRows').html(rows.map(function (it) {
            const c = it.collection;
            return '<tr>'
                + '<td>' + escSmm(it.brand) + '</td>'
                + '<td>' + (it.product ? escSmm(it.product) : '<span style="color:var(--text3)">—</span>') + '</td>'
                + '<td><span class="smm-title">' + escSmm(it.title) + '</span></td>'
                + '<td>' + (c?.collected_by?.name ? escSmm(c.collected_by.name) : '—') + '</td>'
                + '<td>' + (c?.collected_at ? escSmm(c.collected_at) : '—') + '</td>'
                + '<td class="text-end">'
                + '<button class="btn btn-sm btn-primary smm-publish-btn me-1" data-id="' + it.id + '" data-brand="' + it.brand_id + '" data-submission="' + (c?.submission?.id || '') + '" data-title="' + escSmm(it.title) + '"><i class="bi bi-send"></i> Publish</button>'
                + '<button class="btn btn-sm btn-outline-danger smm-revision-btn me-1" data-id="' + it.id + '" data-brand="' + it.brand_id + '" data-title="' + escSmm(it.title) + '"><i class="bi bi-arrow-counterclockwise"></i></button>'
                + '<a class="btn btn-sm btn-outline-secondary" href="/marketing/brands/' + it.brand_id + '/checklist" title="View this brand\'s full content checklist"><i class="bi bi-list-check"></i></a>'
                + '</td></tr>';
        }).join(''));
    });
}

function reviewBadge(state) {
    if (state === 'reviewed') return '<span class="spill spill-completed">Reviewed</span>';
    if (state === 'revision_requested') return '<span class="spill spill-cancelled">Revision requested</span>';
    return '<span class="spill spill-hold">Awaiting review</span>';
}

function loadPublished() {
    $.get('{{ route('panels.smm.published') }}').done(function (r) {
        const rows = r.data || [];
        if (!rows.length) { $('#pubRows').html('<tr><td colspan="7" class="smm-empty">Nothing published yet.</td></tr>'); return; }
        // Publication history stays visible here regardless of a later
        // revision — requesting one only moves the ContentItem back to
        // needs_revision, it never touches this published row or its
        // facebook_post_url/published_at/reviewed_at.
        $('#pubRows').html(rows.map(p => '<tr>'
            + '<td>' + escSmm(p.brand) + '</td>'
            + '<td><span class="smm-title">' + escSmm(p.title) + '</span></td>'
            + '<td><a href="' + escSmm(p.facebook_post_url) + '" target="_blank" rel="noopener">' + escSmm(p.facebook_post_url) + '</a></td>'
            + '<td>' + escSmm(p.published_by) + '</td>'
            + '<td>' + escSmm(p.published_at) + '</td>'
            + '<td>' + reviewBadge(p.review_state)
            + '</td>'
            + '<td class="text-end">' + (p.review_state === 'revision_requested' ? '' :
                '<button class="btn btn-sm btn-outline-danger smm-revision-btn me-1" data-id="' + p.content_item_id + '" data-brand="' + p.brand_id + '" data-title="' + escSmm(p.title) + '"><i class="bi bi-arrow-counterclockwise"></i></button>')
            + '<a class="btn btn-sm btn-outline-secondary" href="/marketing/brands/' + p.brand_id + '/checklist" title="View this brand\'s full content checklist"><i class="bi bi-list-check"></i></a>'
            + '</td>'
            + '</tr>').join(''));
    });
}

$('#availRows').on('click', '.smm-collect-btn', function () {
    const id = $(this).data('id'), brand = $(this).data('brand');
    Swal.fire({ title: 'Collect this item?', icon: 'question', showCancelButton: true, confirmButtonText: 'Collect' }).then(r => {
        if (!r.isConfirmed) return;
        $.post('/marketing/brands/' + brand + '/content-items/' + id + '/collect', { _token: $('meta[name=csrf-token]').attr('content') })
            .done(function () { Swal.fire({ icon: 'success', title: 'Collected', timer: 1200, showConfirmButton: false }); loadAvailable(); })
            .fail(x => Swal.fire('Error', x.responseJSON?.message || Object.values(x.responseJSON?.errors || {}).flat().join(' ') || 'Could not collect.', 'error'));
    });
});

$(document).on('click', '.smm-revision-btn', function () {
    $('#smmRevisionItem').val($(this).data('id'));
    $('#smmRevisionBrand').val($(this).data('brand'));
    $('#smmRevisionTitle').text('Send back — ' + $(this).data('title'));
    $('#smmRevisionNote').val('');
    bootstrap.Modal.getOrCreateInstance('#smmRevisionModal').show();
});

$('#smmRevisionSave').on('click', function () {
    const note = $('#smmRevisionNote').val().trim();
    if (note.length < 3) { Swal.fire('Reason required', 'Say what needs to change before sending it back.', 'warning'); return; }

    const $btn = $(this).prop('disabled', true);
    $.post('/marketing/brands/' + $('#smmRevisionBrand').val() + '/content-items/' + $('#smmRevisionItem').val() + '/request-revision', {
        note: note, _token: $('meta[name=csrf-token]').attr('content'),
    }).done(function () {
        bootstrap.Modal.getInstance('#smmRevisionModal').hide();
        Swal.fire({ icon: 'success', title: 'Sent back', timer: 1200, showConfirmButton: false });
        // Available/Collected refresh unconditionally; Published is only
        // populated once its tab has been opened at least once (loadAvailable()
        // runs on page load, loadPublished() does not) — refreshing it here
        // when it's already loaded keeps a revision requested from Published
        // reflected without forcing a tab switch.
        loadAvailable(); loadCollected();
        if ($('#panePublished').is(':visible')) loadPublished();
    }).fail(x => Swal.fire('Error', x.responseJSON?.message || Object.values(x.responseJSON?.errors || {}).flat().join(' ') || 'Could not send back.', 'error'))
      .always(() => $btn.prop('disabled', false));
});

$('#collRows').on('click', '.smm-publish-btn', function () {
    if (!$(this).data('submission')) { Swal.fire('Error', 'No submission on record for this item.', 'error'); return; }
    $('#smmPublishItem').val($(this).data('id'));
    $('#smmPublishBrand').val($(this).data('brand'));
    $('#smmPublishSubmission').val($(this).data('submission'));
    $('#smmPublishTitle').text('Publish — ' + $(this).data('title'));
    $('#smmPublishUrl').val('');
    bootstrap.Modal.getOrCreateInstance('#smmPublishModal').show();
});

$('#smmPublishSave').on('click', function () {
    const url = $('#smmPublishUrl').val().trim();
    if (!url) { Swal.fire('Missing URL', 'Paste the Facebook post URL.', 'warning'); return; }

    const $btn = $(this).prop('disabled', true);
    $.post('/marketing/brands/' + $('#smmPublishBrand').val() + '/content-items/' + $('#smmPublishItem').val() + '/publish', {
        submission_id: $('#smmPublishSubmission').val(), facebook_post_url: url, _token: $('meta[name=csrf-token]').attr('content'),
    }).done(function () {
        bootstrap.Modal.getInstance('#smmPublishModal').hide();
        Swal.fire({ icon: 'success', title: 'Published', timer: 1200, showConfirmButton: false });
        loadCollected();
    }).fail(x => Swal.fire('Error', x.responseJSON?.message || Object.values(x.responseJSON?.errors || {}).flat().join(' ') || 'Could not publish.', 'error'))
      .always(() => $btn.prop('disabled', false));
});

loadAvailable();
</script>
@endpush
