@extends('layouts.portal')
@section('title', 'Approvals')

@section('content')
<h5 class="mb-3">Approvals</h5>

<div class="card p-0">
    <table class="table mb-0">
        <thead><tr><th>Title</th><th>Type</th><th>Version</th><th>Deadline</th><th>Status</th><th class="text-end">Action</th></tr></thead>
        <tbody>
        @forelse($approvalRequests as $approval)
            @php
                $badgeClass = match($approval->status) {
                    'Approved' => 'spill-green',
                    'Pending' => 'spill-blue',
                    'Revision Requested' => 'spill-yellow',
                    'Rejected', 'Expired' => 'spill-red',
                    default => 'spill-gray',
                };
            @endphp
            <tr>
                <td>{{ $approval->title }}</td>
                <td>{{ $approval->approval_type }}</td>
                <td>v{{ $approval->version }}</td>
                <td style="font-size:.78rem;color:var(--text2)">{{ $approval->deadline?->format('d M Y') ?? '—' }}</td>
                <td><span class="spill {{ $badgeClass }}">{{ $approval->status }}</span></td>
                <td class="text-end">
                    <a href="{{ route('portal.approvals.show', $approval) }}" class="btn btn-sm btn-outline-secondary">Review</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center py-4" style="color:var(--text3)">No approvals waiting on you.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
