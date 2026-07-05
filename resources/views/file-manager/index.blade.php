@extends('layouts.app')
@section('title', 'File Manager')

@push('styles')
<style>
.fm-row { cursor: default; }
.fm-row.fm-folder { cursor: pointer; }
.fm-row.fm-folder:hover { background: var(--surface2); }
.fm-breadcrumb a { color: var(--primary); text-decoration: none; }
.fm-breadcrumb a:hover { text-decoration: underline; }
#fmDropZone { border: 2px dashed var(--border); border-radius: var(--radius); }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="page-title mb-0"><i class="bi bi-folder2-open me-2"></i>File Manager</h4>
    @can('manage file-manager')
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" id="fmNewFolderBtn">
            <i class="bi bi-folder-plus me-1"></i>New Folder
        </button>
        <button class="btn btn-sm btn-primary" id="fmUploadBtn">
            <i class="bi bi-upload me-1"></i>Upload
        </button>
        <input type="file" id="fmFileInput" class="d-none">
    </div>
    @endcan
</div>

<div class="card section-card">
    <div class="card-header py-3">
        <nav class="fm-breadcrumb small" id="fmBreadcrumb"></nav>
    </div>
    <div class="card-body p-0" id="fmDropZone">
        <div class="card-body p-0" id="fmList">
            <div class="text-center py-5">
                <div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div>
            </div>
        </div>
    </div>
</div>

{{-- Preview Modal --}}
<div class="modal fade" id="fmPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="height:85vh">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold text-truncate" id="fmPreviewTitle"></h6>
                <div class="d-flex align-items-center gap-2">
                    <a href="#" id="fmPreviewDownload" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i></a>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0 d-flex align-items-center justify-content-center overflow-auto" id="fmPreviewBody"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const fmListUrl   = '{{ route('file-manager.list') }}';
const fmFolderUrl = '{{ route('file-manager.folder.create') }}';
const fmUploadUrl = '{{ route('file-manager.upload') }}';
const fmDownloadUrl = '{{ route('file-manager.download') }}';
const fmPreviewUrl = '{{ route('file-manager.preview') }}';
const fmRenameUrl  = '{{ route('file-manager.rename') }}';
const fmDeleteUrl  = '{{ route('file-manager.destroy') }}';
const canManageFm  = @json(auth()->user()->can('manage file-manager'));

let fmPath = '';

function fmEsc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmLoad(path) {
    fmPath = path || '';
    $('#fmList').html('<div class="text-center py-5"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div></div>');
    $.get(fmListUrl, { path: fmPath })
    .done(function (res) {
        fmRenderBreadcrumb(res.breadcrumb || []);
        fmRenderItems(res.items || []);
    })
    .fail(function (r) {
        $('#fmList').html('<div class="text-center py-4 small c-red"><i class="bi bi-exclamation-circle me-1"></i>' + (r.responseJSON?.message || 'Failed to load folder.') + '</div>');
    });
}

function fmRenderBreadcrumb(crumbs) {
    let html = '<a href="#" class="fm-crumb" data-path=""><i class="bi bi-house-door me-1"></i>Root</a>';
    crumbs.forEach(function (c) {
        html += ' <span class="text-muted mx-1">/</span> <a href="#" class="fm-crumb" data-path="' + fmEsc(c.path) + '">' + fmEsc(c.name) + '</a>';
    });
    $('#fmBreadcrumb').html(html);
}

function fmRenderItems(items) {
    if (!items.length) {
        $('#fmList').html('<div class="text-center py-5" style="color:var(--text3)"><i class="bi bi-folder2-open" style="font-size:2rem"></i><div class="mt-2" style="font-size:.82rem">This folder is empty.</div></div>');
        return;
    }

    let html = '<table class="table align-middle mb-0" style="font-size:.83rem"><thead><tr>'
        + '<th style="width:36px"></th><th>Name</th><th>Size</th><th>Modified</th><th class="text-end pe-3">Actions</th>'
        + '</tr></thead><tbody>';

    items.forEach(function (item) {
        const icon = item.is_dir ? 'bi-folder-fill' : 'bi-file-earmark';
        const color = item.is_dir ? '#f5a623' : 'var(--text3)';
        const rowCls = item.is_dir ? 'fm-row fm-folder' : 'fm-row';

        let actions = '';
        if (!item.is_dir) {
            if (item.is_image || item.is_pdf) {
                actions += '<button class="btn btn-sm px-2 py-1 fm-preview" data-path="' + fmEsc(item.path) + '" data-name="' + fmEsc(item.name) + '" data-is-pdf="' + (item.is_pdf ? '1' : '0') + '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Preview"><i class="bi bi-eye"></i></button>';
            }
            actions += '<a href="' + fmDownloadUrl + '?path=' + encodeURIComponent(item.path) + '" class="btn btn-sm px-2 py-1" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Download"><i class="bi bi-download"></i></a>';
        }
        if (canManageFm) {
            actions += '<button class="btn btn-sm px-2 py-1 fm-rename" data-path="' + fmEsc(item.path) + '" data-name="' + fmEsc(item.name) + '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Rename"><i class="bi bi-pencil"></i></button>';
            actions += '<button class="btn btn-sm px-2 py-1 fm-delete c-red" data-path="' + fmEsc(item.path) + '" style="background:var(--c-red-bg);border:1px solid var(--c-red-bg)" title="Delete"><i class="bi bi-trash"></i></button>';
        }

        html += '<tr class="' + rowCls + '" data-path="' + fmEsc(item.path) + '" data-is-dir="' + (item.is_dir ? '1' : '0') + '">'
            + '<td class="ps-3"><i class="bi ' + icon + '" style="font-size:1.2rem;color:' + color + '"></i></td>'
            + '<td>' + fmEsc(item.name) + '</td>'
            + '<td style="color:var(--text3)">' + (item.size || '—') + '</td>'
            + '<td style="color:var(--text3)">' + (item.modified || '—') + '</td>'
            + '<td class="text-end pe-3"><div class="d-flex gap-1 justify-content-end">' + actions + '</div></td>'
            + '</tr>';
    });

    html += '</tbody></table>';
    $('#fmList').html(html);
}

