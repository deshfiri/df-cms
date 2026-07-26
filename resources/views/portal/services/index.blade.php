@extends('layouts.portal')
@section('title', 'My Services')

@section('content')
<h5 class="mb-3">My Services</h5>

<div class="row g-3">
    @forelse($services as $service)
        @php
            $badgeClass = match($service['status']) {
                'Completed' => 'spill-green',
                'Active' => 'spill-blue',
                default => 'spill-gray',
            };
        @endphp
        <div class="col-md-6 col-xl-4">
            <div class="card p-3 h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="fw-semibold" style="font-size:.9rem">{{ $service['department'] }}</div>
                    <span class="spill {{ $badgeClass }}">{{ $service['status'] }}</span>
                </div>
                <div class="progress mb-2" style="height:5px;background:var(--surface2)">
                    <div class="progress-bar" style="width:{{ $service['progress'] }}%;background:var(--primary)"></div>
                </div>
                <div style="font-size:.72rem;color:var(--text3)" class="mb-2">{{ $service['progress'] }}% complete &middot; {{ $service['stage_count'] }} stage(s)</div>

                @if($service['current_stage'])
                    <div style="font-size:.78rem;color:var(--text2)"><i class="bi bi-signpost-split me-1"></i>{{ $service['current_stage'] }}</div>
                @endif
                @if($service['next_step'])
                    <div style="font-size:.75rem;color:var(--text3)" class="mt-1"><i class="bi bi-arrow-right-short"></i>{{ $service['next_step'] }}</div>
                @endif
                @if($service['last_update'])
                    <div style="font-size:.7rem;color:var(--text3)" class="mt-2">Last update: {{ $service['last_update']->format('d M Y') }}</div>
                @endif
            </div>
        </div>
    @empty
        <div class="col-12">
            <div class="card p-4 text-center" style="color:var(--text3);font-size:.85rem">No services to show yet.</div>
        </div>
    @endforelse
</div>
@endsection
