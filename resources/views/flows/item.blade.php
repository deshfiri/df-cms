@extends('layouts.app')
@section('title', 'Item · ' . $item->title)

@php
    $statusSpill = fn ($s) => match ($s) {
        'Open'      => 'spill-running',
        'Completed' => 'spill-completed',
        'Cancelled' => 'spill-cancelled',
        default     => 'spill-hold',
    };
    $prioSpill = fn ($p) => match ($p) {
        'Urgent' => 'spill-cancelled',
        'High'   => 'spill-warning',
        'Normal' => 'spill-running',
        default  => 'spill-hold',
    };

    // Someone else addressed this item to its current owner (rather than them
    // claiming it off the shared pile) — worth wording differently.
    $lastMove  = $item->transitions->last();
    $addressed = $item->assigned_to !== null && $lastMove
        && $lastMove->to_stage_id === $item->current_stage_id
        && $lastMove->moved_by !== $item->assigned_to;
    $ownerVerb = $addressed ? 'Assigned to' : 'Claimed by';
@endphp

@push('styles')
<style>
    .tl { position: relative; padding-left: 26px; }
    .tl::before { content: ''; position: absolute; left: 9px; top: 4px; bottom: 4px; width: 2px; background: var(--border); }
    .tl-item { position: relative; padding-bottom: 1rem; }
    .tl-dot { position: absolute; left: -26px; top: 2px; width: 20px; height: 20px; border-radius: 50%; background: var(--surface); border: 2px solid var(--primary); display: grid; place-items: center; }
    .tl-dot i { font-size: .6rem; color: var(--primary); }

    /* Stage strip — where the item is, where it goes next, and who works each stage. */
    .stage-strip { display: flex; gap: .4rem; overflow-x: auto; padding: .1rem .1rem .3rem; }
    .ss { flex: 0 0 auto; min-width: 132px; max-width: 200px; border: 1px solid var(--border); border-radius: var(--radius); padding: .45rem .6rem; background: var(--surface2); }
    .ss-h { display: flex; align-items: center; gap: .35rem; font-size: .74rem; font-weight: 600; color: var(--text2); }
    .ss-h i { font-size: .7rem; color: var(--text3); flex-shrink: 0; }
    .ss-h span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ss-u { font-size: .64rem; color: var(--text3); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ss-done { opacity: .6; }
    .ss-done .ss-h i { color: var(--primary); }
    .ss-now { border-color: var(--primary); background: var(--surface); box-shadow: var(--shadow-sm); }
    .ss-now .ss-h, .ss-now .ss-h i { color: var(--primary); }
</style>
@endpush

@section('content')
<div class="mb-3">
    <a href="{{ url()->previous() }}" class="small text-decoration-none" style="color:var(--text3)"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<div class="card section-card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div class="min-w-0">
                {{-- Who the work is for comes first: without it a title like
                     "Logo design" says nothing about whose logo. --}}
                <div class="mb-1" style="font-size:.78rem">
                    @if($item->client)
                        <i class="bi bi-person-badge me-1" style="color:var(--primary)"></i>
                        @can('view', $item->client)
                            <a href="{{ route('clients.show', $item->client) }}" class="fw-semibold text-decoration-none" style="color:var(--primary)">{{ $item->client->client_name }}</a>
                        @else
                            <span class="fw-semibold" style="color:var(--text2)">{{ $item->client->client_name }}</span>
                        @endcan
                        @if($item->client->dfid_number)
                            <span style="color:var(--text3)">· {{ $item->client->dfid_number }}</span>
                        @endif
                    @else
                        <span style="color:var(--text3)"><i class="bi bi-building me-1"></i>Internal — not linked to a client</span>
                    @endif
                </div>
                <h5 class="fw-bold mb-1" style="color:var(--text)">{{ $item->title }}</h5>
                <div style="font-size:.75rem;color:var(--text3)">
                    {{ $item->flow->name ?? '—' }} ·
                    @if($item->currentStage)
                        Currently at <strong style="color:var(--text2)">{{ $item->currentStage->name }}</strong>
                    @elseif($item->status === 'Cancelled')
                        <strong style="color:var(--text2)">Cancelled</strong>
                    @elseif($item->status === 'Completed')
                        <strong style="color:var(--text2)">Completed</strong>
                    @endif
                    · started by {{ $item->creator->name ?? '—' }}
                </div>
                <div class="d-flex gap-2 mt-2 flex-wrap align-items-center">
                    <span class="spill {{ $prioSpill($item->priority) }}" style="font-size:.6rem">{{ $item->priority }} priority</span>
                    @if($item->due_date)
                        <span style="font-size:.7rem;font-weight:600;color:{{ $item->isOverdue() ? '#dc3545' : ($item->due_date->isToday() ? '#f59e0b' : 'var(--text3)') }}">
                            <i class="bi bi-calendar-event me-1"></i>{{ $item->isOverdue() ? 'Overdue · ' : ($item->due_date->isToday() ? 'Due today · ' : 'Due ') }}{{ $item->due_date->format('d M Y') }}
                        </span>
                    @endif
                    @if($item->isOpen())
                        @if($item->assignee)
                            <span style="font-size:.68rem;color:{{ $addressed ? 'var(--primary)' : 'var(--text2)' }}"><i class="bi {{ $addressed ? 'bi-person-fill-check' : 'bi-person-check' }} me-1"></i>{{ $ownerVerb }} {{ $item->assigned_to === auth()->id() ? 'you' : $item->assignee->name }}</span>
                        @else
                            <span style="font-size:.68rem;color:var(--text3)"><i class="bi bi-hand-index-thumb me-1"></i>Unclaimed</span>
                        @endif
                    @endif
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <span class="spill {{ $statusSpill($item->status) }}">{{ $item->status }}</span>
                @if($canManage && $item->isOpen())
                    <button class="btn btn-sm btn-outline-secondary py-0 px-2" id="editItemBtn" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger py-0 px-2" id="cancelItemBtn" title="Cancel item"><i class="bi bi-x-lg"></i></button>
                @endif
            </div>
        </div>

        @if($item->description)
            <p class="mt-3 mb-0 small" style="color:var(--text2);white-space:pre-wrap">{{ $item->description }}</p>
        @endif

        @if($stages->count() > 1)
            <div class="stage-strip mt-3">
                @foreach($stages as $s)
                    @php
                        $isNow  = $item->current_stage_id === $s->id;
                        $isDone = $item->currentStage ? $s->position < $item->currentStage->position : $item->status === 'Completed';
                        $names  = $s->users->pluck('name');
                    @endphp
                    <div class="ss {{ $isNow ? 'ss-now' : ($isDone ? 'ss-done' : '') }}">
                        <div class="ss-h">
                            <i class="bi {{ $isDone ? 'bi-check-circle-fill' : ($isNow ? 'bi-record-circle' : 'bi-circle') }}"></i>
                            <span>{{ $s->name }}</span>
                        </div>
                        <div class="ss-u" title="{{ $names->join(', ') }}">
                            {{ $names->isEmpty() ? 'nobody assigned' : $names->join(', ') }}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if($item->isOpen())
            <div class="mt-3 pt-3" style="border-top:1px solid var(--border)">
                @if($canAct)
                    <button class="btn btn-sm btn-primary" id="advanceBtn"><i class="bi bi-arrow-right-circle me-1"></i>Send to Next Stage</button>
                    @if($canSendBack)
                        <button class="btn btn-sm btn-outline-secondary ms-1" id="sendBackBtn"><i class="bi bi-arrow-left-circle me-1"></i>Send Back</button>
                    @endif
                    @if($item->assigned_to && ($item->assigned_to === auth()->id() || auth()->user()->can('manage workflows')))
                        <button class="btn btn-sm btn-outline-secondary ms-1" id="releaseBtn" title="Return to the team"><i class="bi bi-arrow-counterclockwise me-1"></i>Release</button>
                    @endif
                    {{-- Inline echo, not @if: a directive glued to a word character
                         ("forward@if") is not compiled by Blade, which would leave a
                         stray @endif behind and break the whole view. --}}
                    <div style="font-size:.68rem;color:var(--text3);margin-top:4px">Send it forward{{ $canSendBack ? ', send it back for changes,' : '' }} or release it back to the team.</div>
                @elseif($canClaim)
                    <button class="btn btn-sm btn-primary" id="claimBtn"><i class="bi bi-hand-index-thumb me-1"></i>Claim this item</button>
                    <div style="font-size:.68rem;color:var(--text3);margin-top:4px">Claim it to take ownership — then you can add work and send it forward.</div>
                @elseif($item->assignee)
                    <div style="font-size:.72rem;color:var(--text3)"><i class="bi bi-lock me-1"></i>{{ $ownerVerb }} <strong>{{ $item->assignee->name }}</strong> — read-only until they release it.</div>
                @endif
            </div>
        @endif
    </div>
</div>

<div class="card section-card mb-3">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">Attachments <span style="font-size:.7rem;color:var(--text3);font-weight:400">— files, links &amp; notes; they travel to the next stage</span></h6>
        <span style="font-size:.72rem;color:var(--text3)" id="attCount">{{ $item->attachments->count() }}</span>
    </div>
    <div class="card-body">
        @if($canAttach)
            <div class="p-2 rounded mb-3" style="background:var(--surface2);border:1px solid var(--border)">
                <div class="btn-group btn-group-sm mb-2 flex-wrap" role="group">
                    <button type="button" class="btn btn-outline-primary active att-kind" data-kind="file"><i class="bi bi-paperclip me-1"></i>File</button>
                    <button type="button" class="btn btn-outline-primary att-kind" data-kind="link"><i class="bi bi-link-45deg me-1"></i>Link / Video URL</button>
                    <button type="button" class="btn btn-outline-primary att-kind" data-kind="note"><i class="bi bi-sticky me-1"></i>Note</button>
                </div>
                <input type="text" id="attTitle" class="form-control form-control-sm mb-2" placeholder="Label (optional) — e.g. Logo v2" maxlength="150">
                {{-- Files upload as soon as they are picked; the label goes with a single file. --}}
                <div id="attFileWrap"><input type="file" id="attFile" class="form-control form-control-sm" multiple></div>
                @include('partials.upload-queue')
                <div id="attUrlWrap" class="d-none"><input type="url" id="attUrl" class="form-control form-control-sm" placeholder="https://… (video, Drive, Figma, etc.)"></div>
                <div id="attNoteWrap" class="d-none"><textarea id="attBody" class="form-control form-control-sm" rows="2" placeholder="Type anything…" maxlength="5000"></textarea></div>
                <div class="text-end mt-2 d-none" id="attAddWrap"><button class="btn btn-sm btn-primary" id="attAdd"><i class="bi bi-plus-lg me-1"></i>Add</button></div>
            </div>
        @endif

        @include('partials.file-preview')
        <div id="attList">
            @forelse($item->attachments as $a)
                @php
                    $icon = $a->isNote() ? 'bi-sticky' : ($a->isLink() ? 'bi-link-45deg'
                        : ($a->isImage() ? 'bi-image'
                        : (str_contains($a->mime_type ?? '', 'pdf') ? 'bi-file-earmark-pdf'
                        : (str_starts_with($a->mime_type ?? '', 'video/') ? 'bi-camera-video' : 'bi-paperclip'))));
                @endphp
                <div class="d-flex align-items-start gap-2 p-2 rounded mb-1" style="background:var(--c-blue-bg);border:1px solid color-mix(in srgb, var(--c-blue) 25%, transparent)">
                    @if($a->isPreviewableImage())
                        <button type="button" class="fp-thumb"
                                data-preview-src="{{ route('flow-items.attachments.preview', [$item, $a]) }}"
                                data-preview-name="{{ $a->title ?: $a->original_name }}"
                                data-download-src="{{ route('flow-items.attachments.download', [$item, $a]) }}"
                                title="Preview {{ $a->original_name }}">
                            <img src="{{ route('flow-items.attachments.preview', [$item, $a]) }}" alt="" loading="lazy">
                        </button>
                    @else
                        <i class="bi {{ $icon }}" style="color:var(--c-blue);font-size:1rem;margin-top:2px"></i>
                    @endif
                    <div class="flex-grow-1 min-w-0">
                        @if($a->isFile())
                            <a href="{{ route('flow-items.attachments.download', [$item, $a]) }}" class="fw-semibold small text-decoration-none" style="color:var(--text)">{{ $a->title ?: $a->original_name }}</a>
                            @if($a->isPreviewableImage())
                                <button type="button" class="btn btn-sm p-0 ms-1 align-baseline" style="color:var(--text3);font-size:.72rem"
                                        data-preview-src="{{ route('flow-items.attachments.preview', [$item, $a]) }}"
                                        data-preview-name="{{ $a->title ?: $a->original_name }}"
                                        data-download-src="{{ route('flow-items.attachments.download', [$item, $a]) }}"><i class="bi bi-arrows-fullscreen me-1"></i>View</button>
                            @endif
                            <div style="font-size:.66rem;color:var(--text3)">{{ $a->original_name }}{{ $a->file_size ? ' · ' . number_format($a->file_size / 1024, 0) . ' KB' : '' }}</div>
                        @elseif($a->isLink())
                            <a href="{{ $a->url }}" target="_blank" rel="noopener" class="fw-semibold small text-decoration-none" style="color:var(--text)">{{ $a->title ?: $a->url }} <i class="bi bi-box-arrow-up-right" style="font-size:.62rem"></i></a>
                            @if($a->title)<div style="font-size:.66rem;color:var(--text3);word-break:break-all">{{ $a->url }}</div>@endif
                        @else
                            @if($a->title)<div class="fw-semibold small" style="color:var(--text)">{{ $a->title }}</div>@endif
                            <div class="small" style="color:var(--text2);white-space:pre-wrap">{{ $a->body }}</div>
                        @endif
                        <div style="font-size:.64rem;color:var(--text3);margin-top:2px">{{ $a->uploadedBy->name ?? '—' }} · {{ $a->created_at->diffForHumans() }}</div>
                    </div>
                    @if($a->uploaded_by === auth()->id() || auth()->user()->can('manage workflows'))
                        <button class="btn btn-sm p-0 att-delete" data-id="{{ $a->id }}" style="color:var(--text3)" title="Remove"><i class="bi bi-x-circle"></i></button>
                    @endif
                </div>
            @empty
                <div class="text-center py-3 small" style="color:var(--text3)">No attachments yet.</div>
            @endforelse
        </div>
    </div>
</div>

<div class="card section-card mb-3">
    <div class="card-header py-3"><h6 class="fw-bold mb-0">Discussion</h6></div>
    <div class="card-body">
        <div id="commentList">
            @forelse($item->comments as $c)
                <div class="p-2 rounded mb-1" style="border-bottom:1px solid var(--border)">
                    <div style="font-size:.74rem;display:flex;justify-content:space-between;gap:.5rem">
                        <span><strong style="color:var(--text)">{{ $c->user->name ?? '—' }}</strong> <span style="color:var(--text3);font-size:.66rem">{{ $c->created_at->diffForHumans() }}</span></span>
                        @if($c->user_id === auth()->id() || auth()->user()->can('manage workflows'))
                            <button class="btn btn-sm p-0 comment-delete" data-id="{{ $c->id }}" style="color:var(--text3)" title="Delete"><i class="bi bi-x-circle" style="font-size:.8rem"></i></button>
                        @endif
                    </div>
                    <div class="small" style="color:var(--text2);white-space:pre-wrap">{!! \App\Support\MentionParser::highlight($c->body, $mentionable) !!}</div>
                </div>
            @empty
                <div class="text-center py-2 small" style="color:var(--text3)">No comments yet — start the discussion.</div>
            @endforelse
        </div>
        <div class="d-flex gap-2 mt-2">
            <textarea id="commentInput" class="form-control form-control-sm" rows="1" placeholder="Write a comment… (Ctrl+Enter to send) — type @ to mention anyone" maxlength="5000"></textarea>
            <button class="btn btn-sm btn-primary" id="commentSend"><i class="bi bi-send"></i></button>
        </div>
    </div>
</div>
@include('partials.mention-autocomplete')

@if($canManage && $item->isOpen())
    <div class="modal fade" id="editItemModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2 px-3"><h6 class="modal-title fw-bold">Edit Item</h6><button class="btn-close btn-sm" data-bs-dismiss="modal"></button></div>
                <div class="modal-body px-3 py-3">
                    <div class="mb-3"><label class="form-label fw-semibold small">Title</label><input type="text" id="eiTitle" class="form-control form-control-sm" maxlength="200" value="{{ $item->title }}"></div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="form-label fw-semibold small">Priority</label>
                            <select id="eiPriority" class="form-select form-select-sm">
                                @foreach(\App\Models\FlowItem::$priorities as $p)<option value="{{ $p }}" @selected($p === $item->priority)>{{ $p }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-6"><label class="form-label fw-semibold small">Due date</label><input type="date" id="eiDue" class="form-control form-control-sm" value="{{ $item->due_date?->format('Y-m-d') }}"></div>
                    </div>
                    <div><label class="form-label fw-semibold small">Details</label><textarea id="eiDesc" class="form-control form-control-sm" rows="3" maxlength="5000">{{ $item->description }}</textarea></div>
                </div>
                <div class="modal-footer py-2 px-3">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-sm btn-primary" id="eiSave">Save</button>
                </div>
            </div>
        </div>
    </div>
@endif

<div class="card section-card">
    <div class="card-header py-3"><h6 class="fw-bold mb-0">History</h6></div>
    <div class="card-body">
        <div class="tl">
            @foreach($item->transitions as $t)
                <div class="tl-item">
                    <div class="tl-dot"><i class="bi {{ $t->to_stage_id ? 'bi-arrow-right' : ($t->from_stage_id ? 'bi-check' : 'bi-plus') }}"></i></div>
                    <div class="fw-semibold small" style="color:var(--text)">
                        @if(!$t->from_stage_id)
                            Created — entered <strong>{{ $t->toStage->name ?? '—' }}</strong>
                        @elseif($t->to_stage_id)
                            @php $isBack = $t->fromStage && $t->toStage && $t->toStage->position < $t->fromStage->position; @endphp
                            {{ $t->fromStage->name ?? '—' }} <i class="bi {{ $isBack ? 'bi-arrow-left' : 'bi-arrow-right' }} mx-1" style="font-size:.7rem;color:{{ $isBack ? '#dc3545' : 'var(--text3)' }}"></i> <strong>{{ $t->toStage->name ?? '—' }}</strong>
                            @if($isBack)<span class="spill spill-cancelled ms-1" style="font-size:.58rem">sent back</span>@endif
                        @else
                            {{ $t->fromStage->name ?? '—' }} <i class="bi bi-arrow-right mx-1" style="font-size:.7rem;color:var(--text3)"></i> <strong>{{ $item->status === 'Cancelled' ? 'Cancelled' : 'Completed' }}</strong>
                        @endif
                    </div>
                    <div style="font-size:.7rem;color:var(--text3)">{{ $t->movedBy->name ?? 'System' }} · {{ $t->created_at->format('d M Y, h:i A') }}</div>
                    @if($t->note)
                        <div class="small mt-1" style="color:var(--text2)">“{{ $t->note }}”</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection

@push('scripts')
@include('flows.handoff')
@if($canAct)
<script>
    $('#advanceBtn').on('click', function () {
        flowHandoff({{ $item->id }}, 'advance').then(ok => { if (ok) location.reload(); });
    });
    @if($canSendBack)
    $('#sendBackBtn').on('click', function () {
        flowHandoff({{ $item->id }}, 'back').then(ok => { if (ok) location.reload(); });
    });
    @endif
</script>
@endif
<script>
    const ITEM = {{ $item->id }};

    $('#claimBtn').on('click', function () {
        $.post('/flow-items/' + ITEM + '/claim').done(() => location.reload()).fail(x => Swal.fire('Could not claim it', x.responseJSON?.message || 'Please try again.', 'error').then(() => location.reload()));
    });

    // ── Someone else claimed it ──────────────────────────────────────────
    // Everyone else looking at this item hears who took it. A stage worker is
    // sent to My Queue — it is no longer theirs to pick up. Workflow admins and
    // watchers stay (they look after every item) and the page refreshes to show
    // the new owner.
    @if($item->isOpen())
    (function () {
        if (!window.Echo) return;
        const sendToQueue = {{ Js::from(!auth()->user()->canAny(['manage workflows', 'view workflows'])) }};
        let handled = false;

        window.Echo.private('flow-item.' + ITEM).listen('.item.claimed', function (e) {
            if (handled || Number(e.claimed_by.id) === Number(window.CURRENT_USER_ID)) return;
            handled = true;

            const name = $('<div>').text(e.claimed_by.name).html();
            let left = 5;
            Swal.fire({
                icon: 'info',
                title: name + ' claimed this work',
                html: sendToQueue
                    ? 'It is theirs now. Taking you to <strong>My Queue</strong> in <b id="claimCountdown">' + left + '</b>s…'
                    : 'It is theirs to work on now.',
                confirmButtonText: sendToQueue ? 'Go to My Queue now' : 'OK',
                allowOutsideClick: false,
                allowEscapeKey: false,
                timer: 5000,
                timerProgressBar: true,
                didOpen: function () {
                    const tick = setInterval(function () {
                        left = Math.max(0, left - 1);
                        const el = document.getElementById('claimCountdown');
                        if (el) el.textContent = left; else clearInterval(tick);
                    }, 1000);
                },
            }).then(function () {
                if (sendToQueue) {
                    // replace(): Back should not land on an item that is no longer theirs.
                    window.location.replace(e.queue_url);
                } else {
                    window.location.reload();
                }
            });
        });
    })();
    @endif
    $('#releaseBtn').on('click', function () {
        $.post('/flow-items/' + ITEM + '/release').done(() => location.reload()).fail(x => Swal.fire('Error', x.responseJSON?.message || 'Could not release it.', 'error'));
    });

    $(document).on('click', '.att-delete', function () {
        const id = $(this).data('id');
        Swal.fire({ title: 'Remove attachment?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545' })
            .then(r => { if (r.isConfirmed) $.ajax({ url: '/flow-items/' + ITEM + '/attachments/' + id, type: 'DELETE' }).done(() => location.reload()).fail(x => Swal.fire('Error', x.responseJSON?.message || 'Failed', 'error')); });
    });
    @if($canAttach)
    let attKind = 'file';
    $('.att-kind').on('click', function () {
        attKind = $(this).data('kind');
        $('.att-kind').removeClass('active'); $(this).addClass('active');
        // The upload queue lives inside the file wrap, so a batch in progress
        // stays in view only on the File tab — it keeps going either way.
        $('#attFileWrap').toggleClass('d-none', attKind !== 'file');
        $('#attUrlWrap').toggleClass('d-none', attKind !== 'link');
        $('#attNoteWrap').toggleClass('d-none', attKind !== 'note');
        // Files go up the moment they are picked; only links and notes need Add.
        $('#attAddWrap').toggleClass('d-none', attKind === 'file');
    });

    // Re-reads this page and swaps the list in, instead of reloading — a
    // reload would cut off any upload still running.
    function refreshAttachments() {
        return fetch(location.pathname, { headers: { Accept: 'text/html' }, credentials: 'same-origin' })
            .then(r => r.ok ? r.text() : Promise.reject(r))
            .then(function (html) {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                ['#attList', '#attCount'].forEach(function (sel) {
                    const fresh = doc.querySelector(sel), here = document.querySelector(sel);
                    if (fresh && here) here.innerHTML = fresh.innerHTML;
                });
            })
            .catch(() => location.reload());
    }

    makeUploadQueue('#attFile', {
        url: '/flow-items/' + ITEM + '/attachments',
        // A label names one file; picked together, each file keeps its own name.
        data: (file, count) => ({ kind: 'file', title: count === 1 ? $.trim($('#attTitle').val()) : '' }),
        maxBytes: {{ \App\Support\UploadLimit::bytes(51200) }},
        hint: @json('Any file type · up to ' . \App\Support\UploadLimit::label(\App\Support\UploadLimit::bytes(51200)) . ' each — use a link for larger video'),
        onSettled: function () {
            $('#attTitle').val('');
            refreshAttachments();
        },
    });

    $('#attAdd').on('click', function () {
        const fd = new FormData();
        fd.append('kind', attKind);
        fd.append('title', $('#attTitle').val());
        if (attKind === 'link') {
            const u = $.trim($('#attUrl').val());
            if (!u) { Swal.fire('Enter a URL', '', 'info'); return; }
            fd.append('url', u);
        } else {
            const b = $.trim($('#attBody').val());
            if (!b) { Swal.fire('Enter some text', '', 'info'); return; }
            fd.append('body', b);
        }
        const $btn = $(this).prop('disabled', true);
        $.ajax({ url: '/flow-items/' + ITEM + '/attachments', type: 'POST', data: fd, processData: false, contentType: false })
            .done(function () {
                $('#attTitle, #attUrl, #attBody').val('');
                refreshAttachments();
            })
            .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Could not add it', 'error'))
            .always(() => $btn.prop('disabled', false));
    });
    @endif

    // ── Discussion ──────────────────────────────────────────────────
    makeMentionAutocomplete('#commentInput', @json($mentionable->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()));
    $('#commentSend').on('click', function () {
        const body = $.trim($('#commentInput').val());
        if (!body) return;
        $.post('/flow-items/' + ITEM + '/comments', { body })
            .done(() => location.reload())
            .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Failed', 'error'));
    });
    // Ctrl/Cmd+Enter submits — plain Enter is left free for the mention
    // dropdown (and for a normal newline) since the box now spans more than
    // one line.
    $('#commentInput').on('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) $('#commentSend').click(); });
    $(document).on('click', '.comment-delete', function () {
        $.ajax({ url: '/flow-items/' + ITEM + '/comments/' + $(this).data('id'), type: 'DELETE' }).done(() => location.reload());
    });

    @if($canManage && $item->isOpen())
    // ── Edit / Cancel ───────────────────────────────────────────────
    $('#editItemBtn').on('click', () => new bootstrap.Modal('#editItemModal').show());
    $('#eiSave').on('click', function () {
        const title = $.trim($('#eiTitle').val());
        if (!title) return;
        $.ajax({ url: '/flow-items/' + ITEM, type: 'PUT', data: { title, priority: $('#eiPriority').val(), due_date: $('#eiDue').val() || null, description: $('#eiDesc').val() } })
            .done(() => location.reload())
            .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Failed', 'error'));
    });
    $('#cancelItemBtn').on('click', function () {
        Swal.fire({ title: 'Cancel this item?', text: 'It will be withdrawn from the workflow.', input: 'textarea', inputPlaceholder: 'Reason (optional)…', showCancelButton: true, confirmButtonText: 'Cancel item', confirmButtonColor: '#dc3545' })
            .then(res => { if (res.isConfirmed) $.post('/flow-items/' + ITEM + '/cancel', { reason: res.value || '' }).done(() => location.reload()).fail(x => Swal.fire('Error', x.responseJSON?.message || 'Failed', 'error')); });
    });
    @endif
</script>
@endpush
