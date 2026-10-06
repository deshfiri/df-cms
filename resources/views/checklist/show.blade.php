@extends('layouts.app')
@section('title', $brand->name . ' — Content Checklist')

@php
    $categoryLabels = [
        'raw_content' => 'Raw Content',
        'advertising_content' => 'Advertising Content',
        'poster' => 'Poster',
    ];
    $statusLabel = [
        'pending' => 'Pending', 'in_progress' => 'In progress', 'available' => 'Submitted',
        'collected' => 'Collected by SMM', 'published' => 'Published', 'needs_revision' => 'Needs revision',
    ];
    $statusSpill = [
        'pending' => 'spill-hold', 'in_progress' => 'spill-hold', 'available' => 'spill-warning',
        'collected' => 'spill-warning', 'published' => 'spill-completed', 'needs_revision' => 'spill-cancelled',
    ];
    $reviewLabel = [
        'reviewed' => 'Reviewed', 'revision_requested' => 'Revision requested', 'awaiting_review' => 'Awaiting review',
    ];
    $reviewSpill = [
        'reviewed' => 'spill-completed', 'revision_requested' => 'spill-cancelled', 'awaiting_review' => 'spill-hold',
    ];
    // Stored/computed in UTC (config('app.timezone') is 'UTC', unchanged
    // here) — this page's audience is Bangladesh-local, so every
    // user-facing timestamp on it converts explicitly to Asia/Dhaka via
    // Carbon's timezone-aware setTimezone(), never by adding hours.
    $dhaka = fn ($at) => $at ? $at->copy()->setTimezone('Asia/Dhaka')->format('d M Y, h:i A') : null;
    // Cheap UI hint only — see StoredFileResponse::looksPreviewable()'s own
    // docblock for why this never decides what's actually streamed.
    $looksPreviewable = fn ($path) => \App\Services\Storage\StoredFileResponse::looksPreviewable($path);
@endphp