$(document).on('click', '.fm-crumb', function (e) {
    e.preventDefault();
    fmLoad($(this).data('path') || '');
});

$(document).on('click', '.fm-row.fm-folder', function (e) {
    if ($(e.target).closest('button, a').length) return;
    fmLoad($(this).data('path'));
});

$('#fmNewFolderBtn').on('click', function () {
    Swal.fire({
        title: 'New Folder',
        input: 'text',
        inputPlaceholder: 'Folder name',
        showCancelButton: true,
        confirmButtonText: 'Create',
    }).then(function (r) {
        if (!r.isConfirmed || !r.value?.trim()) return;
        $.post(fmFolderUrl, { path: fmPath, name: r.value.trim() })
        .done(function () { fmLoad(fmPath); })
        .fail(function (x) { Swal.fire('Error', x.responseJSON?.message || 'Could not create folder.', 'error'); });
    });
});

$('#fmUploadBtn').on('click', function () { $('#fmFileInput').trigger('click'); });

$('#fmFileInput').on('change', function () {
    const file = this.files[0];
    if (!file) return;
    fmUploadFile(file);
    this.value = '';
});

function fmUploadFile(file) {
    const fd = new FormData();
    fd.append('path', fmPath);
    fd.append('file', file);
    fd.append('_token', $('meta[name="csrf-token"]').attr('content'));

    $.ajax({ url: fmUploadUrl, type: 'POST', data: fd, processData: false, contentType: false })
    .done(function () {
        fmLoad(fmPath);
        Swal.fire({ icon: 'success', title: 'Uploaded', timer: 1200, showConfirmButton: false });
    })
    .fail(function (r) { Swal.fire('Error', r.responseJSON?.message || 'Upload failed.', 'error'); });
}

@can('manage file-manager')
const $fmDropZone = $('#fmDropZone');
$fmDropZone.on('dragover', function (e) { e.preventDefault(); $(this).css('background', 'var(--surface2)'); });
$fmDropZone.on('dragleave', function () { $(this).css('background', ''); });
$fmDropZone.on('drop', function (e) {
    e.preventDefault();
    $(this).css('background', '');
    const files = e.originalEvent.dataTransfer.files;
    if (files.length) fmUploadFile(files[0]);
});
@endcan

$(document).on('click', '.fm-preview', function () {
    const path = $(this).data('path');
    const name = $(this).data('name');
    const isPdf = $(this).data('is-pdf') == 1;
    const url = fmPreviewUrl + '?path=' + encodeURIComponent(path);

    $('#fmPreviewTitle').text(name);
    $('#fmPreviewDownload').attr('href', fmDownloadUrl + '?path=' + encodeURIComponent(path));

    const body = isPdf
        ? '<iframe src="' + url + '" style="width:100%;height:100%;border:none"></iframe>'
        : '<div class="text-center p-3"><img src="' + url + '" style="max-width:100%;max-height:calc(85vh - 60px);object-fit:contain"></div>';
    $('#fmPreviewBody').html(body);
    new bootstrap.Modal('#fmPreviewModal').show();
});

$(document).on('click', '.fm-rename', function () {
    const path = $(this).data('path');
    const name = $(this).data('name');
    Swal.fire({
        title: 'Rename',
        input: 'text',
        inputValue: name,
        showCancelButton: true,
        confirmButtonText: 'Rename',
    }).then(function (r) {
        if (!r.isConfirmed || !r.value?.trim() || r.value.trim() === name) return;
        $.post(fmRenameUrl, { path: path, name: r.value.trim() })
        .done(function () { fmLoad(fmPath); })
        .fail(function (x) { Swal.fire('Error', x.responseJSON?.message || 'Could not rename.', 'error'); });
    });
});

$(document).on('click', '.fm-delete', function () {
    const path = $(this).data('path');
    Swal.fire({ title: 'Delete this item?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' })
    .then(function (r) {
        if (!r.isConfirmed) return;
        $.ajax({ url: fmDeleteUrl, type: 'DELETE', data: { path: path } })
        .done(function () { fmLoad(fmPath); })
        .fail(function (x) { Swal.fire('Error', x.responseJSON?.message || 'Could not delete.', 'error'); });
    });
});

fmLoad('');
</script>
@endpush
