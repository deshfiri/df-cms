@extends('layouts.app')
@section('title', 'Import Data')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="page-title mb-0"><i class="bi bi-upload me-2"></i>Import Clients</h4>
</div>

{{-- Step Wizard --}}
<div id="importWizard">
    {{-- Step 1: Upload --}}
    <div id="step1">
        <div class="card section-card mb-3">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0">Step 1 — Upload File</h6>
            </div>
            <div class="card-body">
                <div id="dropzone" class="border-2 border-dashed rounded p-5 text-center" style="border-style:dashed!important;cursor:pointer;background:var(--surface2)">
                    <i class="bi bi-cloud-upload text-primary" style="font-size:3rem"></i>
                    <h6 class="mt-2">Drag & Drop or Click to Upload</h6>
                    <p class="text-muted small mb-0">Supports: XLSX, XLS, CSV · Max 10MB</p>
                    <input type="file" id="importFile" accept=".xlsx,.xls,.csv" class="d-none">
                </div>
                <div id="fileInfo" class="d-none mt-3 alert alert-info py-2">
                    <i class="bi bi-file-earmark-spreadsheet me-2"></i>
                    <span id="fileName"></span>
                    <button class="btn btn-sm btn-outline-danger ms-2" id="clearFile"><i class="bi bi-x"></i></button>
                </div>
                <button id="previewBtn" class="btn btn-primary mt-3 d-none">
                    <i class="bi bi-eye me-1"></i>Preview & Map Columns
                </button>
            </div>
        </div>
    </div>

    {{-- Step 2: Column Mapping --}}
    <div id="step2" class="d-none">
        <div class="card section-card mb-3">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">Step 2 — Map Columns</h6>
                <span class="badge bg-info" id="previewRows"></span>
            </div>
            <div class="card-body">
                <p class="text-muted small">Match your spreadsheet columns to the system fields. <strong>Client Name</strong> and <strong>Brand Name</strong> are required.</p>
                <div class="row g-3" id="mappingFields"></div>

                <h6 class="mt-4 fw-bold">Preview (first 5 rows)</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered" id="previewTable" style="font-size:.8rem"></table>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button id="backStep1" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Back</button>
                    <button id="doImport" class="btn btn-sm btn-primary"><i class="bi bi-upload me-1"></i>Start Import</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Step 3: Result --}}
    <div id="step3" class="d-none">
        <div class="card section-card mb-3">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0">Step 3 — Import Result</h6>
            </div>
            <div class="card-body" id="importResult"></div>
        </div>
    </div>
</div>

{{-- Import Logs --}}
<div class="card section-card mt-4">
    <div class="card-header py-3">
        <h6 class="fw-bold mb-0">Import History</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
                <thead class="table-light">
                    <tr><th class="ps-3">File</th><th>Date</th><th>Total</th><th>Success</th><th>Failed</th><th>Duplicates</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                    <tr>
                        <td class="ps-3">{{ $log->file_name }}</td>
                        <td>{{ $log->created_at->format('d M Y H:i') }}</td>
                        <td>{{ $log->total_rows }}</td>
                        <td class="text-success fw-semibold">{{ $log->success_rows }}</td>
                        <td class="text-danger fw-semibold">{{ $log->failed_rows }}</td>
                        <td class="text-warning fw-semibold">{{ $log->duplicate_rows }}</td>
                        <td>
                            @php $sc = ['completed'=>'success','failed'=>'danger','processing'=>'warning','pending'=>'secondary'][$log->status] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $sc }}">{{ ucfirst($log->status) }}</span>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center text-muted py-3">No imports yet</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const fields = @json($fields);
let previewData = null;
let uploadedFile = null;

// Drag & drop
const dz = document.getElementById('dropzone');
dz.addEventListener('click', () => $('#importFile').click());
dz.addEventListener('dragover', e => { e.preventDefault(); dz.style.background = '#e8f0fe'; });
dz.addEventListener('dragleave', () => dz.style.background = '#f8f9fa');
dz.addEventListener('drop', e => { e.preventDefault(); dz.style.background = '#f8f9fa'; handleFile(e.dataTransfer.files[0]); });
$('#importFile').on('change', function () { handleFile(this.files[0]); });

function handleFile(file) {
    uploadedFile = file;
    $('#fileName').text(file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)');
    $('#fileInfo').removeClass('d-none');
    $('#previewBtn').removeClass('d-none');
}

$('#clearFile').on('click', function () {
    uploadedFile = null;
    $('#importFile').val('');
    $('#fileInfo').addClass('d-none');
    $('#previewBtn').addClass('d-none');
});

$('#previewBtn').on('click', function () {
    if (!uploadedFile) return;
    const fd = new FormData();
    fd.append('file', uploadedFile);
    fd.append('_token', $('meta[name=csrf-token]').attr('content'));

    $(this).html('<span class="spinner-border spinner-border-sm me-1"></span>Loading...').prop('disabled', true);

    $.ajax({ url: '{{ route("import.preview") }}', type: 'POST', data: fd, processData: false, contentType: false })
     .done(function (r) {
        previewData = r;
        $('#previewRows').text(r.total_rows + ' rows detected');
        buildMapping(r.headers);
        buildPreviewTable(r.headers, r.rows);
        $('#step1').addClass('d-none');
        $('#step2').removeClass('d-none');
        $('#previewBtn').html('<i class="bi bi-eye me-1"></i>Preview & Map Columns').prop('disabled', false);
     })
     .fail(function (r) {
        Swal.fire('Error', r.responseJSON?.message || 'Preview failed', 'error');
        $('#previewBtn').html('<i class="bi bi-eye me-1"></i>Preview & Map Columns').prop('disabled', false);
     });
});

