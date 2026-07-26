@extends('layouts.portal')
@section('title', 'Project Updates')

@section('content')
<h5 class="mb-3">Project Updates</h5>

<div class="d-flex gap-2 mb-3 flex-wrap">
    <a href="{{ route('portal.updates.index') }}" class="btn btn-sm {{ !request()->filled('status') ? 'btn-primary' : 'btn-outline-secondary' }}">All Updates</a>
    <a href="{{ route('portal.updates.index', ['status' => 'completed']) }}" class="btn btn-sm {{ request('status') === 'completed' ? 'btn-primary' : 'btn-outline-secondary' }}">Completed Work</a>
    <a href="{{ route('portal.updates.index', ['status' => 'pending']) }}" class="btn btn-sm {{ request('status') === 'pending' ? 'btn-primary' : 'btn-outline-secondary' }}">Pending Work</a>
</div>

@forelse($updates as $update)
    <div class="card p-3 mb-3">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="fw-semibold" style="font-size:.88rem">{{ $update->title }}</div>
                <div style="font-size:.72rem;color:var(--text3)">
                    {{ $update->postedBy->name ?? 'Team' }} &middot; {{ $update->created_at->format('d M Y, h:i A') }}
                    @if($update->department) &middot; {{ $update->department }} @endif
                </div>
            </div>
            @if(!is_null($update->progress_percent))
                <span class="spill spill-blue">{{ $update->progress_percent }}%</span>
            @endif
        </div>
        <div class="mt-2" style="font-size:.82rem;color:var(--text2)">{{ $update->description }}</div>

        @if($update->path)
            <div class="mt-2">
                <a href="#" class="btn btn-sm btn-outline-secondary"><i class="bi bi-paperclip me-1"></i>Attachment</a>
            </div>
        @endif
        @if($update->video_url)
            <div class="mt-2"><a href="{{ $update->video_url }}" target="_blank" style="font-size:.78rem;color:var(--primary)"><i class="bi bi-play-circle me-1"></i>Watch Video</a></div>
        @endif
        @if($update->external_link)
            <div class="mt-1"><a href="{{ $update->external_link }}" target="_blank" style="font-size:.78rem;color:var(--primary)"><i class="bi bi-box-arrow-up-right me-1"></i>View Link</a></div>
        @endif
        @if($update->next_action)
            <div class="mt-2" style="font-size:.75rem;color:var(--text3)"><i class="bi bi-arrow-right-short"></i>Next: {{ $update->next_action }}</div>
        @endif
    </div>
@empty
    <div class="card p-4 text-center" style="color:var(--text3);font-size:.85rem">No updates yet.</div>
@endforelse

{{ $updates->links() }}
@endsection
