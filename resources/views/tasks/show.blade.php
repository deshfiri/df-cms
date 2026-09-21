@extends('layouts.app')
@section('title', 'Task · ' . $task->title)

@php
    $Involvement = \App\Services\TaskInvolvementService::class;

    $statusSpill = fn ($s) => match ($s) {
        'Pending'     => 'spill-pending',
        'In Progress' => 'spill-in-progress',
        'On Hold'     => 'spill-hold',
        'Submitted'   => 'spill-warning',
        'Completed'   => 'spill-approved',
        'Cancelled'   => 'spill-rejected',
        default       => 'spill-pending',
    };
    $prioritySpill = fn ($p) => match ($p) {
        'Low'    => 'spill-hold',
        'Medium' => 'spill-in-progress',
        'High'   => 'spill-warning',
        'Urgent' => 'spill-rejected',
        default  => 'spill-hold',
    };

    // One look per kind of event in the history.
    $eventLook = [
        'created'            => ['bi-plus-circle',            'var(--primary)'],
        'updated'            => ['bi-pencil',                 'var(--text3)'],
        'reassigned'         => ['bi-person-gear',            'var(--primary)'],
        'status_changed'     => ['bi-arrow-repeat',           'var(--primary)'],
        'due_changed'        => ['bi-calendar-event',         'var(--c-yellow)'],
        'submitted'          => ['bi-send',                   'var(--c-green)'],
        'approved'           => ['bi-check2-circle',          'var(--c-green)'],
        'returned'           => ['bi-arrow-counterclockwise', 'var(--c-red)'],
        'comment'            => ['bi-chat-left-text',         'var(--text2)'],
        'attachment_added'   => ['bi-paperclip',              'var(--text2)'],
        'attachment_removed' => ['bi-trash',                  'var(--text3)'],
        'note_added'         => ['bi-sticky',                 'var(--text2)'],
        'note_removed'       => ['bi-trash',                  'var(--text3)'],
        'link_added'         => ['bi-link-45deg',             'var(--text2)'],
        'link_removed'       => ['bi-trash',                  'var(--text3)'],
    ];
    $eventTitle = [
        'created' => 'created the task', 'updated' => 'edited the task', 'reassigned' => 'reassigned it',
        'status_changed' => 'changed the status', 'due_changed' => 'moved the deadline', 'submitted' => 'submitted the task',
        'approved' => 'accepted the submission', 'returned' => 'sent it back for revision', 'comment' => 'commented',
        'attachment_added' => 'added a file', 'attachment_removed' => 'removed a file',
        'note_added' => 'left a note', 'note_removed' => 'removed a note',
        'link_added' => 'shared a link', 'link_removed' => 'removed a link',
    ];

    // Files, links and notes in one list, in the order they were shared.
    $shared = $task->attachments->toBase()->concat($task->notes)->sortByDesc('created_at')->values();

    // An edit that changed who, when or what state also logs those as their own
    // events; the generic "edited" row next to them would only repeat it.
    $activities = $task->activities->filter(function ($a) use ($task, $Involvement) {
        if ($Involvement::eventOf($a)[0] !== 'updated') {
            return true;
        }
        return !$task->activities->contains(fn ($b) => $b->id !== $a->id
            && $b->user_id === $a->user_id
            && in_array($b->event, ['reassigned', 'status_changed', 'due_changed'], true)
            && abs($b->created_at->diffInSeconds($a->created_at)) < 3);
    });

    $roleLabel = [
        $Involvement::ROLE_PRIMARY => 'Assignee', $Involvement::ROLE_CONTRIBUTOR => 'Contributor',
        $Involvement::ROLE_REVIEWER => 'Reviewer', $Involvement::ROLE_PASSED_THROUGH => 'Passed through',
        $Involvement::ROLE_CREATOR => 'Requester', $Involvement::ROLE_OTHER => 'Took part',
    ];
    $roleSpill = [
        $Involvement::ROLE_PRIMARY => 'spill-in-progress', $Involvement::ROLE_CONTRIBUTOR => 'spill-approved',
        $Involvement::ROLE_REVIEWER => 'spill-warning', $Involvement::ROLE_PASSED_THROUGH => 'spill-hold',
        $Involvement::ROLE_CREATOR => 'spill-pending', $Involvement::ROLE_OTHER => 'spill-hold',
    ];
    $people = $task->involvements->sortBy(fn ($i) => array_search($i->role, array_keys($roleLabel), true))->values();

    $iso = fn ($dt) => $dt?->toIso8601String();
    $avatar = function ($user, $size = 26) {
        if (!$user) {
            return '<span class="tp-avatar" style="width:' . $size . 'px;height:' . $size . 'px">?</span>';
        }
        return $user->avatarUrl()
            ? '<img class="tp-avatar" src="' . e($user->avatarUrl()) . '" alt="" style="width:' . $size . 'px;height:' . $size . 'px">'
            : '<span class="tp-avatar" style="width:' . $size . 'px;height:' . $size . 'px">' . e($user->initials()) . '</span>';
    };
    $me = auth()->user();
@endphp

