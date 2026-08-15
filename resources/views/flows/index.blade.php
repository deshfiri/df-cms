@extends('layouts.app')
@section('title', 'Workflows')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-diagram-2 me-2"></i>Workflows</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Build multi-stage processes, assign people to stages, and track every item.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('workflows.items') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list-task me-1"></i>All Items</a>
        <button class="btn btn-sm btn-primary" id="newFlowBtn"><i class="bi bi-plus-lg me-1"></i>New Workflow</button>
    </div>
</div>

<div class="row g-3">
    @forelse($flows as $flow)
        <div class="col-md-6 col-xl-4">
            <div class="card section-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div class="min-w-0">
                            <a href="{{ route('workflows.show', $flow) }}" class="fw-bold text-decoration-none" style="color:var(--text);font-size:.95rem">{{ $flow->name }}</a>
                            <div style="font-size:.72rem;color:var(--text3)">{{ $flow->stages_count }} stage(s) · {{ $flow->items_count }} item(s)</div>
                        </div>
                        <span class="spill {{ $flow->is_active ? 'spill-completed' : 'spill-hold' }} flow-status" data-id="{{ $flow->id }}" style="cursor:pointer" title="Toggle active">{{ $flow->is_active ? 'Active' : 'Inactive' }}</span>
                    </div>
                    @if($flow->description)
                        <p class="small mb-3" style="color:var(--text2)">{{ Str::limit($flow->description, 120) }}</p>
                    @else
                        <div class="mb-3"></div>
                    @endif
                    <div class="d-flex gap-2">
                        <a href="{{ route('workflows.show', $flow) }}" class="btn btn-sm btn-primary flex-fill"><i class="bi bi-diagram-3 me-1"></i>Build</a>
                        <a href="{{ route('workflows.items', ['flow' => $flow->id]) }}" class="btn btn-sm btn-outline-secondary" title="Items"><i class="bi bi-list-task"></i></a>
                        <button class="btn btn-sm btn-outline-secondary flow-edit" data-id="{{ $flow->id }}" data-name="{{ e($flow->name) }}" data-desc="{{ e($flow->description) }}" title="Edit"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-outline-danger flow-delete" data-id="{{ $flow->id }}" title="Delete"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            <div class="card section-card">
                <div class="card-body text-center py-5" style="color:var(--text3)">
                    <i class="bi bi-diagram-2" style="font-size:2.4rem"></i>
                    <div class="mt-2" style="font-size:.9rem">No workflows yet. Create your first one.</div>
                </div>
            </div>
        </div>
    @endforelse
</div>

<div class="modal fade" id="flowModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2 px-3"><h6 class="modal-title fw-bold" id="flowModalTitle">New Workflow</h6><button class="btn-close btn-sm" data-bs-dismiss="modal"></button></div>
            <div class="modal-body px-3 py-3">
                <input type="hidden" id="flowId">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Name</label>
                    <input type="text" id="flowName" class="form-control form-control-sm" maxlength="150" placeholder="e.g. Content Approval">
                </div>
                <div>
                    <label class="form-label fw-semibold small">Description <span style="color:var(--text3)">(optional)</span></label>
                    <textarea id="flowDesc" class="form-control form-control-sm" rows="2" maxlength="2000"></textarea>
                </div>
            </div>
            <div class="modal-footer py-2 px-3">
                <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-sm btn-primary" id="flowSave">Save</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const fail = r => Swal.fire('Error', r.responseJSON?.message || 'Something went wrong', 'error');

    $('#newFlowBtn').on('click', function () {
        $('#flowId').val(''); $('#flowName').val(''); $('#flowDesc').val('');
        $('#flowModalTitle').text('New Workflow');
        new bootstrap.Modal('#flowModal').show();
    });
    $(document).on('click', '.flow-edit', function () {
        $('#flowId').val($(this).data('id'));
        $('#flowName').val($(this).data('name'));
        $('#flowDesc').val($(this).data('desc'));
        $('#flowModalTitle').text('Edit Workflow');
        new bootstrap.Modal('#flowModal').show();
    });
    $('#flowSave').on('click', function () {
        const id = $('#flowId').val();
        const payload = { name: $('#flowName').val(), description: $('#flowDesc').val() };
        const req = id ? $.ajax({ url: '/workflows/' + id, type: 'PUT', data: payload }) : $.post('/workflows', payload);
        req.done(function (r) {
            if (!id && r.id) { location.href = '/workflows/' + r.id; return; }
            location.reload();
        }).fail(fail);
    });
    $(document).on('click', '.flow-status', function () {
        $.post('/workflows/' + $(this).data('id') + '/toggle').done(() => location.reload()).fail(fail);
    });
    $(document).on('click', '.flow-delete', function () {
        const id = $(this).data('id');
        Swal.fire({ title: 'Delete workflow?', text: 'Items and history are kept, but the workflow is removed.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545' })
            .then(r => { if (r.isConfirmed) $.ajax({ url: '/workflows/' + id, type: 'DELETE' }).done(() => location.reload()).fail(fail); });
    });
</script>
@endpush
