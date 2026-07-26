@extends('layouts.portal')
@section('title', $approvalRequest->title)

@section('content')
<a href="{{ route('portal.approvals.index') }}" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i>Back</a>

@if(session('success'))
<div class="alert alert-success" style="font-size:.85rem">{{ session('success') }}</div>
@endif
@if($errors->any())
<div class="alert alert-danger" style="font-size:.85rem">{{ $errors->first() }}</div>
@endif

<div class="card p-4 mb-3">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h5 class="mb-1">{{ $approvalRequest->title }}</h5>
            <div style="font-size:.75rem;color:var(--text3)">{{ $approvalRequest->approval_type }} &middot; Version {{ $approvalRequest->version }}</div>
        </div>
        <span class="spill spill-blue">{{ $approvalRequest->status }}</span>
    </div>
    @if($approvalRequest->description)
        <p class="mt-3" style="font-size:.85rem;color:var(--text2)">{{ $approvalRequest->description }}</p>
    @endif
    @if($approvalRequest->external_preview_url)
        <a href="{{ $approvalRequest->external_preview_url }}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye me-1"></i>View Preview</a>
    @endif
    @if($approvalRequest->deadline)
        <div class="mt-2" style="font-size:.75rem;color:var(--text3)">Deadline: {{ $approvalRequest->deadline->format('d M Y') }}</div>
    @endif
</div>

@if($approvalRequest->responses->isNotEmpty())
<div class="card p-4 mb-3">
    <div class="fw-semibold mb-2" style="font-size:.85rem">Approval History</div>
    @foreach($approvalRequest->responses as $response)
        <div class="pb-2 mb-2 border-bottom" style="font-size:.82rem">
            <div class="d-flex justify-content-between">
                <span class="fw-semibold">{{ $response->response }}</span>
                <span style="color:var(--text3);font-size:.72rem">v{{ $response->version }} &middot; {{ $response->created_at->format('d M Y') }}</span>
            </div>
            @if($response->comment)<div style="color:var(--text2)">{{ $response->comment }}</div>@endif
        </div>
    @endforeach
</div>
@endif

@if(in_array($approvalRequest->status, ['Pending', 'Revision Requested']))
<div class="card p-4">
    <div class="fw-semibold mb-3" style="font-size:.85rem">Your Response</div>
    <form method="POST" action="{{ route('portal.approvals.respond', $approvalRequest) }}" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label class="form-label small">Decision</label>
            <select name="response" class="form-select" required>
                <option value="Approved">Approve</option>
                <option value="Revision Requested">Request Revision</option>
                @if($approvalRequest->allow_reject)<option value="Rejected">Reject</option>@endif
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label small">Comment</label>
            <textarea name="comment" class="form-control" rows="3" placeholder="Required if requesting revision"></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label small">Attachment (optional)</label>
            <input type="file" name="file" class="form-control">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Submit Response</button>
    </form>
</div>
@endif
@endsection
