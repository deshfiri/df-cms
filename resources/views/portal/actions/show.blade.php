@extends('layouts.portal')
@section('title', $actionRequest->title)

@section('content')
<a href="{{ route('portal.actions.index') }}" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i>Back</a>

@if(session('success'))
<div class="alert alert-success" style="font-size:.85rem">{{ session('success') }}</div>
@endif

<div class="card p-4 mb-3">
    <h5>{{ $actionRequest->title }}</h5>
    <div style="font-size:.75rem;color:var(--text3)" class="mb-3">
        Requested {{ $actionRequest->created_at->format('d M Y') }}
        @if($actionRequest->due_date) &middot; Due {{ $actionRequest->due_date->format('d M Y') }} @endif
        &middot; Priority: {{ $actionRequest->priority }}
    </div>
    <p style="font-size:.88rem;color:var(--text2)">{{ $actionRequest->description }}</p>

    @if($actionRequest->team_feedback)
        <div class="p-3 mt-2" style="background:var(--surface2);border-radius:8px">
            <div style="font-size:.7rem;color:var(--text3);text-transform:uppercase" class="mb-1">Team Feedback</div>
            <div style="font-size:.82rem">{{ $actionRequest->team_feedback }}</div>
        </div>
    @endif
</div>

@if($actionRequest->submissions->isNotEmpty())
<div class="card p-4 mb-3">
    <div class="fw-semibold mb-2" style="font-size:.85rem">Previous Submissions</div>
    @foreach($actionRequest->submissions as $submission)
        <div class="pb-2 mb-2 border-bottom" style="font-size:.82rem">
            <div>{{ $submission->response_text ?? '(No text response)' }}</div>
            @if($submission->has_attachment)
                <div style="font-size:.72rem;color:var(--text3)"><i class="bi bi-paperclip me-1"></i>{{ $submission->original_name }}</div>
            @endif
            <div style="font-size:.7rem;color:var(--text3)">{{ $submission->created_at->format('d M Y, h:i A') }}</div>
        </div>
    @endforeach
</div>
@endif

@if(in_array($actionRequest->status, ['Pending', 'Need Revision']))
<div class="card p-4">
    <div class="fw-semibold mb-3" style="font-size:.85rem">Your Response</div>
    <form method="POST" action="{{ route('portal.actions.submit', $actionRequest) }}" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label class="form-label small">Response</label>
            <textarea name="response_text" class="form-control" rows="4"></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label small">Attachment @if($actionRequest->required_attachment)<span class="text-danger">*</span>@endif</label>
            <input type="file" name="file" class="form-control" {{ $actionRequest->required_attachment ? 'required' : '' }}>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Submit Response</button>
    </form>
</div>
@endif
@endsection
