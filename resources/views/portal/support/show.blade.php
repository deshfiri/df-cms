@extends('layouts.portal')
@section('title', $ticket->subject)

@section('content')
<a href="{{ route('portal.support.index') }}" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i>Back</a>

@if(session('success'))
<div class="alert alert-success" style="font-size:.85rem">{{ session('success') }}</div>
@endif

<div class="card p-4 mb-3">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h5 class="mb-1">{{ $ticket->subject }}</h5>
            <div style="font-size:.75rem;color:var(--text3)">{{ $ticket->ticket_number }} &middot; {{ $ticket->category }} &middot; {{ $ticket->priority }} priority</div>
        </div>
        <span class="spill spill-blue">{{ $ticket->status }}</span>
    </div>
    <p class="mt-3" style="font-size:.85rem;color:var(--text2)">{{ $ticket->message }}</p>
</div>

<div class="card p-4 mb-3">
    <div class="fw-semibold mb-3" style="font-size:.85rem">Conversation</div>
    @forelse($ticket->replies as $reply)
        <div class="pb-2 mb-2 border-bottom" style="font-size:.82rem">
            <div class="d-flex justify-content-between">
                <span class="fw-semibold">{{ $reply->author_type === 'staff' ? ($reply->author->name ?? 'Support Team') : 'You' }}</span>
                <span style="color:var(--text3);font-size:.72rem">{{ $reply->created_at->format('d M Y, h:i A') }}</span>
            </div>
            <div>{{ $reply->message }}</div>
        </div>
    @empty
        <div style="font-size:.8rem;color:var(--text3)">No replies yet.</div>
    @endforelse
</div>

@if(!$ticket->isClosed())
<div class="card p-4">
    <div class="fw-semibold mb-2" style="font-size:.85rem">Reply</div>
    <form method="POST" action="{{ route('portal.support.reply', $ticket) }}" enctype="multipart/form-data">
        @csrf
        <textarea name="message" class="form-control mb-2" rows="3" required></textarea>
        <input type="file" name="file" class="form-control mb-2">
        <button type="submit" class="btn btn-primary btn-sm">Send Reply</button>
    </form>
</div>
@endif
@endsection
