@extends('layouts.app')
@section('title', 'Workflow Items')

@php
    $statusSpill = fn ($s) => match ($s) {
        'Open'      => 'spill-running',
        'Completed' => 'spill-completed',
        'Cancelled' => 'spill-cancelled',
        default     => 'spill-hold',
    };
    $prioSpill = fn ($p) => match ($p) {
        'Urgent' => 'spill-cancelled',
        'High'   => 'spill-warning',
        'Normal' => 'spill-running',
        default  => 'spill-hold',
    };
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <a href="{{ route('workflows.index') }}" class="small text-decoration-none" style="color:var(--text3)"><i class="bi bi-arrow-left me-1"></i>Workflows</a>
        <h4 class="page-title mb-0 mt-1"><i class="bi bi-list-task me-2"></i>All Items</h4>
    </div>
    <form method="GET">
        <select name="flow" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:180px">
            <option value="">All workflows</option>
            @foreach($flows as $f)
                <option value="{{ $f->id }}" @selected((string) $flowId === (string) $f->id)>{{ $f->name }}</option>
            @endforeach
        </select>
    </form>
</div>

<div class="card section-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="itemsTable" class="table table-hover mb-0" style="font-size:.85rem;width:100%">
                <thead>
                    <tr><th class="ps-3">Item</th><th>Priority</th><th>Workflow</th><th>Current stage</th><th>Due</th><th>Progress</th><th>Status</th><th>Created by</th><th>Moves</th><th class="pe-3"></th></tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                        @php $total = $stageTotals[$item->flow_id] ?? 0; @endphp
                        <tr>
                            <td class="ps-3"><a href="{{ route('flow-items.show', $item) }}" class="fw-semibold text-decoration-none" style="color:var(--text)">{{ $item->title }}</a></td>
                            <td><span class="spill {{ $prioSpill($item->priority) }}" style="font-size:.58rem">{{ $item->priority }}</span></td>
                            <td><span style="color:var(--text2)">{{ $item->flow->name ?? '—' }}</span></td>
                            <td>
                                {{ $item->currentStage->name ?? ($item->status === 'Completed' ? '✓ Done' : '—') }}
                                @if($item->status === 'Open' && $item->current_stage_id && !in_array($item->current_stage_id, $assignedStageIds))
                                    <span class="spill spill-warning ms-1" style="font-size:.56rem" title="No users assigned to this stage — it can't move without an admin"><i class="bi bi-exclamation-triangle"></i> stranded</span>
                                @endif
                                @if($item->status === 'Open' && $item->currentStage)
                                    <div style="font-size:.63rem;color:var(--text3)">{{ $item->assignee ? '👤 ' . $item->assignee->name : 'unclaimed' }}</div>
                                @endif
                            </td>
                            <td>
                                @if($item->due_date)
                                    <span style="font-size:.78rem;font-weight:600;color:{{ $item->isOverdue() ? '#dc3545' : ($item->due_date->isToday() ? '#f59e0b' : 'var(--text2)') }}">{{ $item->due_date->format('d M') }}</span>
                                @else
                                    <span style="color:var(--text3)">—</span>
                                @endif
                            </td>
                            <td>
                                @if($item->currentStage && $total)
                                    <span style="font-size:.78rem">{{ $item->currentStage->position }} / {{ $total }}</span>
                                @elseif($item->status === 'Completed')
                                    <span style="font-size:.78rem;color:var(--text3)">{{ $total }} / {{ $total }}</span>
                                @else
                                    <span style="color:var(--text3)">—</span>
                                @endif
                            </td>
                            <td><span class="spill {{ $statusSpill($item->status) }}">{{ $item->status }}</span></td>
                            <td><span style="color:var(--text2)">{{ $item->creator->name ?? '—' }}</span></td>
                            <td>{{ $item->transitions_count }}</td>
                            <td class="pe-3"><a href="{{ route('flow-items.show', $item) }}" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.72rem">History</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center py-5" style="color:var(--text3)"><i class="bi bi-inbox" style="font-size:2rem"></i><div class="mt-2">No items yet.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $(function () {
        if ($('#itemsTable tbody tr').length > 1 || !$('#itemsTable td[colspan]').length) {
            $('#itemsTable').DataTable({ order: [], pageLength: 25, lengthChange: false, info: false, language: { search: '', searchPlaceholder: 'Search…' }, columnDefs: [{ orderable: false, targets: [9] }] });
        }
    });
</script>
@endpush
