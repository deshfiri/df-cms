@extends('layouts.app')
@section('title', 'My Queue')

@php
    $prioSpill = fn ($p) => match ($p) {
        'Urgent' => 'spill-cancelled',
        'High'   => 'spill-warning',
        'Normal' => 'spill-running',
        default  => 'spill-hold',
    };
    $overdue  = $items->filter(fn ($i) => $i->isOverdue())->count();
    $dueToday = $items->filter(fn ($i) => $i->due_date && $i->due_date->isToday() && $i->isOpen())->count();
    $queueFlows = $items->map(fn ($i) => ['id' => $i->flow_id, 'name' => $i->flow->name ?? '—'])->unique('id')->values();
@endphp

@push('styles')
<style>
    .q-tile { border: 1px solid var(--border); border-radius: var(--radius); padding: .7rem .9rem; background: var(--surface); }
    .q-tile .v { font-size: 1.5rem; font-weight: 800; line-height: 1; }
    .q-tile .l { font-size: .66rem; color: var(--text3); text-transform: uppercase; letter-spacing: .04em; margin-top: 3px; }
    .q-row.hide { display: none !important; }
</style>
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-inboxes me-2"></i>My Queue</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Ordered by urgency — overdue and high-priority first.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('flow.history') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-clock-history me-1"></i>History</a>
        @if($startable->isNotEmpty())
            <button class="btn btn-sm btn-primary" id="newItemBtn"><i class="bi bi-plus-lg me-1"></i>New Item</button>
        @endif
    </div>
</div>

<div class="row g-2 mb-3">
    <div class="col-4"><div class="q-tile"><div class="v" style="color:{{ $overdue ? '#dc3545' : 'var(--text)' }}">{{ $overdue }}</div><div class="l">Overdue</div></div></div>
    <div class="col-4"><div class="q-tile"><div class="v" style="color:{{ $dueToday ? '#f59e0b' : 'var(--text)' }}">{{ $dueToday }}</div><div class="l">Due today</div></div></div>
    <div class="col-4"><div class="q-tile"><div class="v" style="color:var(--primary)">{{ $items->count() }}</div><div class="l">Total waiting</div></div></div>
</div>

<div class="card section-card">
    <div class="card-header py-2 d-flex gap-2 flex-wrap align-items-center">
        <input type="text" id="qSearch" class="form-control form-control-sm" style="max-width:220px" placeholder="Search items…">
        @if($queueFlows->count() > 1)
            <select id="qFlowFilter" class="form-select form-select-sm" style="max-width:200px">
                <option value="">All workflows</option>
                @foreach($queueFlows as $f)<option value="{{ $f['id'] }}">{{ $f['name'] }}</option>@endforeach
            </select>
        @endif
    </div>
    <div class="card-body p-0" id="qList">
        @forelse($items as $item)
            <div class="q-row d-flex align-items-center gap-3 p-3" style="border-bottom:1px solid var(--border)"
                data-title="{{ Str::lower($item->title) }}" data-flow="{{ $item->flow_id }}">
                <span class="spill {{ $prioSpill($item->priority) }}" style="font-size:.6rem">{{ $item->priority }}</span>
                <div class="flex-grow-1 min-w-0">
                    <a href="{{ route('flow-items.show', $item) }}" class="fw-semibold small text-decoration-none" style="color:var(--text)">{{ $item->title }}</a>
                    <div style="font-size:.72rem;color:var(--text3)">
                        {{ $item->flow->name ?? '—' }} · {{ $item->currentStage->name ?? '—' }}
                        @if($item->isOverdue())
                            · <span style="color:#dc3545;font-weight:600"><i class="bi bi-exclamation-circle"></i> Overdue {{ $item->due_date->format('d M') }}</span>
                        @elseif($item->due_date && $item->due_date->isToday())
                            · <span style="color:#f59e0b;font-weight:600">Due today</span>
                        @elseif($item->due_date)
                            · Due {{ $item->due_date->format('d M') }}
                        @endif
                        @if($item->assigned_to === auth()->id())
                            · <span style="color:var(--primary);font-weight:600">yours</span>
                        @else
                            · <span style="color:var(--text3)">unclaimed</span>
                        @endif
                    </div>
                </div>
                <a href="{{ route('flow-items.show', $item) }}" class="btn btn-sm btn-outline-secondary py-1 px-2" style="font-size:.72rem">Open</a>
                @if($item->assigned_to === auth()->id())
                    <button class="btn btn-sm btn-primary py-1 px-2 item-advance" data-id="{{ $item->id }}" data-title="{{ e($item->title) }}" style="font-size:.72rem;white-space:nowrap"><i class="bi bi-arrow-right-circle me-1"></i>Send</button>
                @else
                    <button class="btn btn-sm btn-outline-primary py-1 px-2 item-claim" data-id="{{ $item->id }}" style="font-size:.72rem;white-space:nowrap"><i class="bi bi-hand-index-thumb me-1"></i>Claim</button>
                @endif
            </div>
        @empty
            <div class="text-center py-5" style="color:var(--text3)">
                <i class="bi bi-check2-circle" style="font-size:2.4rem"></i>
                <div class="mt-2" style="font-size:.9rem">Your queue is clear — nothing waiting on you.</div>
            </div>
        @endforelse
        <div class="text-center py-4 small d-none" id="qNoMatch" style="color:var(--text3)">No items match your search.</div>
    </div>
