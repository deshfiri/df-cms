@extends('layouts.app')
@section('title', 'Raw Content Panel')

@push('styles')
<style>
    #rcTable .rc-title { font-weight: 600; color: var(--text); }
    #rcTable .rc-sub { font-size: .7rem; color: var(--text3); }
    .rc-empty { text-align: center; padding: 3rem 1rem; color: var(--text3); }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-folder2-open me-2"></i>Raw Content Panel</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Raw content and advertising content for every brand's checklist</div>
    </div>
    <button class="btn btn-sm btn-primary" id="newItemBtn"><i class="bi bi-plus-lg me-1"></i>New Item</button>
</div>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <select id="filterBrand" class="form-select form-select-sm" style="width:200px">
        <option value="">All brands</option>
        @foreach($brands as $b)
            <option value="{{ $b->id }}">{{ $b->name }}</option>
        @endforeach
    </select>
    <select id="filterCategory" class="form-select form-select-sm" style="width:180px">
        <option value="">Raw content + advertising</option>
        <option value="raw_content">Raw content only</option>
        <option value="advertising_content">Advertising content only</option>
    </select>
    <select id="filterStatus" class="form-select form-select-sm" style="width:160px">
        <option value="">All statuses</option>
        <option value="pending">Pending</option>
        <option value="in_progress">In progress</option>
        <option value="available">Submitted</option>
        <option value="collected">Collected by SMM</option>
        <option value="published">Published</option>
        <option value="needs_revision">Needs revision</option>
    </select>
</div>

<div class="card section-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" id="rcTable" style="font-size:.82rem">
                <thead>
                    <tr>
                        <th>Brand</th><th>Product</th><th>Category</th><th>Title</th>
                        <th>Status</th><th>Latest submission</th><th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="rcRows"><tr><td colspan="7" class="rc-empty">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

{{-- New item --}}
<div class="modal fade" id="rcNewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2"><h6 class="modal-title fw-bold">New Content Item</h6><button class="btn-close btn-sm" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Brand <span class="text-danger">*</span></label>
                        <select id="rcNewBrand" class="form-select form-select-sm">
                            <option value="">Select brand…</option>
                            @foreach($brands as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Product <span style="color:var(--text3);font-weight:400">(optional)</span></label>
                        <select id="rcNewProduct" class="form-select form-select-sm"><option value="">— No specific product —</option></select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Category <span class="text-danger">*</span></label>
                        <select id="rcNewCategory" class="form-select form-select-sm">
                            <option value="raw_content">Raw content</option>
                            <option value="advertising_content">Advertising content</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Title <span class="text-danger">*</span></label>
                        <input type="text" id="rcNewTitle" class="form-control form-control-sm" placeholder="e.g. Product photos — batch 1">
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-sm btn-primary" id="rcNewSave"><i class="bi bi-check me-1"></i>Create</button>
            </div>
        </div>
    </div>
</div>

{{-- Submit --}}
<div class="modal fade" id="rcSubmitModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2"><h6 class="modal-title fw-bold" id="rcSubmitTitle">Submit content</h6><button class="btn-close btn-sm" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="rcSubmitBrand"><input type="hidden" id="rcSubmitItem">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Link</label>
                    <input type="url" id="rcSubmitLink" class="form-control form-control-sm" placeholder="https://…">
                </div>
                <div class="text-center small text-muted mb-2">— or —</div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">File</label>
                    <input type="file" id="rcSubmitFile" class="form-control form-control-sm">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-sm btn-primary" id="rcSubmitSave"><i class="bi bi-upload me-1"></i>Submit</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const statusSpill = {
    pending: 'spill-hold', in_progress: 'spill-hold', available: 'spill-warning',
    collected: 'spill-warning', published: 'spill-completed', needs_revision: 'spill-cancelled',
};
const statusLabel = {
    pending: 'Pending', in_progress: 'In progress', available: 'Submitted',
    collected: 'Collected by SMM', published: 'Published', needs_revision: 'Needs revision',
};
const catLabel = { raw_content: 'Raw content', advertising_content: 'Advertising content' };
const escRawContent = s => $('<div>').text(s == null ? '' : s).html();

