@extends('layouts.portal')
@section('title', 'Pending Actions')

@section('content')
<h5 class="mb-3">Pending Actions</h5>

<div class="card p-0">
    <table class="table mb-0">
        <thead><tr><th>Title</th><th>Priority</th><th>Due Date</th><th>Status</th><th class="text-end">Action</th></tr></thead>
        <tbody>
        @forelse($actionRequests as $action)
            @php
                $badgeClass = match($action->status) {
                    'Approved', 'Completed' => 'spill-green',
                    'Submitted', 'Under Review' => 'spill-blue',
                    'Need Revision' => 'spill-yellow',
                    'Rejected' => 'spill-red',
                    default => 'spill-gray',
                };
            @endphp
            <tr>
                <td>{{ $action->title }}</td>
                <td>{{ $action->priority }}</td>
                <td style="font-size:.78rem;color:var(--text2)">{{ $action->due_date?->format('d M Y') ?? '—' }}</td>
                <td><span class="spill {{ $badgeClass }}">{{ $action->status }}</span></td>
                <td class="text-end">
                    <a href="{{ route('portal.actions.show', $action) }}" class="btn btn-sm btn-outline-secondary">View</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center py-4" style="color:var(--text3)">No actions requested from you right now.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
