@extends('layouts.app')
@section('title', 'My Workflow History')

@php
    $statusSpill = fn ($s) => match ($s) {
        'Open'      => 'spill-running',
        'Completed' => 'spill-completed',
        'Cancelled' => 'spill-cancelled',
        default     => 'spill-hold',
    };
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-clock-history me-2"></i>My History</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Items you started or worked on — any status.</div>
    </div>
    <a href="{{ route('flow.queue') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-inboxes me-1"></i>My Queue</a>
</div>

<div class="card section-card">
    <div class="card-header py-2">
        <input type="text" id="hSearch" class="form-control form-control-sm" style="max-width:240px" placeholder="Search items…">
    </div>
    <div class="card-body p-0">
        @forelse($items as $item)
            <a href="{{ route('flow-items.show', $item) }}" class="h-row d-flex align-items-center gap-3 p-3 text-decoration-none" style="border-bottom:1px solid var(--border)" data-title="{{ Str::lower($item->title) }}">
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold small" style="color:var(--text)">{{ $item->title }}</div>
                    <div style="font-size:.72rem;color:var(--text3)">
                        {{ $item->flow->name ?? '—' }}
                        @if($item->status === 'Open' && $item->currentStage) · at {{ $item->currentStage->name }} @endif
                        · {{ $item->updated_at->diffForHumans() }}
                    </div>
                </div>
                <span class="spill {{ $statusSpill($item->status) }}">{{ $item->status }}</span>
            </a>
        @empty
            <div class="text-center py-5" style="color:var(--text3)">
                <i class="bi bi-clock-history" style="font-size:2.2rem"></i>
                <div class="mt-2" style="font-size:.9rem">Nothing yet — items you start or move will appear here.</div>
            </div>
        @endforelse
        <div class="text-center py-4 small d-none" id="hNoMatch" style="color:var(--text3)">No items match your search.</div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $('#hSearch').on('input', function () {
        const q = ($(this).val() || '').toLowerCase();
        let shown = 0;
        $('.h-row').each(function () {
            const ok = $(this).data('title').indexOf(q) !== -1;
            $(this).toggleClass('d-none', !ok);
            if (ok) shown++;
        });
        $('#hNoMatch').toggleClass('d-none', shown !== 0 || $('.h-row').length === 0);
    });
</script>
@endpush
