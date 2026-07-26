@extends('layouts.portal')
@section('title', 'My Journey')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="mb-0">My Journey</h5>
    <div style="font-size:.8rem;color:var(--text2)">{{ $overallProgress }}% complete</div>
</div>

<div class="progress mb-4" style="height:6px;background:var(--surface2)">
    <div class="progress-bar" style="width:{{ $overallProgress }}%;background:var(--primary)"></div>
</div>

<div class="card p-0">
    @forelse($stages as $stage)
        @php
            $badgeClass = match($stage['status']) {
                'Approved' => 'spill-green',
                'Submitted', 'In Progress' => 'spill-blue',
                'Need Revision' => 'spill-yellow',
                'Rejected' => 'spill-red',
                default => 'spill-gray',
            };
        @endphp
        <div class="p-3 {{ !$loop->last ? 'border-bottom' : '' }}" style="{{ $stage['locked'] ? 'opacity:.55' : '' }}">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="fw-semibold" style="font-size:.88rem">
                        {{ $stage['name'] }}
                        @if($stage['current'])<i class="bi bi-arrow-right-circle-fill ms-1" style="color:var(--primary)"></i>@endif
                    </div>
                    @if($stage['description'])
                        <div style="font-size:.78rem;color:var(--text2)" class="mt-1">{{ $stage['description'] }}</div>
                    @endif
                </div>
                <span class="spill {{ $badgeClass }}">{{ $stage['locked'] ? 'Locked' : $stage['status'] }}</span>
            </div>

            @if($stage['client_update'])
                <div class="mt-2 p-2" style="background:var(--surface2);border-radius:8px;font-size:.78rem">
                    {{ $stage['client_update'] }}
                </div>
            @endif

            <div class="d-flex flex-wrap gap-3 mt-2" style="font-size:.72rem;color:var(--text3)">
                @if($stage['started_at'])<span><i class="bi bi-play-circle me-1"></i>Started {{ $stage['started_at']->format('d M Y') }}</span>@endif
                @if($stage['completed_at'])<span><i class="bi bi-check-circle me-1"></i>Completed {{ $stage['completed_at']->format('d M Y') }}</span>@endif
                @if($stage['overdue'])<span style="color:var(--c-yellow)"><i class="bi bi-exclamation-triangle me-1"></i>Delayed</span>@endif
            </div>

            @if($stage['next_step'])
                <div class="mt-2" style="font-size:.75rem;color:var(--text2)"><i class="bi bi-arrow-right-short"></i>Next: {{ $stage['next_step'] }}</div>
            @endif

            <div class="d-flex gap-2 mt-2">
                @if($stage['client_action_required'])
                    <span class="spill spill-yellow"><i class="bi bi-flag"></i>Action Needed</span>
                @endif
                @if($stage['client_approval_required'])
                    <span class="spill spill-blue"><i class="bi bi-patch-check"></i>Approval Needed</span>
                @endif
            </div>
        </div>
    @empty
        <div class="p-4 text-center" style="color:var(--text3);font-size:.85rem">No journey stages to show yet.</div>
    @endforelse
</div>
@endsection