function loadItems() {
    $.get('{{ route('panels.raw-content') }}', {
        brand_id: $('#filterBrand').val(), category: $('#filterCategory').val(), status: $('#filterStatus').val(),
    }).done(function (r) {
        const rows = r.data || [];
        if (!rows.length) { $('#rcRows').html('<tr><td colspan="7" class="rc-empty">No content items match these filters.</td></tr>'); return; }
        $('#rcRows').html(rows.map(function (it) {
            const sub = it.submission;
            const subDownloadUrl = '/marketing/brands/' + it.brand_id + '/content-items/' + it.id + '/submissions/' + (sub ? sub.id : '') + '/download';
            const subText = sub ? (sub.link_url ? '<a href="' + escRawContent(sub.link_url) + '" target="_blank" rel="noopener">Link</a>' : (sub.file_path ? '<a href="' + escRawContent(subDownloadUrl) + '">Download file</a>' : '—')) : '—';
            const canSubmit = ['pending', 'in_progress', 'needs_revision'].includes(it.status);
            return '<tr>'
                + '<td>' + escRawContent(it.brand) + '</td>'
                + '<td>' + (it.product ? escRawContent(it.product) : '<span style="color:var(--text3)">—</span>') + '</td>'
                + '<td>' + catLabel[it.category] + '</td>'
                + '<td><span class="rc-title">' + escRawContent(it.title) + '</span></td>'
                + '<td><span class="spill ' + (statusSpill[it.status] || 'spill-hold') + '">' + statusLabel[it.status] + '</span></td>'
                + '<td>' + subText + '</td>'
                + '<td class="text-end">' + (canSubmit
                    ? '<button class="btn btn-sm btn-primary rc-submit-btn" data-id="' + it.id + '" data-brand="' + it.brand_id + '" data-title="' + escRawContent(it.title) + '"><i class="bi bi-upload"></i> Submit</button>'
                    : '<span style="color:var(--text3);font-size:.72rem">—</span>')
                + '</td></tr>';
        }).join(''));
    });
}

function loadProducts(brandId, $select) {
    $select.html('<option value="">— No specific product —</option>');
    if (!brandId) return;
    $.get('/marketing/brands/' + brandId + '/products').done(function (r) {
        (r.data || []).filter(p => p.is_active).forEach(function (p) {
            $select.append('<option value="' + p.id + '">' + escRawContent(p.name) + '</option>');
        });
    });
}

$('#filterBrand,#filterCategory,#filterStatus').on('change', loadItems);

$('#newItemBtn').on('click', function () {
    $('#rcNewBrand,#rcNewTitle').val('');
    $('#rcNewCategory').val('raw_content');
    $('#rcNewProduct').html('<option value="">— No specific product —</option>');
    bootstrap.Modal.getOrCreateInstance('#rcNewModal').show();
});
$('#rcNewBrand').on('change', function () { loadProducts($(this).val(), $('#rcNewProduct')); });

$('#rcNewSave').on('click', function () {
    const brandId = $('#rcNewBrand').val();
    if (!brandId) { Swal.fire('Missing brand', 'Select which brand this is for.', 'warning'); return; }
    if (!$('#rcNewTitle').val().trim()) { Swal.fire('Missing title', 'Give it a short title.', 'warning'); return; }

    const $btn = $(this).prop('disabled', true);
    $.post('/marketing/brands/' + brandId + '/content-items', {
        category: $('#rcNewCategory').val(), title: $('#rcNewTitle').val().trim(), product_id: $('#rcNewProduct').val() || null,
    }).done(function () {
        bootstrap.Modal.getInstance('#rcNewModal').hide();
        Swal.fire({ icon: 'success', title: 'Created', timer: 1200, showConfirmButton: false });
        loadItems();
    }).fail(x => Swal.fire('Error', x.responseJSON?.message || Object.values(x.responseJSON?.errors || {}).flat().join(' ') || 'Could not create.', 'error'))
      .always(() => $btn.prop('disabled', false));
});

$('#rcRows').on('click', '.rc-submit-btn', function () {
    $('#rcSubmitItem').val($(this).data('id'));
    $('#rcSubmitBrand').val($(this).data('brand'));
    $('#rcSubmitTitle').text('Submit — ' + $(this).data('title'));
    $('#rcSubmitLink').val('');
    $('#rcSubmitFile').val('');
    bootstrap.Modal.getOrCreateInstance('#rcSubmitModal').show();
});

$('#rcSubmitSave').on('click', function () {
    const link = $('#rcSubmitLink').val().trim();
    const file = $('#rcSubmitFile')[0].files[0];
    if (!link && !file) { Swal.fire('Nothing to submit', 'Add a link or choose a file.', 'warning'); return; }

    const fd = new FormData();
    if (link) fd.append('link_url', link);
    if (file) fd.append('file', file);
    fd.append('_token', $('meta[name=csrf-token]').attr('content'));

    const $btn = $(this).prop('disabled', true);
    $.ajax({
        url: '/marketing/brands/' + $('#rcSubmitBrand').val() + '/content-items/' + $('#rcSubmitItem').val() + '/submit',
        type: 'POST', data: fd, processData: false, contentType: false,
    }).done(function () {
        bootstrap.Modal.getInstance('#rcSubmitModal').hide();
        Swal.fire({ icon: 'success', title: 'Submitted', timer: 1200, showConfirmButton: false });
        loadItems();
    }).fail(x => Swal.fire('Error', x.responseJSON?.message || Object.values(x.responseJSON?.errors || {}).flat().join(' ') || 'Could not submit.', 'error'))
      .always(() => $btn.prop('disabled', false));
});

loadItems();
</script>
@endpush
