@extends('layouts.app')
@section('title', 'My Work')

@php
    $prioritySpill = ['Urgent' => 'spill-rejected', 'High' => 'spill-warning', 'Medium' => 'spill-in-progress', 'Low' => 'spill-hold'];
@endphp

@push('styles')
<style>
    .mwk-row { display: flex; align-items: center; gap: .75rem; padding: .65rem 1rem; border-bottom: 1px solid var(--border); }
    .mwk-row:last-child { border-bottom: 0; }
    .mwk-title { font-weight: 600; font-size: .83rem; color: var(--text); text-decoration: none; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    a.mwk-title:hover { color: var(--primary); }
    .mwk-meta { font-size: .7rem; color: var(--text3); display: flex; flex-wrap: wrap; gap: .1rem .7rem; margin-top: 1px; }
    .mwk-empty { text-align: center; padding: 1.6rem 1rem; color: var(--text3); font-size: .8rem; }
    .mwk-late { color: var(--c-red); font-weight: 600; }
</style>
@endpush

@section('content')

@include('partials.my-work-panel')

<div class="row g-3">
    @foreach([
        ['To do', 'bi-list-check', $openTasks, 'Nothing open is assigned to you.', 'due'],
        ['Waiting on you to review', 'bi-clipboard-check', $waitingOnMe, 'Nobody is waiting on your review.', 'from'],
        ['Handed in, awaiting review', 'bi-send-check', $handedIn, 'Nothing of yours is waiting on a reviewer.', 'submitted'],
    ] as [$heading, $icon, $list, $empty, $meta])
        <div class="col-lg-4">
            <div class="card section-card h-100">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0" style="font-size:.85rem"><i class="bi {{ $icon }} me-1"></i>{{ $heading }}</h6>
                    <span style="font-size:.72rem;color:var(--text3)">{{ $list->count() }}</span>
                </div>
                <div class="card-body p-0">
                    @forelse($list as $task)
                        <div class="mwk-row">
                            <div class="flex-grow-1 min-w-0">
                                @if($canTasks)
                                    <a href="{{ route('tasks.show', $task) }}" class="mwk-title">{{ $task->title }}</a>
                                @else
                                    <span class="mwk-title">{{ $task->title }}</span>
                                @endif
                                <div class="mwk-meta">
                                    @if($task->clients->isNotEmpty())<span><i class="bi bi-person-badge me-1"></i>{{ $task->clients->pluck('client_name')->join(', ') }}</span>@endif
                                    @if($meta === 'due')
                                        @if($task->due_at)
                                            <span class="{{ $task->is_overdue ? 'mwk-late' : '' }}"><i class="bi bi-calendar-event me-1"></i>{{ $task->is_overdue ? 'Overdue · ' : 'Due ' }}{{ $task->due_date?->format('d M') }}</span>
                                        @endif
                                        <span>{{ $task->status }}</span>
                                    @elseif($meta === 'from')
                                        <span><i class="bi bi-person me-1"></i>{{ $task->assignees->isEmpty() ? '—' : $task->assignees->pluck('name')->join(', ') }}</span>
                                        <span><i class="bi bi-send me-1"></i>{{ $task->submitted_at?->diffForHumans() ?? '—' }}</span>
                                    @else
                                        <span><i class="bi bi-send me-1"></i>{{ $task->submitted_at?->diffForHumans() ?? '—' }}</span>
                                    @endif
                                </div>
                            </div>
                            <span class="spill {{ $prioritySpill[$task->priority] ?? 'spill-hold' }}" style="font-size:.6rem">{{ $task->priority }}</span>
                        </div>
                    @empty
                        <div class="mwk-empty">{{ $empty }}</div>
                    @endforelse
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection
