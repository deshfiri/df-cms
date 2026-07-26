@extends('layouts.portal')
@section('title', 'Dashboard')

@section('content')
<div class="mb-4">
    <h4 class="mb-0">Welcome, {{ $portalUser->name }}</h4>
    <div style="font-size:.8rem;color:var(--text2)">{{ $client->brand_name ?? $client->client_name }}</div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4 col-xl-2">
        <div class="card p-3">
            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;font-weight:600">Overall Progress</div>
            <div style="font-size:1.4rem;font-weight:700" class="mt-1">{{ $dashboard['overall_progress'] }}%</div>
        </div>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <div class="card p-3">
            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;font-weight:600">Active Services</div>
            <div style="font-size:1.4rem;font-weight:700" class="mt-1">{{ $dashboard['active_services'] }}</div>
        </div>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <div class="card p-3">
            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;font-weight:600">Pending Actions</div>
            <div style="font-size:1.4rem;font-weight:700" class="mt-1">{{ $dashboard['pending_actions'] }}</div>
        </div>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <div class="card p-3">
            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;font-weight:600">Pending Approvals</div>
            <div style="font-size:1.4rem;font-weight:700" class="mt-1">{{ $dashboard['pending_approvals'] }}</div>
        </div>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <div class="card p-3">
            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;font-weight:600">Due Payment</div>
            <div style="font-size:1.4rem;font-weight:700" class="mt-1">৳{{ number_format($dashboard['due_amount'], 2) }}</div>
        </div>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <div class="card p-3">
            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;font-weight:600">Completed Stages</div>
            <div style="font-size:1.4rem;font-weight:700" class="mt-1">{{ $dashboard['completed_stages'] }}/{{ $dashboard['total_stages'] }}</div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card p-3 mb-3">
            <div class="fw-semibold mb-2" style="font-size:.85rem">Current Stage</div>
            @if($dashboard['current_stage'])
                <div style="font-size:.95rem;font-weight:600">{{ $dashboard['current_stage']['name'] }}</div>
                @if($dashboard['current_stage']['description'])
                    <div style="font-size:.78rem;color:var(--text2)" class="mt-1">{{ $dashboard['current_stage']['description'] }}</div>
                @endif
                @if($dashboard['current_stage']['next_step'])
                    <div style="font-size:.75rem;color:var(--text3)" class="mt-2"><i class="bi bi-arrow-right-short"></i>Next: {{ $dashboard['current_stage']['next_step'] }}</div>
                @endif
            @else
                <div style="font-size:.8rem;color:var(--text3)">No active stage right now.</div>
            @endif
        </div>

        <div class="card p-3">
            <div class="fw-semibold mb-2" style="font-size:.85rem">Latest Project Updates</div>
            @forelse($dashboard['latest_updates'] as $update)
                <div class="pb-2 mb-2 border-bottom" style="font-size:.8rem">
                    <div class="fw-semibold">{{ $update->title }}</div>
                    <div style="color:var(--text3);font-size:.72rem">{{ $update->created_at->diffForHumans() }}</div>
                </div>
            @empty
                <div style="font-size:.78rem;color:var(--text3)">No updates yet.</div>
            @endforelse
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card p-3 mb-3">
            <div class="fw-semibold mb-2" style="font-size:.85rem">Assigned Manager</div>
            @if($dashboard['manager'])
                <div style="font-size:.85rem;font-weight:600">{{ $dashboard['manager']->name }}</div>
                @if($dashboard['manager']->designation)
                    <div style="font-size:.75rem;color:var(--text2)">{{ $dashboard['manager']->designation }}</div>
                @endif
                @if($dashboard['manager']->email)
                    <div style="font-size:.75rem;color:var(--text3)" class="mt-1"><i class="bi bi-envelope me-1"></i>{{ $dashboard['manager']->email }}</div>
                @endif
                @if($dashboard['manager']->phone)
                    <div style="font-size:.75rem;color:var(--text3)"><i class="bi bi-telephone me-1"></i>{{ $dashboard['manager']->phone }}</div>
                @endif
            @else
                <div style="font-size:.78rem;color:var(--text3)">No manager assigned yet.</div>
            @endif
        </div>

        <div class="card p-3">
            <div class="fw-semibold mb-2" style="font-size:.85rem">Recent Documents</div>
            @forelse($dashboard['recent_documents'] as $doc)
                <div class="d-flex justify-content-between pb-2 mb-2 border-bottom" style="font-size:.8rem">
                    <span>{{ $doc->title }}</span>
                    <span style="color:var(--text3);font-size:.72rem">{{ $doc->created_at->format('d M') }}</span>
                </div>
            @empty
                <div style="font-size:.78rem;color:var(--text3)">No documents shared yet.</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