@push('styles')
<style>
    .tp-crumb { font-size: .75rem; color: var(--text3); }
    .tp-crumb a { color: var(--text3); text-decoration: none; }
    .tp-crumb a:hover { color: var(--primary); }
    .tp-title { font-size: 1.25rem; font-weight: 700; color: var(--text); margin: 0; word-break: break-word; }
    .tp-meta { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; margin-top: .5rem; }
    .tp-chip { font-size: .7rem; color: var(--text2); background: var(--surface2); border: 1px solid var(--border); border-radius: 999px; padding: 1px 9px; white-space: nowrap; }
    .tp-label { font-size: .68rem; border-radius: 999px; padding: 1px 9px; color: #fff; }
    .tp-actions { display: flex; flex-wrap: wrap; gap: .45rem; align-items: center; }
    .tp-hint { font-size: .74rem; color: var(--text3); }
    .tp-hint strong { color: var(--text2); }

    .tp-submit { font-weight: 600; padding: .45rem 1rem; }
    .tp-callout { display: flex; gap: .6rem; align-items: flex-start; border: 1px solid var(--border); border-left: 3px solid var(--primary); background: var(--surface2); border-radius: var(--radius); padding: .6rem .8rem; font-size: .8rem; color: var(--text2); }
    .tp-callout.is-warn { border-left-color: var(--c-yellow); }
    .tp-callout.is-danger { border-left-color: var(--c-red); }
    .tp-callout i { margin-top: 1px; }

    .tp-card-h { display: flex; justify-content: space-between; align-items: center; gap: .5rem; }
    .tp-card-h h6 { margin: 0; font-weight: 700; font-size: .85rem; }
    .tp-count { font-size: .7rem; color: var(--text3); }
    .tp-brief { font-size: .85rem; color: var(--text2); white-space: pre-wrap; word-break: break-word; margin: 0; }

    .tp-avatar { border-radius: 50%; object-fit: cover; background: rgba(var(--primary-rgb), .12); color: var(--primary); display: inline-grid; place-items: center; font-size: .68rem; font-weight: 700; flex-shrink: 0; }

    /* Live timer */
    .tt-state { display: inline-flex; align-items: center; gap: .4rem; font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--text2); }
    .tt-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--text3); }
    .tt-state[data-tone="running"] .tt-dot { background: var(--c-green); box-shadow: 0 0 0 3px rgba(22,163,74,.18); animation: ttPulse 1.6s infinite; }
    .tt-state[data-tone="overdue"] { color: var(--c-red); }
    .tt-state[data-tone="overdue"] .tt-dot { background: var(--c-red); }
    .tt-state[data-tone="done"] .tt-dot { background: var(--c-green); }
    .tt-state[data-tone="waiting"] .tt-dot { background: var(--c-yellow); }
    @keyframes ttPulse { 50% { box-shadow: 0 0 0 6px rgba(22,163,74,0); } }
    @media (prefers-reduced-motion: reduce) { .tt-state[data-tone="running"] .tt-dot { animation: none; } }
    .tt-main { font-size: 2rem; font-weight: 800; color: var(--text); font-variant-numeric: tabular-nums; line-height: 1.1; margin-top: .35rem; letter-spacing: -.01em; }
    .tt-main.is-overdue { color: var(--c-red); }
    .tt-caption { font-size: .78rem; color: var(--text3); margin-top: 2px; }
    .tt-bar { height: 6px; border-radius: 3px; background: var(--surface2); overflow: hidden; margin-top: .75rem; }
    .tt-bar > span { display: block; height: 100%; width: 0; background: var(--primary); transition: width .6s ease; }
    .tt-bar.is-late > span { background: var(--c-red); }
    .tt-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem .9rem; margin-top: .9rem; }
    .tt-grid .k, .tp-dl .k { font-size: .66rem; text-transform: uppercase; letter-spacing: .04em; color: var(--text3); }
    .tt-grid .v, .tp-dl .v { font-size: .8rem; color: var(--text); font-weight: 600; font-variant-numeric: tabular-nums; }

    .tp-dl { display: grid; grid-template-columns: 1fr; gap: .55rem; }
    .tp-dl-row { display: flex; justify-content: space-between; gap: .75rem; align-items: baseline; border-bottom: 1px dashed var(--border); padding-bottom: .45rem; }
    .tp-dl-row:last-child { border-bottom: 0; padding-bottom: 0; }
    .tp-dl-row .v { text-align: right; min-width: 0; word-break: break-word; }
    .tp-person { display: inline-flex; align-items: center; gap: .4rem; }

    /* Files */
    .tp-file { display: flex; align-items: center; gap: .6rem; padding: .5rem .6rem; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); margin-bottom: .4rem; }
    .tp-file-icon { width: 44px; height: 44px; border-radius: 8px; display: grid; place-items: center; background: var(--surface2); color: var(--primary); font-size: 1.1rem; flex-shrink: 0; }
    .tp-file-name { font-size: .8rem; font-weight: 600; color: var(--text); text-decoration: none; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .tp-file-name:hover { color: var(--primary); }
    .tp-file-sub { font-size: .68rem; color: var(--text3); }
    .tp-icon-btn { background: none; border: 0; color: var(--text3); padding: .25rem .35rem; border-radius: 6px; line-height: 1; text-decoration: none; }
    .tp-icon-btn:hover { color: var(--primary); background: var(--surface2); }
    .tp-icon-btn.is-danger:hover { color: var(--c-red); }

    /* Links and notes, beside the file drop zone */
    .tp-share-col { display: flex; }
    .tp-share-col > .dzone, .tp-share-col > .tp-note-form { flex: 1; min-width: 0; }
    .tp-share-col > .dzone { display: flex; flex-direction: column; justify-content: center; }
    .tp-note-form { display: flex; flex-direction: column; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); padding: .5rem .6rem; transition: border-color .12s, box-shadow .12s; }
    .tp-note-form:focus-within { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb), .12); }
    .tp-note-form textarea { flex: 1; min-height: 2.8rem; border: 0; outline: 0; resize: none; padding: 0; background: transparent; color: var(--text); font-size: .8rem; }
    .tp-note-form textarea::placeholder { color: var(--text3); }
    .tp-note-foot { display: flex; justify-content: space-between; align-items: center; gap: .5rem; margin-top: .35rem; }
    .tp-note-hint { font-size: .68rem; color: var(--text3); }
    .tp-file.tp-note { align-items: flex-start; }
    .tp-note-body { font-size: .8rem; color: var(--text); white-space: pre-wrap; word-break: break-word; }
    .tp-note-body a { color: var(--primary); word-break: break-all; }

    /* Discussion */
    .tp-comment { display: flex; gap: .6rem; padding: .6rem 0; border-bottom: 1px solid var(--border); }
    .tp-comment:last-child { border-bottom: 0; }
    .tp-comment-head { font-size: .76rem; color: var(--text3); display: flex; gap: .4rem; align-items: baseline; flex-wrap: wrap; }
    .tp-comment-head strong { color: var(--text); }
    .tp-comment-body { font-size: .82rem; color: var(--text2); white-space: pre-wrap; word-break: break-word; margin-top: 2px; }

    /* History */
    .tp-tl { position: relative; padding-left: 30px; margin: 0; list-style: none; }
    .tp-tl::before { content: ''; position: absolute; left: 11px; top: 6px; bottom: 6px; width: 2px; background: var(--border); }
    .tp-tl li { position: relative; padding-bottom: .85rem; }
    .tp-tl li:last-child { padding-bottom: 0; }
    .tp-tl-dot { position: absolute; left: -30px; top: 0; width: 24px; height: 24px; border-radius: 50%; background: var(--surface); border: 2px solid var(--border); display: grid; place-items: center; }
    .tp-tl-dot i { font-size: .68rem; }
    .tp-tl-line { font-size: .8rem; color: var(--text2); }
    .tp-tl-line strong { color: var(--text); }
    .tp-tl-detail { font-size: .76rem; color: var(--text3); margin-top: 1px; word-break: break-word; }
    .tp-tl-time { font-size: .68rem; color: var(--text3); }

    /* People */
    .tp-people-row { display: flex; align-items: center; gap: .55rem; padding: .45rem 0; border-bottom: 1px dashed var(--border); }
    .tp-people-row:last-child { border-bottom: 0; }
    .tp-share { font-size: .72rem; font-weight: 700; color: var(--text); font-variant-numeric: tabular-nums; }
    .tp-share-bar { height: 3px; border-radius: 2px; background: var(--surface2); overflow: hidden; margin-top: 3px; width: 64px; }
    .tp-share-bar > span { display: block; height: 100%; background: var(--primary); }
    .tp-points { font-size: .66rem; color: var(--text3); }