function buildMapping(headers) {
    let html = '';
    Object.entries(fields).forEach(([key, label]) => {
        html += `<div class="col-md-4">
            <label class="form-label small fw-semibold">${label}</label>
            <select class="form-select form-select-sm map-field" data-field="${key}">
                <option value="">-- Skip --</option>
                ${headers.map((h, i) => {
                    const normalised = (h || '').toLowerCase().replace(/[\s\-\/]+/g, '_').replace(/[^a-z0-9_]/g, '');
                    const selected = normalised === key ? 'selected' : '';
                    return `<option value="${i}" ${selected}>${h || '(empty col ' + i + ')'}</option>`;
                }).join('')}
            </select>
        </div>`;
    });
    $('#mappingFields').html(html);
}

function buildPreviewTable(headers, rows) {
    let html = `<thead class="table-light"><tr>${headers.map(h => `<th>${h || ''}</th>`).join('')}</tr></thead><tbody>`;
    rows.forEach(r => { html += `<tr>${r.map(c => `<td>${c || ''}</td>`).join('')}</tr>`; });
    html += '</tbody>';
    $('#previewTable').html(html);
}

$('#backStep1').on('click', () => { $('#step2').addClass('d-none'); $('#step1').removeClass('d-none'); });

$('#doImport').on('click', function () {
    const mapping = {};
    $('.map-field').each(function () {
        const f = $(this).data('field');
        const v = $(this).val();
        if (v !== '') mapping[f] = v;
    });

    if (!mapping.client_name && !mapping.brand_name) {
        Swal.fire('Missing', 'Please map at least Client Name or Brand Name.', 'warning');
        return;
    }

    const fd = new FormData();
    fd.append('file', uploadedFile);
    fd.append('mapping', JSON.stringify(mapping));
    fd.append('_token', $('meta[name=csrf-token]').attr('content'));

    $(this).html('<span class="spinner-border spinner-border-sm me-1"></span>Importing...').prop('disabled', true);

    $.ajax({ url: '{{ route("import.store") }}', type: 'POST', data: fd, processData: false, contentType: false })
     .done(function (r) {
        $('#step2').addClass('d-none');
        $('#step3').removeClass('d-none');
        const l = r.log;
        const newRows     = (l.success_rows || 0) - (l.updated_rows || 0);
        const updatedRows = l.updated_rows  || 0;
        const skippedRows = l.skipped_rows  || 0;
        const failedRows  = l.failed_rows   || 0;
        const statusClr   = r.success ? 'success' : 'warning';
        const allErrors   = [...(l.errors || []), ...(l.validation_errors || [])];
        $('#importResult').html(`
            <div class="alert alert-${statusClr}">
                <h6 class="fw-bold">${r.success ? '<i class="bi bi-check-circle me-2"></i>' : '<i class="bi bi-exclamation-triangle me-2"></i>'}${r.message}</h6>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-2"><div class="card text-center p-3"><div class="fs-4 fw-bold">${l.total_rows || 0}</div><small class="text-muted">Total</small></div></div>
                <div class="col-6 col-md-2"><div class="card text-center p-3 bg-success bg-opacity-10"><div class="fs-4 fw-bold text-success">${newRows}</div><small class="text-muted">New</small></div></div>
                <div class="col-6 col-md-2"><div class="card text-center p-3 bg-primary bg-opacity-10"><div class="fs-4 fw-bold text-primary">${updatedRows}</div><small class="text-muted">Updated</small></div></div>
                <div class="col-6 col-md-2"><div class="card text-center p-3 bg-secondary bg-opacity-10"><div class="fs-4 fw-bold text-secondary">${skippedRows}</div><small class="text-muted">Unchanged</small></div></div>
                <div class="col-6 col-md-2"><div class="card text-center p-3 bg-danger bg-opacity-10"><div class="fs-4 fw-bold text-danger">${failedRows}</div><small class="text-muted">Failed</small></div></div>
                <div class="col-6 col-md-2"><div class="card text-center p-3 bg-warning bg-opacity-10"><div class="fs-4 fw-bold text-warning">${l.duplicate_rows || 0}</div><small class="text-muted">Dup in file</small></div></div>
            </div>
            ${allErrors.length ? `<div class="mt-2"><h6 class="text-danger small fw-bold">Errors (${allErrors.length}):</h6><ul class="small text-danger mb-2">${allErrors.slice(0,30).map(e=>`<li>${e}</li>`).join('')}</ul></div>` : ''}
            <div class="mt-3 d-flex gap-2">
                <a href="{{ route('clients.index') }}" class="btn btn-primary btn-sm"><i class="bi bi-people me-1"></i>View Clients</a>
                <button onclick="location.reload()" class="btn btn-sm btn-light"><i class="bi bi-arrow-repeat me-1"></i>New Import</button>
            </div>
        `);
     }).fail(function () {
        Swal.fire('Error', 'Import failed', 'error');
        $('#doImport').html('<i class="bi bi-upload me-1"></i>Start Import').prop('disabled', false);
     });
});
</script>
@endpush