</div>

@if($startable->isNotEmpty())
    <div class="modal fade" id="newItemModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2 px-3"><h6 class="modal-title fw-bold">New Item</h6><button class="btn-close btn-sm" data-bs-dismiss="modal"></button></div>
                <div class="modal-body px-3 py-3">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Workflow</label>
                        <select id="niFlow" class="form-select form-select-sm">
                            @foreach($startable as $f)<option value="{{ $f['id'] }}">{{ $f['name'] }}</option>@endforeach
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label fw-semibold small">Title</label><input type="text" id="niTitle" class="form-control form-control-sm" maxlength="200"></div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Priority</label>
                            <select id="niPriority" class="form-select form-select-sm">
                                @foreach(\App\Models\FlowItem::$priorities as $p)<option value="{{ $p }}" @selected($p === 'Normal')>{{ $p }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Due date</label>
                            <input type="date" id="niDue" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div><label class="form-label fw-semibold small">Details <span style="color:var(--text3)">(optional)</span></label><textarea id="niDesc" class="form-control form-control-sm" rows="3" maxlength="5000"></textarea></div>
                </div>
                <div class="modal-footer py-2 px-3">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-sm btn-primary" id="niSave"><i class="bi bi-send me-1"></i>Create</button>
                </div>
            </div>
        </div>
    </div>
@endif
@endsection

@push('scripts')
<script>
    const fail = r => Swal.fire('Error', r.responseJSON?.message || 'Something went wrong', 'error');

    function filterQueue() {
        const q = ($('#qSearch').val() || '').toLowerCase();
        const flow = $('#qFlowFilter').val() || '';
        let shown = 0;
        $('.q-row').each(function () {
            const ok = $(this).data('title').indexOf(q) !== -1 && (!flow || String($(this).data('flow')) === flow);
            $(this).toggleClass('hide', !ok);
            if (ok) shown++;
        });
        $('#qNoMatch').toggleClass('d-none', shown !== 0 || $('.q-row').length === 0);
    }
    $('#qSearch').on('input', filterQueue);
    $('#qFlowFilter').on('change', filterQueue);

    $(document).on('click', '.item-claim', function () {
        $.post('/flow-items/' + $(this).data('id') + '/claim')
            .done(() => location.reload())
            .fail(fail);
    });

    $(document).on('click', '.item-advance', function () {
        const id = $(this).data('id');
        Swal.fire({ title: 'Send to next stage?', text: $(this).data('title'), input: 'textarea', inputPlaceholder: 'Optional note…', showCancelButton: true, confirmButtonText: 'Send forward' })
            .then(res => {
                if (!res.isConfirmed) return;
                $.post('/flow-items/' + id + '/advance', { note: res.value || '' })
                    .done(() => { Swal.fire({ icon: 'success', title: 'Sent forward', timer: 1000, showConfirmButton: false }).then(() => location.reload()); })
                    .fail(fail);
            });
    });

    @if($startable->isNotEmpty())
    $('#newItemBtn').on('click', function () {
        $('#niTitle').val(''); $('#niDesc').val(''); $('#niPriority').val('Normal'); $('#niDue').val('');
        new bootstrap.Modal('#newItemModal').show();
    });
    $('#niSave').on('click', function () {
        const title = $.trim($('#niTitle').val());
        if (!title) return;
        $.post('/flow-items', { flow_id: $('#niFlow').val(), title, priority: $('#niPriority').val(), due_date: $('#niDue').val() || null, description: $('#niDesc').val() })
            .done(() => { bootstrap.Modal.getInstance('#newItemModal').hide(); Swal.fire({ icon: 'success', title: 'Item created', timer: 1000, showConfirmButton: false }).then(() => location.reload()); })
            .fail(fail);
    });
    @endif
</script>
@endpush
