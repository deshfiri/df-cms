@extends('layouts.app')
@section('title', 'Workflow Stages')

@php
    $departments = ['Sales', 'Document', 'Design', 'Website', 'Product', 'Marketing', 'Support', 'Admin'];
@endphp

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="page-title mb-0"><i class="bi bi-diagram-3 me-2"></i>Workflow Stages</h4>
    @can('manage-workflow')
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addStageModal">
        <i class="bi bi-plus-lg me-1"></i>Add Stage
    </button>
    @endcan
</div>

<div class="card section-card">
    <div class="card-body">
        <p class="text-muted small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Stages are applied to all clients automatically. Drag to reorder. Deleting a stage permanently wipes its progress history for every client — use Merge instead if you want to keep that history.
        </p>
        <div id="stageList">
            @foreach($stages as $stage)
            <div class="d-flex align-items-center gap-3 p-3 border rounded mb-2 stage-row" style="background:var(--surface);border-color:var(--border) !important" data-id="{{ $stage->id }}">
                <i class="bi bi-grip-vertical text-muted drag-handle" style="cursor:grab;font-size:1.1rem"></i>
                <span class="badge bg-secondary sort-badge">{{ $stage->sort_order }}</span>
                <span class="fw-semibold flex-grow-1">{{ $stage->name }}</span>
                @if($stage->department)
                <span style="font-size:.68rem;background:rgba(var(--primary-rgb),.1);color:var(--primary);padding:2px 8px;border-radius:20px">{{ $stage->department }}</span>
                @endif
                @if(!$stage->requires_approval)
                <span class="badge bg-info">Auto-approve</span>
                @endif
                <span class="badge {{ $stage->status ? 'bg-success' : 'bg-danger' }}">{{ $stage->status ? 'Active' : 'Inactive' }}</span>
                @can('manage-workflow')
                <button class="btn btn-sm btn-outline-warning btn-edit-stage" data-id="{{ $stage->id }}" data-name="{{ $stage->name }}" data-status="{{ $stage->status ? '1' : '0' }}" data-department="{{ $stage->department }}" data-requires-approval="{{ $stage->requires_approval ? '1' : '0' }}">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary btn-merge-stage" data-id="{{ $stage->id }}" data-name="{{ $stage->name }}" title="Merge into another stage">
                    <i class="bi bi-arrow-down-up"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger btn-del-stage" data-id="{{ $stage->id }}" data-name="{{ $stage->name }}">
                    <i class="bi bi-trash"></i>
                </button>
                @endcan
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- Add Stage Modal --}}
<div class="modal fade" id="addStageModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold">New Workflow Stage</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Stage Name <span class="text-danger">*</span></label>
                    <input type="text" id="stageName" class="form-control" placeholder="e.g. Product Launch">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Department</label>
                    <select id="stageDepartment" class="form-select">
                        <option value="">None</option>
                        @foreach($departments as $d)
                        <option value="{{ $d }}">{{ $d }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-check">
                    <input type="checkbox" id="stageRequiresApproval" class="form-check-input" checked>
                    <label class="form-check-label small" for="stageRequiresApproval">Requires approval before unlocking next stage</label>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="saveStage" class="btn btn-sm btn-primary">Add Stage</button>
            </div>
        </div>
    </div>
</div>

{{-- Edit Stage Modal --}}
<div class="modal fade" id="editStageModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold">Edit Stage</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editStageId">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Stage Name</label>
                    <input type="text" id="editStageName" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Status</label>
                    <select id="editStageStatus" class="form-select">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Department</label>
                    <select id="editStageDepartment" class="form-select">
                        <option value="">None</option>
                        @foreach($departments as $d)
                        <option value="{{ $d }}">{{ $d }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-check">
                    <input type="checkbox" id="editStageRequiresApproval" class="form-check-input">
                    <label class="form-check-label small" for="editStageRequiresApproval">Requires approval before unlocking next stage</label>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="updateStage" class="btn btn-sm btn-warning">Update</button>
            </div>
        </div>
    </div>
</div>

{{-- Merge Stage Modal --}}
<div class="modal fade" id="mergeStageModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold">Merge Stage</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="mergeSourceId">
                <p class="small text-muted">Merging <strong id="mergeSourceName"></strong> into the stage below. For every client where <strong id="mergeSourceName2"></strong> is already Approved, the target stage will be marked Approved too. <strong id="mergeSourceName3"></strong> will then be retired from the pipeline (its history is kept, not deleted).</p>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Merge Into <span class="text-danger">*</span></label>
                    <select id="mergeTargetId" class="form-select">
                        <option value="">Select target stage…</option>
                        @foreach($stages as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="confirmMerge" class="btn btn-sm btn-primary">Merge</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
.sortable-ghost { opacity: .4; background: rgba(var(--primary-rgb),.08) !important; border-color: var(--primary) !important; }
.drag-handle:hover { color: var(--primary) !important; }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
const csrf = $('meta[name=csrf-token]').attr('content');

// ── Drag-and-drop reorder ────────────────────────────────────────────────────
Sortable.create(document.getElementById('stageList'), {
    handle: '.drag-handle',
    animation: 150,
    ghostClass: 'sortable-ghost',
    onEnd: function () {
        const stages = [];
        $('#stageList .stage-row').each(function (index) {
            stages.push({ id: $(this).data('id'), sort_order: index + 1 });
            // Update the visible badge immediately
            $(this).find('.sort-badge').text(index + 1);
        });

        $.post('{{ route("workflow.reorder") }}', { stages: stages, _token: csrf })
         .done(() => {
            // Brief green flash to confirm save
            $('#stageList').addClass('border-success');
            setTimeout(() => $('#stageList').removeClass('border-success'), 800);
         })
         .fail(() => Swal.fire('Error', 'Could not save the new order.', 'error'));
    }
});

// ── Add stage ────────────────────────────────────────────────────────────────
$('#saveStage').on('click', function () {
    const name = $.trim($('#stageName').val());
    if (!name) return;
    $.post('{{ route("workflow.store") }}', {
        name,
        department: $('#stageDepartment').val(),
        requires_approval: $('#stageRequiresApproval').is(':checked') ? 1 : 0,
        _token: csrf
    })
     .done(function () { location.reload(); })
     .fail(function (r) { Swal.fire('Error', r.responseJSON?.message || 'Failed', 'error'); });
});

$('#stageName').on('keydown', function (e) { if (e.key === 'Enter') $('#saveStage').click(); });

// ── Edit stage ───────────────────────────────────────────────────────────────
$(document).on('click', '.btn-edit-stage', function () {
    $('#editStageId').val($(this).data('id'));
    $('#editStageName').val($(this).data('name'));
    $('#editStageStatus').val($(this).data('status'));
    $('#editStageDepartment').val($(this).data('department') || '');
    $('#editStageRequiresApproval').prop('checked', $(this).data('requires-approval') == 1);
    new bootstrap.Modal('#editStageModal').show();
});

$('#updateStage').on('click', function () {
    const id = $('#editStageId').val();
    $.ajax({
        url: '/workflow/' + id,
        type: 'PUT',
        data: {
            name: $('#editStageName').val(),
            status: $('#editStageStatus').val(),
            department: $('#editStageDepartment').val(),
            requires_approval: $('#editStageRequiresApproval').is(':checked') ? 1 : 0,
            _token: csrf
        }
    }).done(function () { location.reload(); })
      .fail(r => Swal.fire('Error', r.responseJSON?.message || 'Failed', 'error'));
});

// ── Merge stage ──────────────────────────────────────────────────────────────
$(document).on('click', '.btn-merge-stage', function () {
    const id = $(this).data('id'), name = $(this).data('name');
    $('#mergeSourceId').val(id);
    $('#mergeSourceName, #mergeSourceName2, #mergeSourceName3').text(name);
    $('#mergeTargetId').val('').find('option[value="' + id + '"]').prop('disabled', true);
    new bootstrap.Modal('#mergeStageModal').show();
});

$('#confirmMerge').on('click', function () {
    const sourceId = $('#mergeSourceId').val();
    const targetId = $('#mergeTargetId').val();
    const sourceName = $('#mergeSourceName').text();
    if (!targetId) return;

    Swal.fire({
        title: 'Merge stage?',
        html: `<strong>${sourceName}</strong> will be retired and its approved clients carried over. This cannot be easily undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, merge'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('/workflow/' + sourceId + '/merge', { target_id: targetId, _token: csrf })
         .done(() => location.reload())
         .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Could not merge stage.', 'error'));
    });
});

// ── Delete stage ─────────────────────────────────────────────────────────────
$(document).on('click', '.btn-del-stage', function () {
    const id = $(this).data('id');
    const name = $(this).data('name');
    Swal.fire({
        title: 'Permanently delete "' + name + '"?',
        html: 'This <strong>permanently wipes the progress history</strong> for this stage across <strong>every client</strong> — who approved it, when, and any remarks. This cannot be undone.<br><br>If you just want to retire this stage while keeping its history, use <strong>Merge</strong> instead.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, permanently delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.ajax({ url: '/workflow/' + id, type: 'DELETE', data: { _token: csrf } })
         .done(() => location.reload())
         .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Failed', 'error'));
    });
});
</script>
@endpush
