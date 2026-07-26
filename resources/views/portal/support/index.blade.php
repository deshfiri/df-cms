@extends('layouts.portal')
@section('title', 'Support')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Support</h5>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newTicketModal"><i class="bi bi-plus-lg me-1"></i>New Ticket</button>
</div>

<div class="card p-0">
    <table class="table mb-0">
        <thead><tr><th>Ticket #</th><th>Subject</th><th>Category</th><th>Priority</th><th>Status</th><th>Last Reply</th></tr></thead>
        <tbody>
        @forelse($tickets as $ticket)
            @php
                $badgeClass = match($ticket->status) {
                    'Resolved', 'Closed' => 'spill-green',
                    'Waiting for Client' => 'spill-yellow',
                    default => 'spill-blue',
                };
            @endphp
            <tr style="cursor:pointer" onclick="window.location='{{ route('portal.support.show', $ticket) }}'">
                <td>{{ $ticket->ticket_number }}</td>
                <td>{{ $ticket->subject }}</td>
                <td>{{ $ticket->category }}</td>
                <td>{{ $ticket->priority }}</td>
                <td><span class="spill {{ $badgeClass }}">{{ $ticket->status }}</span></td>
                <td style="font-size:.78rem;color:var(--text3)">{{ $ticket->last_reply_at?->diffForHumans() ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center py-4" style="color:var(--text3)">No support tickets yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="modal fade" id="newTicketModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('portal.support.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="modal-header">
                    <h6 class="modal-title">New Support Ticket</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small">Category</label>
                        <select name="category" class="form-select" required>
                            @foreach(\App\Models\SupportTicket::$categories as $cat)
                                <option value="{{ $cat }}">{{ $cat }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Priority</label>
                        <select name="priority" class="form-select" required>
                            @foreach(\App\Models\SupportTicket::$priorities as $p)
                                <option value="{{ $p }}" {{ $p === 'Medium' ? 'selected' : '' }}>{{ $p }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Subject</label>
                        <input type="text" name="subject" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Message</label>
                        <textarea name="message" class="form-control" rows="4" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Attachment (optional)</label>
                        <input type="file" name="file" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">Create Ticket</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
