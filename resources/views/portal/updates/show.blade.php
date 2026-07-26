@extends('layouts.portal')
@section('title', $update->title)

@section('content')
<a href="{{ route('portal.updates.index') }}" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i>Back</a>

<div class="card p-4">
    <h5>{{ $update->title }}</h5>
    <div style="font-size:.75rem;color:var(--text3)" class="mb-3">
        {{ $update->postedBy->name ?? 'Team' }} &middot; {{ $update->created_at->format('d M Y, h:i A') }}
    </div>
    <p style="font-size:.88rem;color:var(--text2)">{{ $update->description }}</p>

    @if($update->video_url)
        <p><a href="{{ $update->video_url }}" target="_blank" style="color:var(--primary)"><i class="bi bi-play-circle me-1"></i>Watch Video</a></p>
    @endif
    @if($update->external_link)
        <p><a href="{{ $update->external_link }}" target="_blank" style="color:var(--primary)"><i class="bi bi-box-arrow-up-right me-1"></i>View Link</a></p>
    @endif
    @if($update->next_action)
        <p style="color:var(--text3)"><i class="bi bi-arrow-right-short"></i>Next: {{ $update->next_action }}</p>
    @endif
</div>
@endsection