@push('styles')
<style>
    .cl-item-card { border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: .75rem; }
    .cl-item-head { padding: .75rem 1rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
    .cl-item-title { font-weight: 600; }
    .cl-item-meta { font-size: .75rem; color: var(--text3); }
    .cl-history { border-top: 1px solid var(--border); padding: .75rem 1rem; font-size: .82rem; }
    .cl-version-row { padding: .5rem 0; border-bottom: 1px dashed var(--border); }
    .cl-version-row:last-child { border-bottom: none; }
    .cl-empty-cat { text-align: center; padding: 2rem 1rem; color: var(--text3); border: 1px dashed var(--border); border-radius: var(--radius); }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-list-check me-2"></i>{{ $brand->name }} — Content Checklist</h4>
        <div class="cl-item-meta mt-1">Read-only — a live view of this brand's existing content workflow. Every action still happens on its own panel.</div>
    </div>
    <div>
        @if(!$checklist)
            <span class="spill spill-hold">Not eligible</span>
        @elseif($checklist->isOnHold())
            <span class="spill spill-hold">On hold</span>
        @else
            <span class="spill spill-completed">Active</span>
        @endif
    </div>
</div>

@if($checklist?->isOnHold())
    <div class="card section-card mb-3">
        <div class="card-body small" style="color:var(--text2)">
            <i class="bi bi-pause-circle me-1"></i><strong>On hold:</strong> {{ $checklist->on_hold_reason ?: 'Awaiting resolution.' }}
            — existing content below stays fully visible; new work is paused until a Manager clears it.
        </div>
    </div>
@elseif(!$checklist)
    <div class="card section-card mb-3">
        <div class="card-body small" style="color:var(--text3)">This brand has no checklist yet — nothing has made it eligible.</div>
    </div>
@endif

<div class="row g-3 mb-4">
    @foreach($categoryLabels as $catKey => $catLabel)
        <div class="col-md-4">
            <div class="card section-card">
                <div class="card-body text-center">
                    <div style="font-size:1.6rem;font-weight:700">{{ $counts[$catKey] ?? 0 }}</div>
                    <div class="small" style="color:var(--text3)">{{ $catLabel }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

@foreach($categoryLabels as $catKey => $catLabel)
    <div class="mb-4">
        <h6 class="fw-bold mb-2"><i class="bi bi-folder2 me-1"></i>{{ $catLabel }} ({{ ($categories[$catKey] ?? collect())->count() }})</h6>

        @forelse(($categories[$catKey] ?? collect()) as $row)
            @php($item = $row['item'])
            @php($latest = $row['latest'])
            {{-- A version suffix only earns its place on the latest-submission
                 actions once there's more than one version to tell apart —
                 see $row['history']->count(), the actual submission count
                 from the projection, never inferred from status. History's
                 own rows always show their version label up front (the
                 <strong>V1</strong>/<strong>V2</strong> tag below), so its
                 action buttons never repeat it in their own text. --}}
            @php($versionSuffix = $latest && $row['history']->count() > 1 ? ' '.$latest['version_label'] : '')
            <div class="cl-item-card">
                <div class="cl-item-head">
                    <div>
                        <div class="cl-item-title">{{ $item->title }}</div>
                        <div class="cl-item-meta">
                            Created by {{ $item->createdBy?->name ?? '—' }}
                            @if($latest)
                                · Latest {{ $latest['version_label'] }} submitted by {{ $latest['submission']->submittedBy?->name ?? '—' }}
                                on {{ $dhaka($latest['submission']->created_at) }}
                            @endif
                        </div>
                        {{-- History already carries this per version once there's
                             more than one — showing it here too would just repeat
                             the same fact twice. A single-version item never gets
                             a History section at all, so this is its only place to
                             show who published it, when, and the post link. Reads
                             straight from $latest['publication'], the exact same
                             submission-scoped lookup History itself uses — never
                             the item's newest publication across versions. --}}
                        @if($latest && $row['history']->count() <= 1 && $latest['publication'])
                            <div class="cl-item-meta">
                                Published by {{ $latest['publication']->publishedBy?->name ?? '—' }}
                                on {{ $dhaka($latest['publication']->published_at) }}
                                @if($latest['publication']->facebook_post_url)
                                    · <a href="{{ $latest['publication']->facebook_post_url }}" target="_blank" rel="noopener noreferrer">View post</a>
                                @endif
                            </div>
                        @endif
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="spill {{ $statusSpill[$item->status] ?? 'spill-hold' }}">{{ $statusLabel[$item->status] ?? $item->status }}</span>
                        @if($latest && $latest['review_state'])
                            <span class="spill {{ $reviewSpill[$latest['review_state']] }}">{{ $reviewLabel[$latest['review_state']] }}</span>
                        @endif
                        @if($latest && $latest['submission']->file_path)
                            @if($looksPreviewable($latest['submission']->file_path))
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('marketing.content-items.submissions.preview', [$brand, $item, $latest['submission']]) }}" target="_blank" rel="noopener noreferrer">
                                    <i class="bi bi-eye"></i> View{{ $versionSuffix }}
                                </a>
                            @endif
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('marketing.content-items.submissions.download', [$brand, $item, $latest['submission']]) }}">
                                <i class="bi bi-download"></i> Download{{ $versionSuffix }}
                            </a>
                        @elseif($latest && $latest['submission']->link_url)
                            <a class="btn btn-sm btn-outline-secondary" href="{{ $latest['submission']->link_url }}" target="_blank" rel="noopener">
                                <i class="bi bi-box-arrow-up-right"></i> Open{{ $versionSuffix }} link
                            </a>
                        @endif
                        @if($row['history']->count() > 1)
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#cl-hist-{{ $item->id }}">
                                <i class="bi bi-clock-history"></i> History ({{ $row['history']->count() }})
                            </button>
                        @endif
                    </div>
                </div>

                @if($row['history']->count() > 1)
                    <div class="collapse" id="cl-hist-{{ $item->id }}">
                        <div class="cl-history">
                            @foreach($row['history'] as $version)
                                <div class="cl-version-row d-flex align-items-center justify-content-between flex-wrap gap-2">
                                    <div>
                                        <strong>{{ $version['version_label'] }}</strong>
                                        <span class="cl-item-meta">
                                            — submitted by {{ $version['submission']->submittedBy?->name ?? '—' }}
                                            on {{ $dhaka($version['submission']->created_at) }}
                                            @if($version['collection'])
                                                · collected by {{ $version['collection']->collectedBy?->name ?? '—' }} on {{ $dhaka($version['collection']->collected_at) }}
                                            @endif
                                            @if($version['publication'])
                                                · published by {{ $version['publication']->publishedBy?->name ?? '—' }}
                                                on {{ $dhaka($version['publication']->published_at) }}
                                                @if($version['publication']->facebook_post_url)
                                                    (<a href="{{ $version['publication']->facebook_post_url }}" target="_blank" rel="noopener">post</a>)
                                                @endif
                                            @endif
                                        </span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        @if($version['review_state'])
                                            <span class="spill {{ $reviewSpill[$version['review_state']] }}">{{ $reviewLabel[$version['review_state']] }}</span>
                                        @endif
                                        @if($version['submission']->file_path)
                                            @if($looksPreviewable($version['submission']->file_path))
                                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('marketing.content-items.submissions.preview', [$brand, $item, $version['submission']]) }}" target="_blank" rel="noopener noreferrer">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                            @endif
                                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('marketing.content-items.submissions.download', [$brand, $item, $version['submission']]) }}">
                                                <i class="bi bi-download"></i> Download
                                            </a>
                                        @elseif($version['submission']->link_url)
                                            <a class="btn btn-sm btn-outline-secondary" href="{{ $version['submission']->link_url }}" target="_blank" rel="noopener">
                                                <i class="bi bi-box-arrow-up-right"></i> Open link
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="cl-empty-cat">Pending / No content submitted yet</div>
        @endforelse
    </div>
@endforeach
@endsection
