@extends('layouts.app')
@section('title', $ticket->ticket_number)

@section('content')
<h4 class="page-title mb-3">{{ $ticket->subject }} <small class="text-muted">{{ $ticket->ticket_number }}</small></h4>

<div class="card p-3 mb-3">
    <div style="font-size:.8rem;color:var(--text3)">{{ $ticket->client->client_name }} &middot; {{ $ticket->category }} &middot; {{ $ticket->priority }}</div>
    <p class="mt-2">{{ $ticket->message }}</p>
</div>

<div class="card p-3 mb-3">
    <h6>Conversation</h6>
    @foreach($ticket->replies as $reply)
        <div class="pb-2 mb-2 border-bottom" style="font-size:.85rem; {{ $reply->is_internal_note ? 'background:#fffbeb' : '' }}">
            <strong>{{ $reply->author_type === 'staff' ? ($reply->author->name ?? 'Staff') : ($ticket->client->client_name . ' (Client)') }}</strong>
            @if($reply->is_internal_note)<span class="badge bg-warning text-dark">Internal Note</span>@endif
            <div class="text-muted" style="font-size:.72rem">{{ $reply->created_at->format('d M Y, h:i A') }}</div>
            <div>{{ $reply->message }}</div>
        </div>
    @endforeach
</div>

<div class="card p-3">
    <h6>Reply</h6>
    <textarea id="replyMessage" class="form-control mb-2" rows="3"></textarea>
    <div class="form-check mb-2">
        <input type="checkbox" class="form-check-input" id="internalNote">
        <label class="form-check-label" for="internalNote">Internal note (not visible to client)</label>
    </div>
    <button class="btn btn-primary btn-sm" id="sendReply">Send</button>
</div>
@endsection

@push('scripts')
<script>
$('#sendReply').on('click', function () {
    $.post('{{ route("support-tickets.reply", $ticket) }}', {
        message: $('#replyMessage').val(),
        is_internal_note: $('#internalNote').is(':checked') ? 1 : 0,
    }).done(function () { location.reload(); });
});
</script>
@endpush
