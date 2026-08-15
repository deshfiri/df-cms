@extends('layouts.app')
@section('title', 'Workflow · ' . $flow->name)

@push('styles')
<style>
    .stage-row { display: flex; align-items: center; gap: .75rem; padding: .75rem .9rem; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); margin-bottom: .5rem; }
    .stage-pos { width: 30px; height: 30px; border-radius: 50%; background: var(--primary); color: #fff; display: grid; place-items: center; font-weight: 700; font-size: .8rem; flex-shrink: 0; }
    .stage-users { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: 3px; }
    .stage-chip { font-size: .66rem; background: var(--surface2); border: 1px solid var(--border); color: var(--text2); border-radius: 999px; padding: 1px 8px; }
    .stage-none { font-size: .68rem; color: #dc3545; }
</style>
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <a href="{{ route('workflows.index') }}" class="small text-decoration-none" style="color:var(--text3)"><i class="bi bi-arrow-left me-1"></i>Workflows</a>
        <h4 class="page-title mb-0 mt-1">{{ $flow->name }}
            <span class="spill {{ $flow->is_active ? 'spill-completed' : 'spill-hold' }} ms-2" style="font-size:.7rem">{{ $flow->is_active ? 'Active' : 'Inactive' }}</span>
        </h4>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('workflows.items', ['flow' => $flow->id]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list-task me-1"></i>Items</a>
        <button class="btn btn-sm btn-primary" id="startItemBtn"><i class="bi bi-plus-lg me-1"></i>Start Item</button>
    </div>
</div>

<div class="card section-card">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">Stages <span style="font-size:.7rem;color:var(--text3);font-weight:400">— in order; items move top → bottom</span></h6>
    </div>
    <div class="card-body">
        <div class="d-flex gap-2 mb-3">
            <input type="text" id="newStageName" class="form-control form-control-sm" placeholder="New stage name…" maxlength="150">
            <button class="btn btn-sm btn-primary" id="addStageBtn" style="white-space:nowrap"><i class="bi bi-plus-lg me-1"></i>Add Stage</button>
        </div>

        <div id="stageList">
            @forelse($flow->stages as $stage)
                <div class="stage-row" data-id="{{ $stage->id }}" data-assigned="{{ $stage->users->pluck('id')->implode(',') }}">
                    <span class="stage-pos">{{ $loop->iteration }}</span>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold small" style="color:var(--text)">{{ $stage->name }}</div>
                        <div class="stage-users">
                            @forelse($stage->users as $u)
                                <span class="stage-chip">{{ $u->name }}</span>
                            @empty
                                <span class="stage-none"><i class="bi bi-exclamation-triangle me-1"></i>No one assigned</span>
                            @endforelse
                        </div>
                    </div>
                    <div class="d-flex gap-1 flex-shrink-0">
                        <button class="btn btn-sm btn-outline-secondary py-0 px-1 stage-up" title="Move up"><i class="bi bi-arrow-up"></i></button>
                        <button class="btn btn-sm btn-outline-secondary py-0 px-1 stage-down" title="Move down"><i class="bi bi-arrow-down"></i></button>
                        <button class="btn btn-sm btn-outline-primary py-0 px-2 stage-assign" data-name="{{ e($stage->name) }}" style="font-size:.72rem"><i class="bi bi-people me-1"></i>Assign</button>
                        <button class="btn btn-sm btn-outline-secondary py-0 px-1 stage-rename" data-name="{{ e($stage->name) }}" title="Rename"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-outline-danger py-0 px-1 stage-delete" title="Delete"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
            @empty
                <div class="text-center py-4" style="color:var(--text3);font-size:.85rem" id="noStages">No stages yet — add the first one above.</div>
            @endforelse
        </div>
    </div>
</div>

{{-- Assign-users modal --}}
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2 px-3"><h6 class="modal-title fw-bold">Assign to <span id="assignStageName"></span></h6><button class="btn-close btn-sm" data-bs-dismiss="modal"></button></div>
            <div class="modal-body px-3 py-3" style="max-height:50vh;overflow-y:auto">
                <input type="hidden" id="assignStageId">
                <input type="text" class="form-control form-control-sm mb-2" id="assignFilter" placeholder="Filter people…">
                <div id="assignUsers">
                    @foreach($users as $u)
                        <label class="d-flex align-items-center gap-2 py-1 assign-user" data-name="{{ Str::lower($u->name) }}" style="cursor:pointer">
                            <input type="checkbox" class="form-check-input mt-0 assign-cb" value="{{ $u->id }}">
                            <span class="small">{{ $u->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="modal-footer py-2 px-3">
                <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-sm btn-primary" id="assignSave">Save assignment</button>
            </div>
        </div>
    </div>
</div>

{{-- Start-item modal --}}
<div class="modal fade" id="startModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2 px-3"><h6 class="modal-title fw-bold">Start Item — {{ $flow->name }}</h6><button class="btn-close btn-sm" data-bs-dismiss="modal"></button></div>
            <div class="modal-body px-3 py-3">
                <div class="mb-3"><label class="form-label fw-semibold small">Title</label><input type="text" id="itemTitle" class="form-control form-control-sm" maxlength="200"></div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label fw-semibold small">Priority</label>
                        <select id="itemPriority" class="form-select form-select-sm">
                            @foreach(\App\Models\FlowItem::$priorities as $p)<option value="{{ $p }}" @selected($p === 'Normal')>{{ $p }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-6"><label class="form-label fw-semibold small">Due date</label><input type="date" id="itemDue" class="form-control form-control-sm"></div>
                </div>
                <div><label class="form-label fw-semibold small">Details <span style="color:var(--text3)">(optional)</span></label><textarea id="itemDesc" class="form-control form-control-sm" rows="3" maxlength="5000"></textarea></div>
            </div>
            <div class="modal-footer py-2 px-3">
                <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-sm btn-primary" id="startSave"><i class="bi bi-send me-1"></i>Create &amp; enter Stage 1</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const FLOW = {{ $flow->id }};
    const fail = r => Swal.fire('Error', r.responseJSON?.message || 'Something went wrong', 'error');

    $('#addStageBtn').on('click', function () {
        const name = $.trim($('#newStageName').val());
        if (!name) return;
        $.post('/workflows/' + FLOW + '/stages', { name }).done(() => location.reload()).fail(fail);
    });
    $('#newStageName').on('keydown', e => { if (e.key === 'Enter') $('#addStageBtn').click(); });

    $(document).on('click', '.stage-rename', function () {
        const row = $(this).closest('.stage-row');
        Swal.fire({ title: 'Rename stage', input: 'text', inputValue: $(this).data('name'), showCancelButton: true })
            .then(r => { if (r.isConfirmed && r.value) $.ajax({ url: '/workflows/stages/' + row.data('id'), type: 'PUT', data: { name: r.value } }).done(() => location.reload()).fail(fail); });
    });
    $(document).on('click', '.stage-delete', function () {
        const row = $(this).closest('.stage-row');
        Swal.fire({ title: 'Delete stage?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545' })
            .then(r => { if (r.isConfirmed) $.ajax({ url: '/workflows/stages/' + row.data('id'), type: 'DELETE' }).done(() => location.reload()).fail(fail); });
    });

    // Reorder (up/down) → persist the new order.
    function persistOrder() {
        const order = $('#stageList .stage-row').map(function () { return $(this).data('id'); }).get();
        $.post('/workflows/' + FLOW + '/reorder', { order }).done(() => location.reload()).fail(fail);
    }
    $(document).on('click', '.stage-up', function () {
        const row = $(this).closest('.stage-row'); const prev = row.prev('.stage-row');
        if (prev.length) { row.insertBefore(prev); persistOrder(); }
    });
    $(document).on('click', '.stage-down', function () {
        const row = $(this).closest('.stage-row'); const next = row.next('.stage-row');
        if (next.length) { row.insertAfter(next); persistOrder(); }
    });

    // Assign users.
    $(document).on('click', '.stage-assign', function () {
        const row = $(this).closest('.stage-row');
        const assigned = String(row.data('assigned') || '').split(',').filter(Boolean).map(Number);
        $('#assignStageId').val(row.data('id'));
        $('#assignStageName').text($(this).data('name'));
        $('#assignFilter').val('');
        $('.assign-user').show();
        $('.assign-cb').each(function () { this.checked = assigned.includes(Number(this.value)); });
        new bootstrap.Modal('#assignModal').show();
    });
    $('#assignFilter').on('input', function () {
        const q = $(this).val().toLowerCase();
        $('.assign-user').each(function () { $(this).toggle($(this).data('name').indexOf(q) !== -1); });
    });
    $('#assignSave').on('click', function () {
        const ids = $('.assign-cb:checked').map(function () { return this.value; }).get();
        $.post('/workflows/stages/' + $('#assignStageId').val() + '/users', { user_ids: ids }).done(() => location.reload()).fail(fail);
    });

    // Start item.
    $('#startItemBtn').on('click', function () {
        @if($flow->stages->isEmpty())
            Swal.fire('Add a stage first', 'A workflow needs at least one stage before you can start items.', 'info'); return;
        @endif
        $('#itemTitle').val(''); $('#itemDesc').val('');
        new bootstrap.Modal('#startModal').show();
    });
    $('#startSave').on('click', function () {
        const title = $.trim($('#itemTitle').val());
        if (!title) return;
        $.post('/flow-items', { flow_id: FLOW, title, priority: $('#itemPriority').val(), due_date: $('#itemDue').val() || null, description: $('#itemDesc').val() })
            .done(function () { bootstrap.Modal.getInstance('#startModal').hide(); Swal.fire({ icon: 'success', title: 'Item started', timer: 1300, showConfirmButton: false }); })
            .fail(fail);
    });
</script>
@endpush