</style>
@endpush

@section('content')

{{-- ── Header ─────────────────────────────────────────────────────────── --}}
<div class="card section-card mb-3">
    <div class="card-body">
        <div class="tp-crumb mb-2">
            <a href="{{ route('tasks.index') }}"><i class="bi bi-list-check me-1"></i>Tasks</a>
            <span class="mx-1">/</span>#{{ $task->id }}
        </div>

        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div class="min-w-0 flex-grow-1">
                <div class="mb-1" style="font-size:.78rem">
                    @if($task->client)
                        <i class="bi bi-person-badge me-1" style="color:var(--primary)"></i>
                        @can('view', $task->client)
                            <a href="{{ route('clients.show', $task->client) }}" class="fw-semibold text-decoration-none" style="color:var(--primary)">{{ $task->client->client_name }}</a>
                        @else
                            <span class="fw-semibold" style="color:var(--text2)">{{ $task->client->client_name }}</span>
                        @endcan
                        @if($task->client->dfid_number)<span style="color:var(--text3)"> · {{ $task->client->dfid_number }}</span>@endif
                    @else
                        <span style="color:var(--text3)"><i class="bi bi-building me-1"></i>Internal task</span>
                    @endif
                </div>
                <h1 class="tp-title">{{ $task->title }}</h1>
                <div class="tp-meta">
                    <span class="spill {{ $statusSpill($task->status) }}">{{ $task->status }}</span>
                    @if($task->is_overdue)
                        <span class="spill spill-rejected"><i class="bi bi-exclamation-triangle-fill me-1"></i>Overdue</span>
                    @endif
                    <span class="spill {{ $prioritySpill($task->priority) }}">{{ $task->priority }} priority</span>
                    <span class="tp-chip"><i class="bi bi-tag me-1"></i>{{ $task->type }}</span>
                    @if($task->requires_attachment)
                        <span class="tp-chip"><i class="bi bi-paperclip me-1"></i>File required to submit</span>
                    @endif
                    @foreach($task->labels as $label)
                        {{-- Only a real colour value reaches the style attribute. --}}
                        <span class="tp-label" style="background:{{ preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $label->color) ? $label->color : 'var(--primary)' }}">{{ $label->name }}</span>
                    @endforeach
                </div>
            </div>

            @if($can['update'] || $can['delete'])
                <div class="tp-actions">
                    @if($can['update'])
                        <button class="btn btn-sm btn-outline-secondary" id="tpEdit"><i class="bi bi-pencil me-1"></i>Edit</button>
                    @endif
                    @if($can['delete'])
                        <button class="btn btn-sm btn-outline-danger" id="tpDelete" title="Delete task"><i class="bi bi-trash"></i></button>
                    @endif
                </div>
            @endif
        </div>

        {{-- ── What happens next, and the button for it ────────────────── --}}
        <div class="mt-3 pt-3" style="border-top:1px solid var(--border)">
            @if($can['submit'] || $can['progress'] || $can['review'])
                <div class="tp-actions">
                    @if($can['submit'])
                        @if($submitBlocker = $task->submitBlocker())
                            {{-- Visible, so the assignee knows it's coming — live once work starts. --}}
                            <span class="d-inline-block" tabindex="0" title="{{ $submitBlocker }}">
                                <button class="btn btn-primary tp-submit" disabled style="pointer-events:none" aria-describedby="tpSubmitBlocked">
                                    <i class="bi bi-send me-1"></i>Submit Task
                                </button>
                            </span>
                        @else
                            <button class="btn btn-primary tp-submit task-submit" data-id="{{ $task->id }}" data-title="{{ $task->title }}" data-requires="{{ $task->requires_attachment ? 1 : 0 }}">
                                <i class="bi bi-send me-1"></i>Submit Task
                            </button>
                        @endif
                    @endif
                    @if($can['progress'])
                        @if($task->status !== 'In Progress')
                            <button class="btn btn-sm btn-outline-success task-progress" data-id="{{ $task->id }}" data-status="In Progress">
                                <i class="bi bi-play-fill me-1"></i>{{ $task->status === 'On Hold' ? 'Resume work' : 'Start work' }}
                            </button>
                        @else
                            <button class="btn btn-sm btn-outline-secondary task-progress" data-id="{{ $task->id }}" data-status="On Hold">
                                <i class="bi bi-pause-fill me-1"></i>Put on hold
                            </button>
                        @endif
                    @endif
                    @if($can['review'])
                        <button class="btn btn-warning task-review" data-id="{{ $task->id }}" data-title="{{ $task->title }}">
                            <i class="bi bi-clipboard-check me-1"></i>Review submission
                        </button>
                    @endif
                </div>
                @if($can['submit'] && $task->submitBlocker())
                    <div class="tp-hint mt-2" id="tpSubmitBlocked"><i class="bi bi-play-circle me-1"></i>{{ $task->submitBlocker() }} Press <strong>Start work</strong> and Submit becomes available.</div>
                @elseif($can['submit'] && $awaitingSubmissionFile)
                    <div class="tp-hint mt-2"><i class="bi bi-paperclip me-1"></i>This task needs a file with the submission — attach it below or in the submit dialog.</div>
                @elseif($can['submit'])
                    <div class="tp-hint mt-2">Submitting hands it to <strong>{{ $task->createdBy->name ?? 'the requester' }}</strong>, who accepts it or sends it back.</div>
                @elseif($can['review'])
                    <div class="tp-hint mt-2"><strong>{{ $task->assignedUser->name ?? 'The assignee' }}</strong> handed this in <time class="local-dt" data-format="relative" datetime="{{ $iso($task->submitted_at) }}">{{ $task->submitted_at?->diffForHumans() }}</time>. Accept it or send it back.</div>
                @endif
            @else
                <div class="tp-hint">
                    @switch($task->status)
                        @case('Submitted')
                            <i class="bi bi-hourglass-split me-1"></i>Submitted — waiting for <strong>{{ $task->createdBy->name ?? 'the requester' }}</strong> to review it.
                            @break
                        @case('Completed')
                            <i class="bi bi-check2-circle me-1" style="color:var(--c-green)"></i>Completed and accepted.
                            @break
                        @case('Cancelled')
                            <i class="bi bi-x-circle me-1"></i>This task was cancelled.
                            @break
                        @default
                            @if($task->assignedUser)
                                <i class="bi bi-person me-1"></i>With <strong>{{ (int) $task->assigned_to === (int) $me->id ? 'you' : $task->assignedUser->name }}</strong>.
                            @else
                                <i class="bi bi-person-dash me-1"></i>Not assigned to anyone yet.
                            @endif
                    @endswitch
                </div>
            @endif
        </div>
    </div>
</div>

@if($task->revisions->isNotEmpty() && in_array($task->status, ['In Progress', 'Pending', 'On Hold'], true))
    @php $lastReturn = $task->revisions->first(); @endphp
    <div class="tp-callout is-danger mb-3">
        <i class="bi bi-arrow-counterclockwise" style="color:var(--c-red)"></i>
        <div>
            <strong style="color:var(--text)">Sent back for revision</strong>
            by {{ $lastReturn->requestedBy->name ?? 'someone' }} ({{ $lastReturn->reason_category }})
            @if($lastReturn->note)<div class="mt-1" style="white-space:pre-wrap">{{ $lastReturn->note }}</div>@endif
        </div>
    </div>
@endif

<div class="row g-3">
    {{-- ── Main column ─────────────────────────────────────────────────── --}}
    <div class="col-lg-8 order-2 order-lg-1">
        <div class="card section-card mb-3">
            <div class="card-header py-2 tp-card-h"><h6><i class="bi bi-card-text me-1"></i>Brief</h6></div>
            <div class="card-body">
                @if(filled($task->description))
                    <p class="tp-brief">{{ $task->description }}</p>
                @else
                    <p class="tp-brief" style="color:var(--text3)">No description.</p>
                @endif
            </div>
        </div>

        <div class="card section-card mb-3">
            <div class="card-header py-2 tp-card-h">
                <h6><i class="bi bi-paperclip me-1"></i>Files &amp; links</h6>
                <span class="tp-count" id="taskFilesCount">{{ $shared->count() }}</span>
            </div>
            <div class="card-body">
                @if(!in_array($task->status, ['Completed', 'Cancelled'], true) || $can['manage'])
                    <div class="row g-2 mb-3">
                        <div class="col-md-6 tp-share-col">
                            <input type="file" id="taskFileInput" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6 tp-share-col">
                            <form id="taskNoteForm" class="tp-note-form">
                                <label for="taskNoteInput" class="visually-hidden">Share a link or leave a note</label>
                                <textarea id="taskNoteInput" rows="2" maxlength="2000" placeholder="Paste a link or leave a note…"></textarea>
                                <div class="tp-note-foot">
                                    <span class="tp-note-hint"><i class="bi bi-link-45deg me-1"></i>Links become clickable · Ctrl+Enter</span>
                                    <button type="submit" class="btn btn-sm btn-primary py-0 px-2" id="taskNoteSend">Add</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    @include('partials.dropzone')
                @endif
                @include('partials.file-preview')

                <div id="taskFiles" style="background:#EBF2FE;">
                    @forelse($shared as $a)
                        @if($a instanceof \App\Models\TaskNote)
                            <div class="tp-file{{ $a->is_link ? '' : ' tp-note' }}">
                                <span class="tp-file-icon"><i class="bi {{ $a->is_link ? 'bi-link-45deg' : 'bi-sticky' }}"></i></span>
                                <div class="flex-grow-1 min-w-0">
                                    @if($a->is_link)
                                        <a href="{{ $a->link_url }}" class="tp-file-name" target="_blank" rel="noopener noreferrer nofollow" title="{{ $a->body }}">{{ $a->body }}</a>
                                    @else
                                        <div class="tp-note-body">{!! $a->body_html !!}</div>
                                    @endif
                                    <div class="tp-file-sub">
                                        @if($a->is_link){{ $a->link_host }} · @endif{{ $a->user->name ?? '—' }} ·
                                        <time class="local-dt" data-format="relative" datetime="{{ $iso($a->created_at) }}">{{ $a->created_at->diffForHumans() }}</time>
                                    </div>
                                </div>
                                @if($a->is_link)
                                    <a href="{{ $a->link_url }}" class="tp-icon-btn" target="_blank" rel="noopener noreferrer nofollow" title="Open in a new tab"><i class="bi bi-box-arrow-up-right"></i></a>
                                @endif
                                @if((int) $a->user_id === (int) $me->id || $can['manage'])
                                    <button type="button" class="tp-icon-btn is-danger tp-note-delete" data-id="{{ $a->id }}" data-kind="{{ $a->is_link ? 'link' : 'note' }}" data-name="{{ \Illuminate\Support\Str::limit($a->body, 80) }}" title="Remove"><i class="bi bi-x-lg"></i></button>
                                @endif
                            </div>
                            @continue
                        @endif
                        @php
                            $download = route('tasks.attachments.download', [$task, $a]);
                            $mime = (string) $a->mime_type;
                            $icon = match (true) {
                                str_contains($mime, 'pdf') => 'bi-file-earmark-pdf',
                                str_contains($mime, 'zip') || str_contains($mime, 'compressed') => 'bi-file-earmark-zip',
                                str_contains($mime, 'word') => 'bi-file-earmark-word',
                                str_contains($mime, 'sheet') || str_contains($mime, 'excel') || str_contains($mime, 'csv') => 'bi-file-earmark-spreadsheet',
                                str_starts_with($mime, 'video/') => 'bi-file-earmark-play',
                                str_starts_with($mime, 'image/') => 'bi-file-earmark-image',
                                str_starts_with($mime, 'text/') => 'bi-file-earmark-text',
                                default => 'bi-file-earmark',
                            };
                        @endphp
                        <div class="tp-file">
                            @if($a->isPreviewableImage())
                                @php $preview = route('tasks.attachments.preview', [$task, $a]); @endphp
                                <button type="button" class="fp-thumb" data-preview-src="{{ $preview }}" data-preview-name="{{ $a->original_name }}" data-download-src="{{ $download }}" title="Preview {{ $a->original_name }}">
                                    <img src="{{ $preview }}" alt="" loading="lazy">
                                </button>
                            @else
                                <span class="tp-file-icon"><i class="bi {{ $icon }}"></i></span>
                            @endif
                            <div class="flex-grow-1 min-w-0">
                                <a href="{{ $download }}" class="tp-file-name" title="Download {{ $a->original_name }}">{{ $a->original_name }}</a>
                                <div class="tp-file-sub">
                                    {{ $a->file_size_human }} · {{ $a->user->name ?? '—' }} ·
                                    <time class="local-dt" data-format="relative" datetime="{{ $iso($a->created_at) }}">{{ $a->created_at->diffForHumans() }}</time>
                                </div>
                            </div>
                            @if($a->isPreviewableImage())
                                <button type="button" class="tp-icon-btn" data-preview-src="{{ $preview }}" data-preview-name="{{ $a->original_name }}" data-download-src="{{ $download }}" title="View and copy"><i class="bi bi-arrows-fullscreen"></i></button>
                            @endif
                            <a href="{{ $download }}" class="tp-icon-btn" title="Download"><i class="bi bi-download"></i></a>
                            @if((int) $a->user_id === (int) $me->id || $can['manage'])
                                <button type="button" class="tp-icon-btn is-danger tp-file-delete" data-id="{{ $a->id }}" data-name="{{ $a->original_name }}" title="Remove"><i class="bi bi-x-lg"></i></button>
                            @endif
                        </div>
                    @empty
                        <div class="text-center py-2 small" style="color:var(--text3)">Nothing shared yet.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="card section-card mb-3">
            <div class="card-header py-2 tp-card-h">
                <h6><i class="bi bi-chat-left-text me-1"></i>Discussion</h6>
                <span class="tp-count">{{ $task->comments->count() }}</span>
            </div>
            <div class="card-body">
                <div id="taskComments">
                    @forelse($task->comments as $c)
                        <div class="tp-comment">
                            {!! $avatar($c->user, 30) !!}
                            <div class="flex-grow-1 min-w-0">
                                <div class="tp-comment-head">
                                    <strong>{{ $c->user->name ?? 'User' }}</strong>
                                    <time class="local-dt" data-format="relative" datetime="{{ $iso($c->created_at) }}">{{ $c->created_at->diffForHumans() }}</time>
                                    @if((int) $c->user_id === (int) $me->id || $can['manage'])
                                        <button type="button" class="tp-icon-btn is-danger ms-auto tp-comment-delete" data-id="{{ $c->id }}" title="Delete comment"><i class="bi bi-x-lg" style="font-size:.7rem"></i></button>
                                    @endif
                                </div>
                                <div class="tp-comment-body">{{ $c->comment }}</div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-2 small" style="color:var(--text3)">No comments yet.</div>
                    @endforelse
                </div>
                <form id="taskCommentForm" class="mt-2">
                    <label for="taskCommentInput" class="visually-hidden">Add a comment</label>
                    <textarea id="taskCommentInput" class="form-control form-control-sm" rows="2" maxlength="2000" placeholder="Write a comment… (Ctrl+Enter to send)"></textarea>
                    <div class="d-flex justify-content-end mt-2">
                        <button type="submit" class="btn btn-sm btn-primary" id="taskCommentSend"><i class="bi bi-send me-1"></i>Comment</button>
                    </div>
                </form>
            </div>
        </div>

        @if($task->revisions->isNotEmpty() || $can['update'])
            <div class="card section-card mb-3">
                <div class="card-header py-2 tp-card-h">
                    <h6><i class="bi bi-arrow-counterclockwise me-1"></i>Revisions</h6>
                    @if($can['update'])
                        <button class="btn btn-sm btn-outline-warning py-0 px-2" id="tpRevisionToggle" style="font-size:.74rem">Request revision</button>
                    @endif
                </div>
                <div class="card-body">
                    @if($can['update'])
                        <form id="tpRevisionForm" class="p-2 rounded mb-3" style="background:var(--surface2);border:1px solid var(--border)" hidden>
                            <label class="form-label small fw-semibold" for="tpRevisionReason">Reason</label>
                            <select id="tpRevisionReason" class="form-select form-select-sm mb-2">
                                @foreach($reasonCategories as $reason)
                                    <option value="{{ $reason }}">{{ $reason }}</option>
                                @endforeach
                            </select>
                            <textarea id="tpRevisionNote" class="form-control form-control-sm mb-2" rows="2" maxlength="2000" placeholder="What needs to change? (optional)"></textarea>
                            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                <span style="font-size:.7rem;color:var(--text3)">Only “Employee Mistake” counts against the quality KPI.</span>
                                <button type="submit" class="btn btn-sm btn-warning">Send back</button>
                            </div>
                        </form>
                    @endif
                    @forelse($task->revisions as $rv)
                        <div class="p-2 rounded mb-2" style="background:var(--surface2);border:1px solid var(--border)">
                            <div style="font-size:.76rem">
                                <span class="spill {{ $rv->reason_category === 'Employee Mistake' ? 'spill-cancelled' : 'spill-hold' }}">{{ $rv->reason_category }}</span>
                                <span style="color:var(--text3)"> by {{ $rv->requestedBy->name ?? 'User' }} ·
                                    <time class="local-dt" datetime="{{ $iso($rv->created_at) }}">{{ $rv->created_at->format('d M Y, H:i') }}</time></span>
                            </div>
                            @if($rv->note)<div style="font-size:.8rem;margin-top:3px;white-space:pre-wrap;color:var(--text2)">{{ $rv->note }}</div>@endif
                        </div>
                    @empty
                        <div class="text-center py-1 small" style="color:var(--text3)">Never sent back.</div>
                    @endforelse
                </div>
            </div>
        @endif

        <div class="card section-card mb-3">
            <div class="card-header py-2 tp-card-h">
                <h6><i class="bi bi-clock-history me-1"></i>History</h6>
                <span class="tp-count">{{ $activities->count() }}</span>
            </div>
            <div class="card-body">
                <ol class="tp-tl" id="taskActivity">
                    @forelse($activities as $act)
                        @php
                            [$event, $meta] = \App\Services\TaskInvolvementService::eventOf($act);
                            // One event for both; what was shared decides how it reads.
                            $look = ($meta['kind'] ?? null) === 'link' ? str_replace('note_', 'link_', $event) : $event;
                            [$icon, $color] = $eventLook[$look] ?? ['bi-dot', 'var(--text3)'];
                            $detail = match ($event) {
                                'status_changed', 'reassigned' => $act->description,
                                'comment', 'note_added', 'note_removed' => \Illuminate\Support\Str::limit((string) $act->description, 160),
                                'attachment_added', 'attachment_removed' => $act->description,
                                'returned', 'approved'         => $act->description,
                                'submitted'                    => ($meta['note'] ?? null) ? '“' . \Illuminate\Support\Str::limit($meta['note'], 160) . '”' : null,
                                default                        => null,
                            };
                        @endphp
                        <li>
                            <span class="tp-tl-dot" style="border-color:{{ $color }}"><i class="bi {{ $icon }}" style="color:{{ $color }}"></i></span>
                            <div class="tp-tl-line"><strong>{{ $act->user->name ?? 'System' }}</strong> {{ $eventTitle[$look] ?? strtolower($act->action) }}</div>
                            @if($event === 'due_changed')
                                <div class="tp-tl-detail">
                                    <time class="local-dt" datetime="{{ $meta['from'] ?? '' }}">{{ $meta['from'] ?? 'no deadline' }}</time>
                                    → <time class="local-dt" datetime="{{ $meta['to'] ?? '' }}">{{ $meta['to'] ?? 'no deadline' }}</time>
                                </div>
                            @elseif($detail)
                                <div class="tp-tl-detail">{{ $detail }}</div>
                            @endif
                            @if($event === 'submitted' && !empty($meta['attachment_ids']))
                                <div class="tp-tl-detail"><i class="bi bi-paperclip"></i> with {{ count($meta['attachment_ids']) }} {{ \Illuminate\Support\Str::plural('file', count($meta['attachment_ids'])) }}</div>
                            @endif
                            <time class="tp-tl-time local-dt" data-format="full" datetime="{{ $iso($act->created_at) }}">{{ $act->created_at->format('d M Y, H:i') }}</time>
                        </li>
                    @empty
                        <li class="small" style="color:var(--text3)">Nothing recorded yet.</li>
                    @endforelse
                </ol>
            </div>
        </div>
    </div>

    {{-- ── Side column ─────────────────────────────────────────────────── --}}
    <div class="col-lg-4 order-1 order-lg-2">
        <div class="card section-card mb-3" id="taskTimer" aria-live="off">
            <div class="card-body">
                <div class="tt-state" id="ttState"><span class="tt-dot"></span><span id="ttStateLabel">—</span></div>
                <div class="tt-main" id="ttMain">—</div>
                <div class="tt-caption" id="ttCaption">&nbsp;</div>
                <div class="tt-bar" id="ttBarWrap" hidden><span id="ttBar"></span></div>
                <div class="tt-grid">
                    <div><div class="k">Started</div><div class="v" id="ttStarted">—</div></div>
                    <div><div class="k">Deadline</div><div class="v" id="ttDue">—</div></div>
                    <div><div class="k">Time on task</div><div class="v" id="ttElapsed">—</div></div>
                    <div><div class="k">Estimate</div><div class="v" id="ttEstimate">—</div></div>
                </div>
            </div>
        </div>

        <div class="card section-card mb-3">
            <div class="card-header py-2 tp-card-h"><h6><i class="bi bi-info-circle me-1"></i>Details</h6></div>
            <div class="card-body">
                <div class="tp-dl">
                    <div class="tp-dl-row"><span class="k">Assigned to</span>
                        <span class="v">@if($task->assignedUser)<span class="tp-person">{!! $avatar($task->assignedUser, 22) !!}{{ $task->assignedUser->name }}</span>@else<span style="color:var(--text3)">Unassigned</span>@endif</span></div>
                    <div class="tp-dl-row"><span class="k">Requested by</span>
                        <span class="v">@if($task->createdBy)<span class="tp-person">{!! $avatar($task->createdBy, 22) !!}{{ $task->createdBy->name }}</span>@else — @endif</span></div>
                    <div class="tp-dl-row"><span class="k">Created</span>
                        <span class="v"><time class="local-dt" data-format="full" datetime="{{ $iso($task->created_at) }}">{{ $task->created_at?->format('d M Y, H:i') }}</time></span></div>
                    @if($task->start_date)
                        <div class="tp-dl-row"><span class="k">Planned start</span><span class="v">{{ $task->start_date->format('d M Y') }}</span></div>
                    @endif
                    <div class="tp-dl-row"><span class="k">Started</span>
                        <span class="v">@if($task->started_at)<time class="local-dt" data-format="full" datetime="{{ $iso($task->started_at) }}">{{ $task->started_at->format('d M Y, H:i') }}</time>@else<span style="color:var(--text3)">Not yet</span>@endif</span></div>
                    <div class="tp-dl-row"><span class="k">Due</span>
                        <span class="v">
                            @if($task->due_at && $task->dueHasTime())
                                <time class="local-dt" data-format="full" datetime="{{ $iso($task->due_at) }}">{{ $task->due_at->format('d M Y, H:i') }}</time>
                            @elseif($task->due_date)
                                {{ $task->due_date->format('d M Y') }} <span style="color:var(--text3);font-weight:400">end of day</span>
                            @else
                                <span style="color:var(--text3)">No deadline</span>
                            @endif
                        </span></div>
                    @if($task->submitted_at)
                        <div class="tp-dl-row"><span class="k">Submitted</span>
                            <span class="v"><time class="local-dt" data-format="full" datetime="{{ $iso($task->submitted_at) }}">{{ $task->submitted_at->format('d M Y, H:i') }}</time></span></div>
                    @endif
                    @if($task->completed_at)
                        <div class="tp-dl-row"><span class="k">Completed</span>
                            <span class="v"><time class="local-dt" data-format="full" datetime="{{ $iso($task->completed_at) }}">{{ $task->completed_at->format('d M Y, H:i') }}</time></span></div>
                    @endif
                    @if($task->updatedBy)
                        <div class="tp-dl-row"><span class="k">Last changed</span>
                            <span class="v">{{ $task->updatedBy->name }} · <time class="local-dt" data-format="relative" datetime="{{ $iso($task->updated_at) }}">{{ $task->updated_at?->diffForHumans() }}</time></span></div>
                    @endif
                </div>
            </div>
        </div>

        <div class="card section-card mb-3">
            <div class="card-header py-2 tp-card-h">
                <h6><i class="bi bi-people me-1"></i>People</h6>
                @if($can['shares'])<span class="tp-count">work share</span>@endif
            </div>
            <div class="card-body" id="taskPeople">
                @forelse($people as $inv)
                    <div class="tp-people-row">
                        {!! $avatar($inv->user, 28) !!}
                        <div class="flex-grow-1 min-w-0">
                            <div style="font-size:.8rem;font-weight:600;color:var(--text)" class="text-truncate">{{ $inv->user->name ?? 'Former user' }}</div>
                            <span class="spill {{ $roleSpill[$inv->role] ?? 'spill-hold' }}" style="font-size:.6rem">{{ $roleLabel[$inv->role] ?? $inv->role }}</span>
                            @if($can['shares'] && !empty($inv->breakdown))
                                <div class="tp-points">
                                    @foreach($inv->breakdown as $what => $pts){{ \Illuminate\Support\Str::headline($what) }} +{{ rtrim(rtrim(number_format($pts, 2), '0'), '.') }}@if(!$loop->last) · @endif @endforeach
                                </div>
                            @endif
                        </div>
                        @if($can['shares'] && in_array($inv->role, $Involvement::DOER_ROLES, true))
                            <div class="text-end">
                                <div class="tp-share">{{ round($inv->share * 100) }}%</div>
                                <div class="tp-share-bar"><span style="width:{{ round($inv->share * 100) }}%"></span></div>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="small" style="color:var(--text3)">Nobody has worked on this yet.</div>
                @endforelse
                @if($can['shares'] && $people->isNotEmpty())
                    <div class="tp-points mt-2">Credit follows the work recorded above — opening or holding a task earns none.</div>
                @endif
            </div>
        </div>
    </div>
</div>

@if($can['update'])
    @include('tasks.partials.form-modal')
@endif
@include('tasks.partials.actions')
@endsection

@push('scripts')
<script>
(function () {
    const TASK_ID = {{ $task->id }};
    let timer = {{ Js::from($timer) }};

    // ── Times in the viewer's own zone ───────────────────────────────────
    const fullFmt = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    const rel = typeof Intl.RelativeTimeFormat === 'function' ? new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }) : null;

    function relative(ms) {
        const s = Math.round((ms - serverNow()) / 1000), a = Math.abs(s);
        if (!rel) return fullFmt.format(new Date(ms));
        if (a < 60) return rel.format(Math.round(s), 'second');
        if (a < 3600) return rel.format(Math.round(s / 60), 'minute');
        if (a < 86400) return rel.format(Math.round(s / 3600), 'hour');
        if (a < 2592000) return rel.format(Math.round(s / 86400), 'day');
        return fullFmt.format(new Date(ms));
    }

    window.localizeTimes = function (root) {
        (root || document).querySelectorAll('time.local-dt[datetime]').forEach(function (el) {
            const ms = Date.parse(el.getAttribute('datetime'));
            if (isNaN(ms)) return;
            el.title = fullFmt.format(new Date(ms));
            el.textContent = el.dataset.format === 'relative' ? relative(ms) : fullFmt.format(new Date(ms));
        });
    };

    // ── Live counter ─────────────────────────────────────────────────────
    // Everything is measured against the server's clock: the offset between it
    // and this machine is taken from `server_now`, so a wrong local clock does
    // not change what the counter says, and a refresh picks up where it was.
    let offset = Date.parse(timer.server_now) - Date.now();
    function serverNow() { return Date.now() + offset; }

    function pad(n) { return String(n).padStart(2, '0'); }
    function clock(ms) {
        const t = Math.max(0, Math.floor(ms / 1000));
        const d = Math.floor(t / 86400), h = Math.floor(t % 86400 / 3600), m = Math.floor(t % 3600 / 60), s = t % 60;
        return d > 0 ? d + 'd ' + pad(h) + 'h ' + pad(m) + 'm' : pad(h) + ':' + pad(m) + ':' + pad(s);
    }
    function words(ms) {
        const t = Math.max(0, Math.round(ms / 60000));
        const d = Math.floor(t / 1440), h = Math.floor(t % 1440 / 60), m = t % 60;
        return [d && d + 'd', h && h + 'h', (!d && (m || !h)) && m + 'm'].filter(Boolean).join(' ');
    }
    const parse = v => v ? Date.parse(v) : null;
    const $ = id => document.getElementById(id);

    function render() {
        const now = serverNow();
        const due = parse(timer.due_at), started = parse(timer.started_at), created = parse(timer.created_at);
        const submitted = parse(timer.submitted_at), completed = parse(timer.completed_at);
        const ended = completed || submitted;

        let state = timer.state;
        // Crossing the deadline happens on this screen, not only on refresh.
        if (due && ['running', 'paused', 'not_started'].includes(state) && now > due) state = 'overdue';

        let label, main, caption, tone;
        switch (state) {
            case 'completed':
                label = 'Completed'; tone = 'done';
                main = started && completed ? clock(completed - started) : '—';
                caption = due && completed
                    ? (completed <= due ? 'from start to finish · on time' : 'from start to finish · ' + words(completed - due) + ' late')
                    : 'Completed.';
                break;
            case 'submitted':
                label = 'Waiting for review'; tone = 'waiting';
                // Older tasks can be Submitted with no hand-in time recorded.
                main = submitted ? clock(now - submitted) : '—';
                caption = submitted
                    ? 'since it was handed in' + (due ? (submitted <= due ? ' · on time' : ' · ' + words(submitted - due) + ' after the deadline') : '')
                    : 'Handed in — waiting for review.';
                break;
            case 'cancelled':
                label = 'Cancelled'; tone = 'idle'; main = '—'; caption = 'No time is being counted.';
                break;
            case 'overdue':
                label = 'Overdue'; tone = 'overdue';
                main = clock(now - due);
                caption = 'past the deadline' + (started ? '' : ' · not started');
                break;
            case 'running':
                label = 'In progress'; tone = 'running';
                main = due ? clock(due - now) : (started ? clock(now - started) : '—');
                caption = due ? 'left until the deadline' : (started ? 'since work started' : 'No deadline set.');
                break;
            case 'paused':
                label = 'On hold'; tone = 'idle';
                main = due ? clock(due - now) : '—';
                caption = due ? 'left until the deadline · on hold' : 'Work is on hold.';
                break;
            default:
                label = 'Not started'; tone = 'idle';
                main = due ? clock(due - now) : '—';
                caption = due ? 'left until the deadline' : 'No deadline set.';
        }

        $('ttState').dataset.tone = tone;
        $('ttStateLabel').textContent = label;
        $('ttMain').textContent = main;
        $('ttMain').classList.toggle('is-overdue', state === 'overdue');
        $('ttCaption').textContent = caption;

        // How much of the time given has been used.
        if (due) {
            const from = started || created;
            const upTo = ended || now;
            const pct = due > from ? Math.min(100, Math.max(0, (upTo - from) / (due - from) * 100)) : 100;
            $('ttBarWrap').hidden = false;
            $('ttBar').style.width = pct.toFixed(1) + '%';
            $('ttBarWrap').classList.toggle('is-late', upTo > due);
        } else {
            $('ttBarWrap').hidden = true;
        }

        $('ttStarted').textContent = started ? fullFmt.format(new Date(started)) : 'Not yet';
        $('ttDue').textContent = due
            ? (timer.due_has_time ? fullFmt.format(new Date(due)) : @json($task->due_date?->format('d M Y')) + ' (end of day)')
            : 'None';
        $('ttElapsed').textContent = started && (ended || now) >= started ? words((ended || now) - started) : '—';
        $('ttEstimate').textContent = timer.estimated_seconds ? words(timer.estimated_seconds * 1000) : '—';
    }

    render();
    setInterval(render, 1000);
    localizeTimes();
    setInterval(() => document.querySelectorAll('time.local-dt[data-format="relative"]').length && localizeTimes(), 60000);

    // Resync with the server now and then — someone else may have moved the task.
    setInterval(function () {
        if (document.hidden) return;
        jQuery.getJSON('/tasks/' + TASK_ID, { timer_only: 1 }).done(function (r) {
            const statusChanged = r.status !== @json($task->status);
            timer = r.timer;
            offset = Date.parse(timer.server_now) - Date.now();
            if (statusChanged && !jQuery('.modal.show, .swal2-container').length) location.reload();
        });
    }, 60000);

    // ── Refresh parts of the page after a change ─────────────────────────
    // Re-reads this page and swaps the named sections, so what is shown is
    // always what the server rendered — one source of truth for the markup.
    function refresh(selectors) {
        return fetch(location.pathname, { headers: { Accept: 'text/html' }, credentials: 'same-origin' })
            .then(r => r.ok ? r.text() : Promise.reject(r))
            .then(function (html) {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                selectors.forEach(function (sel) {
                    const fresh = doc.querySelector(sel), here = document.querySelector(sel);
                    if (fresh && here) here.innerHTML = fresh.innerHTML;
                });
                localizeTimes();
            })
            .catch(() => location.reload());
    }

    // Status changes touch the header, the buttons and the counter: reload.
    jQuery(document).on('task:changed task:saved', () => location.reload());

    // ── Files, links and notes ───────────────────────────────────────────
    // One list and one count for all three.
    function refreshShared() {
        return refresh(['#taskFiles', '#taskActivity', '#taskPeople']).then(() => {
            document.getElementById('taskFilesCount').textContent = document.querySelectorAll('#taskFiles .tp-file').length;
        });
    }

    const fileInput = document.getElementById('taskFileInput');
    if (fileInput) {
        makeDropzone(fileInput, { hint: 'Any file type · up to 20 MB' });

        fileInput.addEventListener('change', function () {
            const file = fileInput.files[0];
            if (!file) return;
            if (file.size > 20 * 1024 * 1024) {
                Swal.fire('File too large', 'Files can be up to 20 MB.', 'warning');
                fileInput.value = '';
                fileInput.dispatchEvent(new Event('change'));
                return;
            }

            const fd = new FormData();
            fd.append('file', file);
            fileInput.disabled = true;

            jQuery.ajax({ url: '/tasks/' + TASK_ID + '/attachments', type: 'POST', data: fd, processData: false, contentType: false })
                .done(function () {
                    Swal.fire({ toast: true, position: 'bottom-end', icon: 'success', title: 'File added', showConfirmButton: false, timer: 1500 });
                    refreshShared();
                })
                .fail(x => { if (x.status !== 403) Swal.fire('Upload failed', ajaxMessage(x, 'The file could not be uploaded.'), 'error'); })
                .always(function () {
                    fileInput.disabled = false;
                    fileInput.value = '';
                    fileInput.dispatchEvent(new Event('change'));
                });
        });
    }

    jQuery(document).on('click', '.tp-file-delete', function () {
        const id = this.dataset.id, name = this.dataset.name;
        Swal.fire({ title: 'Remove this file?', text: name, icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Remove' })
            .then(function (r) {
                if (!r.isConfirmed) return;
                jQuery.ajax({ url: '/tasks/' + TASK_ID + '/attachments/' + id, type: 'DELETE' })
                    .done(refreshShared)
                    .fail(x => { if (x.status !== 403) Swal.fire('Could not remove the file', ajaxMessage(x, 'Please try again.'), 'error'); });
            });
    });

    const noteInput = document.getElementById('taskNoteInput');
    if (noteInput) {
        jQuery('#taskNoteForm').on('submit', function (e) {
            e.preventDefault();
            const body = noteInput.value.trim();
            if (!body) { noteInput.focus(); return; }
            const $send = jQuery('#taskNoteSend').prop('disabled', true);

            jQuery.post('/tasks/' + TASK_ID + '/notes', { body })
                .done(function () {
                    noteInput.value = '';
                    refreshShared();
                })
                .fail(x => { if (x.status !== 403) Swal.fire('Could not add it', ajaxMessage(x, 'Please try again.'), 'error'); })
                .always(() => $send.prop('disabled', false));
        });
        noteInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) jQuery('#taskNoteForm').trigger('submit');
        });
    }

    jQuery(document).on('click', '.tp-note-delete', function () {
        const id = this.dataset.id, kind = this.dataset.kind;
        Swal.fire({ title: 'Remove this ' + kind + '?', text: this.dataset.name, icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Remove' })
            .then(function (r) {
                if (!r.isConfirmed) return;
                jQuery.ajax({ url: '/tasks/' + TASK_ID + '/notes/' + id, type: 'DELETE' })
                    .done(refreshShared)
                    .fail(x => { if (x.status !== 403) Swal.fire('Could not remove the ' + kind, ajaxMessage(x, 'Please try again.'), 'error'); });
            });
    });

    // ── Discussion ───────────────────────────────────────────────────────
    const commentInput = document.getElementById('taskCommentInput');
    jQuery('#taskCommentForm').on('submit', function (e) {
        e.preventDefault();
        const comment = commentInput.value.trim();
        if (!comment) { commentInput.focus(); return; }
        const $send = jQuery('#taskCommentSend').prop('disabled', true);

        jQuery.post('/tasks/' + TASK_ID + '/comments', { comment })
            .done(function () {
                commentInput.value = '';
                refresh(['#taskComments', '#taskActivity', '#taskPeople']);
            })
            .fail(x => { if (x.status !== 403) Swal.fire('Could not post the comment', ajaxMessage(x, 'Please try again.'), 'error'); })
            .always(() => $send.prop('disabled', false));
    });
    commentInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) jQuery('#taskCommentForm').trigger('submit');
    });

    jQuery(document).on('click', '.tp-comment-delete', function () {
        const id = this.dataset.id;
        Swal.fire({ title: 'Delete this comment?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' })
            .then(function (r) {
                if (!r.isConfirmed) return;
                jQuery.ajax({ url: '/tasks/' + TASK_ID + '/comments/' + id, type: 'DELETE' })
                    .done(() => refresh(['#taskComments']))
                    .fail(x => { if (x.status !== 403) Swal.fire('Could not delete the comment', ajaxMessage(x, 'Please try again.'), 'error'); });
            });
    });

    // ── Management ───────────────────────────────────────────────────────
    jQuery('#tpEdit').on('click', () => openTaskEditor(TASK_ID));

    jQuery('#tpDelete').on('click', function () {
        Swal.fire({ title: 'Delete this task?', text: 'Its files, comments and history go with it.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' })
            .then(function (r) {
                if (!r.isConfirmed) return;
                jQuery.ajax({ url: '/tasks/' + TASK_ID, type: 'DELETE' })
                    .done(() => { window.location.href = @json(route('tasks.index')); })
                    .fail(x => { if (x.status !== 403) Swal.fire('Could not delete the task', ajaxMessage(x, 'Please try again.'), 'error'); });
            });
    });

    jQuery('#tpRevisionToggle').on('click', function () {
        const form = document.getElementById('tpRevisionForm');
        form.hidden = !form.hidden;
        if (!form.hidden) document.getElementById('tpRevisionReason').focus();
    });

    jQuery('#tpRevisionForm').on('submit', function (e) {
        e.preventDefault();
        jQuery.post('/tasks/' + TASK_ID + '/revisions', {
            reason_category: jQuery('#tpRevisionReason').val(),
            note: jQuery('#tpRevisionNote').val().trim(),
        })
            .done(() => location.reload())
            .fail(x => { if (x.status !== 403) Swal.fire('Could not request a revision', ajaxMessage(x, 'Please try again.'), 'error'); });
    });
})();
</script>
@endpush
