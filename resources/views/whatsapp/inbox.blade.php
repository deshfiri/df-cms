@extends('layouts.app')
@section('title', 'WhatsApp Inbox')

@push('styles')
<style>
    /* Three columns: conversations, thread, customer detail. */
    .wa-wrap {
        display: grid; grid-template-columns: 310px minmax(0, 1fr) 280px;
        gap: 1px; background: var(--border);
        border: 1px solid var(--border); border-radius: var(--radius-md);
        overflow: hidden; height: calc(100vh - 165px); min-height: 480px;
    }
    @media (max-width: 1399.98px) { .wa-wrap { grid-template-columns: 290px minmax(0, 1fr); } .wa-aside { display: none; } }
    @media (max-width: 991.98px) {
        .wa-wrap { grid-template-columns: 1fr; }
        .wa-list { display: none; }
        .wa-wrap:not(.has-active) .wa-list { display: flex; }
        .wa-wrap:not(.has-active) .wa-thread { display: none; }
    }

    .wa-col { background: var(--surface); display: flex; flex-direction: column; min-height: 0; }

    /* ── Conversation list ──────────────────────────────────────── */
    .wa-filters { padding: var(--space-3); border-bottom: 1px solid var(--border); display: flex; flex-direction: column; gap: var(--space-2); }
    .wa-pills { display: flex; gap: 4px; flex-wrap: wrap; }
    .wa-pill {
        border: 1px solid var(--border); background: var(--surface2); color: var(--text2);
        border-radius: 999px; font-size: var(--fs-2xs); padding: 2px 10px; cursor: pointer;
    }
    .wa-pill.active { background: var(--primary); border-color: var(--primary); color: #fff; }

    .wa-items { flex: 1; overflow-y: auto; }
    .wa-item {
        display: flex; gap: var(--space-3); padding: var(--space-3);
        border-bottom: 1px solid var(--border); cursor: pointer;
    }
    .wa-item:hover { background: var(--surface2); }
    .wa-item.active { background: rgba(var(--primary-rgb), .09); }
    .wa-avatar {
        width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0;
        display: grid; place-items: center; font-size: .74rem; font-weight: 700;
        background: var(--surface2); color: var(--text2);
    }
    .wa-item-body { min-width: 0; flex: 1; }
    .wa-item-top { display: flex; justify-content: space-between; gap: 6px; align-items: baseline; }
    .wa-item-name { font-size: .82rem; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .wa-item-time { font-size: .64rem; color: var(--text3); flex-shrink: 0; }
    .wa-item-preview { font-size: .74rem; color: var(--text3); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .wa-item-meta { display: flex; align-items: center; gap: 5px; margin-top: 3px; flex-wrap: wrap; }
    .wa-brand-tag { font-size: .62rem; font-weight: 700; color: var(--primary); background: rgba(var(--primary-rgb), .1); border-radius: 4px; padding: 0 5px; }
    .wa-unread {
        margin-left: auto; background: var(--primary); color: #fff; font-size: .6rem; font-weight: 700;
        border-radius: 999px; padding: 0 6px; min-width: 17px; height: 17px;
        display: inline-flex; align-items: center; justify-content: center;
    }

    /* ── Thread ─────────────────────────────────────────────────── */
    .wa-thread-head { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: var(--space-3); }
    .wa-msgs { flex: 1; overflow-y: auto; padding: var(--space-4); display: flex; flex-direction: column; gap: 7px; background: var(--bg); }

    .wa-msg { max-width: 72%; padding: .45rem .7rem; border-radius: 12px; font-size: .84rem; line-height: 1.4; word-wrap: break-word; }
    .wa-msg.in  { align-self: flex-start; background: var(--surface); border: 1px solid var(--border); color: var(--text); border-bottom-left-radius: 4px; }
    .wa-msg.out { align-self: flex-end; background: var(--primary); color: #fff; border-bottom-right-radius: 4px; }
    .wa-msg-meta { font-size: .62rem; opacity: .75; margin-top: 3px; display: flex; gap: 5px; align-items: center; justify-content: flex-end; }
    .wa-msg.in .wa-msg-meta { justify-content: flex-start; }
    .wa-msg-img { display: block; max-width: 220px; max-height: 220px; border-radius: 8px; cursor: pointer; margin-top: .2rem; }
    .wa-msg-file { display: flex; align-items: center; gap: .45rem; margin-top: .25rem; color: inherit; text-decoration: none; }
    .wa-msg-tpl { font-size: .6rem; font-weight: 700; opacity: .8; text-transform: uppercase; letter-spacing: .04em; }
    .wa-msg.failed { background: var(--c-red-bg); border: 1px solid var(--c-red); color: var(--text); }

    .wa-composer { border-top: 1px solid var(--border); padding: var(--space-3); }
    .wa-composer-row { display: flex; gap: var(--space-2); align-items: center; }
    .wa-blocked {
        font-size: var(--fs-xs); color: var(--text2); line-height: 1.6;
        background: var(--surface2); border: 1px solid var(--border);
        border-left: 3px solid var(--c-yellow);
        border-radius: var(--radius-sm); padding: var(--space-3);
    }

    /* ── Aside ──────────────────────────────────────────────────── */
    .wa-aside { padding: var(--space-4); overflow-y: auto; }
    .wa-aside-label { font-size: var(--fs-2xs); text-transform: uppercase; letter-spacing: .05em; color: var(--text3); font-weight: 700; margin-top: var(--space-4); }
    .wa-aside-value { font-size: var(--fs-sm); color: var(--text); word-break: break-word; }

    .wa-empty { flex: 1; display: grid; place-items: center; text-align: center; color: var(--text3); padding: var(--space-6); }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-whatsapp me-2"></i>WhatsApp Inbox</h4>
        <small style="color:var(--text3)">Customer conversations across every connected brand number.</small>
    </div>
</div>

<div class="wa-wrap" id="waWrap">
    {{-- ── Conversations ─────────────────────────────────────────── --}}
    <div class="wa-col wa-list">
        <div class="wa-filters">
            <input type="text" id="waSearch" class="form-control form-control-sm" placeholder="Search name, phone or message…" autocomplete="off">

            <select id="waBrand" class="form-select form-select-sm">
                <option value="">All brands</option>
                @foreach($brands as $brand)
                    <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                @endforeach
            </select>

            <div class="wa-pills">
                <button class="wa-pill active" data-filter="all">All</button>
                <button class="wa-pill" data-filter="unread">Unread</button>
                <button class="wa-pill" data-filter="mine">Mine</button>
                @if($canAssign)<button class="wa-pill" data-filter="unassigned">Unassigned</button>@endif
            </div>

            <div class="wa-pills">
                <button class="wa-pill active" data-status="">Any status</button>
                @foreach($statuses as $status)
                    <button class="wa-pill" data-status="{{ $status }}">{{ ucfirst($status) }}</button>
                @endforeach
            </div>
        </div>

        <div class="wa-items" id="waItems">
            <div class="wa-empty"><div style="font-size:.82rem">Loading…</div></div>
        </div>
    </div>

    {{-- ── Thread ────────────────────────────────────────────────── --}}
    <div class="wa-col wa-thread">
        <div id="waNoThread" class="wa-empty">
            <div>
                <i class="bi bi-whatsapp" style="font-size:2.4rem"></i>
                <div class="mt-2" style="font-size:.9rem">Pick a conversation to read it.</div>
            </div>
        </div>

        <div id="waThread" class="d-none" style="flex:1;display:flex;flex-direction:column;min-height:0">
            <div class="wa-thread-head">
                <button class="btn btn-sm btn-link d-lg-none p-0 me-1" id="waBack" style="color:var(--text2)">
                    <i class="bi bi-arrow-left"></i>
                </button>
                <div class="wa-avatar" id="waHeadAvatar">?</div>
                <div class="min-w-0 flex-grow-1">
                    <div class="fw-bold" style="font-size:.9rem" id="waHeadName">—</div>
                    {{-- Brand is always visible: an agent must never reply from the wrong one. --}}
                    <div style="font-size:.68rem;color:var(--text3)" id="waHeadBrand">—</div>
                </div>
                <select id="waStatus" class="form-select form-select-sm" style="width:auto">
                    @foreach($statuses as $status)
                        <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="wa-msgs" id="waMsgs"></div>

            <div class="wa-composer">
                <div id="waBlocked" class="wa-blocked d-none"></div>

                <div class="wa-composer-row" id="waComposer">
                    <input type="file" id="waFile" class="d-none">
                    <button class="btn btn-sm" id="waAttach" title="Attach a file"
                        style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)">
                        <i class="bi bi-paperclip"></i>
                    </button>
                    <input type="text" id="waInput" class="form-control form-control-sm" placeholder="Type a reply…" maxlength="4096" autocomplete="off">
                    <button class="btn btn-sm btn-primary" id="waSend"><i class="bi bi-send"></i></button>
                </div>
                <div id="waStaged" class="d-none" style="font-size:.72rem;color:var(--text3);margin-top:.4rem"></div>
            </div>
        </div>
    </div>

    {{-- ── Customer detail ───────────────────────────────────────── --}}
    <div class="wa-col wa-aside" id="waAside">
        <div style="font-size:.8rem;color:var(--text3)">Select a conversation.</div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const CAN_ASSIGN = @json($canAssign);
    const AGENTS     = @json($agents);
    let activeId = null;
    let staged   = null;
    let state    = { filter: 'all', status: '', brand_id: '', search: '' };

    function esc(s) { return $('<span>').text(s == null ? '' : s).html(); }
    function initials(n) { return (n || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase(); }

    // ── Conversation list ────────────────────────────────────────
    function loadConversations() {
        $.get('{{ route('whatsapp.conversations') }}', state).done(function (r) {
            renderConversations(r.conversations);
            $('#waBadgeTotal').text(r.unread_total || '');
        });
    }

    function renderConversations(list) {
        if (!list.length) {
            $('#waItems').html('<div class="wa-empty"><div style="font-size:.82rem">Nothing here.</div></div>');
            return;
        }

        $('#waItems').html(list.map(c => `
            <div class="wa-item ${c.id === activeId ? 'active' : ''}" data-id="${c.id}">
                <div class="wa-avatar">${esc(initials(c.contact_name))}</div>
                <div class="wa-item-body">
                    <div class="wa-item-top">
                        <span class="wa-item-name">${esc(c.contact_name)}</span>
                        <span class="wa-item-time">${esc(c.last_at || '')}</span>
                    </div>
                    <div class="wa-item-preview">${esc(c.preview || '—')}</div>
                    <div class="wa-item-meta">
                        ${c.brand ? `<span class="wa-brand-tag">${esc(c.brand)}</span>` : ''}
                        <span class="spill spill-${esc(c.status)}">${esc(c.status)}</span>
                        ${c.assignee ? `<span style="font-size:.62rem;color:var(--text3)">${esc(c.assignee)}</span>` : ''}
                        ${c.unread ? `<span class="wa-unread">${c.unread}</span>` : ''}
                    </div>
                </div>
            </div>`).join(''));
    }

    $(document).on('click', '.wa-item', function () { openConversation($(this).data('id')); });

    $('#waSearch').on('input', debounce(function () { state.search = $(this).val(); loadConversations(); }, 300));
    $('#waBrand').on('change', function () { state.brand_id = $(this).val(); loadConversations(); });

    $(document).on('click', '.wa-pill[data-filter]', function () {
        $('.wa-pill[data-filter]').removeClass('active');
        $(this).addClass('active');
        state.filter = $(this).data('filter');
        loadConversations();
    });

    $(document).on('click', '.wa-pill[data-status]', function () {
        $('.wa-pill[data-status]').removeClass('active');
        $(this).addClass('active');
        state.status = $(this).data('status') || '';
        loadConversations();
    });

    // ── Thread ───────────────────────────────────────────────────
    function openConversation(id) {
        activeId = id;
        $('#waNoThread').addClass('d-none');
        $('#waThread').removeClass('d-none');
        $('#waWrap').addClass('has-active');
        $('#waMsgs').html('<div class="wa-empty"><div style="font-size:.82rem">Loading…</div></div>');

        $.get('/whatsapp/conversations/' + id).done(function (r) {
            renderThread(r);
            loadConversations();
            subscribeThread(id);
        }).fail(function (x) {
            Swal.fire('Unavailable', x.status === 403
                ? 'You do not have access to that conversation.'
                : 'That conversation could not be opened.', 'error');
        });
    }

    function renderThread(r) {
        const c = r.conversation;

        $('#waHeadAvatar').text(initials(c.contact_name));
        $('#waHeadName').text(c.contact_name);
        $('#waHeadBrand').text((c.brand || '—') + ' · ' + (c.wa_number || 'no number'));
        $('#waStatus').val(c.status);

        $('#waMsgs').html(r.messages.map(messageHtml).join(''));
        scrollBottom();

        // Reply is permitted by the server, not by hiding a button.
        if (r.can_reply) {
            $('#waComposer').removeClass('d-none');
            $('#waBlocked').addClass('d-none');
        } else {
            $('#waComposer').addClass('d-none');
            $('#waBlocked').removeClass('d-none').html(
                '<i class="bi bi-info-circle me-1"></i>' + esc(r.reply_block || 'You cannot reply to this conversation.')
            );
        }

        renderAside(c);
    }

    function messageHtml(m) {
        const out = m.direction === 'outgoing';
        let inner = '';

        if (m.template) inner += `<div class="wa-msg-tpl">Template · ${esc(m.template)}</div>`;

        if (m.media) {
            if (m.media.is_image) {
                inner += `<img src="${esc(m.media.url)}" class="wa-msg-img" data-full="${esc(m.media.url)}" alt="">`;
            } else if (m.media.is_audio) {
                inner += `<audio controls preload="none" src="${esc(m.media.url)}" style="height:34px;max-width:210px"></audio>`;
            } else {
                inner += `<a href="${esc(m.media.url)}" class="wa-msg-file">
                            <i class="bi bi-file-earmark-arrow-down"></i>
                            <span><span style="font-weight:600">${esc(m.media.name || 'File')}</span>
                            <span style="opacity:.75;font-size:.66rem"> ${esc(m.media.size || '')}</span></span>
                          </a>`;
            }
        } else if (m.media_pending) {
            inner += '<div style="opacity:.7;font-size:.74rem"><i class="bi bi-hourglass-split me-1"></i>Downloading attachment…</div>';
        }

        if (m.body) inner += esc(m.body);

        const failed = m.status === 'failed';
        const tick = out ? statusTick(m.status) : '';

        return `<div class="wa-msg ${out ? 'out' : 'in'} ${failed ? 'failed' : ''}" data-id="${m.id}">
                    ${inner}
                    <div class="wa-msg-meta">
                        ${m.agent ? `<span>${esc(m.agent)}</span>` : ''}
                        <span>${timeOf(m.created_at)}</span>${tick}
                    </div>
                    ${failed && m.error ? `<div style="font-size:.66rem;color:var(--c-red);margin-top:3px">${esc(m.error)}</div>` : ''}
                </div>`;
    }

    function statusTick(status) {
        return {
            pending:   ' <i class="bi bi-clock" title="Queued"></i>',
            sent:      ' <i class="bi bi-check" title="Sent"></i>',
            delivered: ' <i class="bi bi-check-all" title="Delivered"></i>',
            read:      ' <i class="bi bi-check-all" style="color:#7fd4ff" title="Read"></i>',
            failed:    ' <i class="bi bi-exclamation-circle" title="Failed"></i>',
        }[status] || '';
    }

    function renderAside(c) {
        let html = `<div class="wa-aside-label" style="margin-top:0">Customer</div>
                    <div class="wa-aside-value">${esc(c.contact_name)}</div>
                    <div class="wa-aside-label">WhatsApp</div>
                    <div class="wa-aside-value">${esc(c.contact_phone)}</div>
                    <div class="wa-aside-label">Brand</div>
                    <div class="wa-aside-value">${esc(c.brand || '—')}</div>
                    <div class="wa-aside-label">Replying from</div>
                    <div class="wa-aside-value">${esc(c.wa_number || '—')}</div>
                    <div class="wa-aside-label">Assigned to</div>`;

        if (CAN_ASSIGN) {
            html += `<select id="waAssign" class="form-select form-select-sm mt-1">
                        <option value="">Unassigned</option>
                        ${AGENTS.map(a => `<option value="${a.id}" ${a.id === c.assignee_id ? 'selected' : ''}>${esc(a.name)}</option>`).join('')}
                     </select>`;
        } else {
            html += `<div class="wa-aside-value">${esc(c.assignee || 'Unassigned')}</div>`;
        }

        html += `<div class="wa-aside-label">Reply window</div>
                 <div class="wa-aside-value" style="font-size:.78rem">${c.window_expires ? 'Closes ' + esc(c.window_expires) : 'Closed — template only'}</div>
                 <div class="wa-aside-label">First seen</div>
                 <div class="wa-aside-value" style="font-size:.78rem">${esc(c.created_at)}</div>`;

        $('#waAside').html(html);
    }

    // ── Actions ──────────────────────────────────────────────────
    $(document).on('change', '#waAssign', function () {
        $.post('/whatsapp/conversations/' + activeId + '/assign', { user_id: $(this).val() || '' })
            .done(loadConversations)
            .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Could not assign it.', 'error'));
    });

    $('#waStatus').on('change', function () {
        $.post('/whatsapp/conversations/' + activeId + '/status', { status: $(this).val() })
            .done(loadConversations)
            .fail(() => Swal.fire('Error', 'Could not change the status.', 'error'));
    });

    $('#waAttach').on('click', () => $('#waFile').trigger('click'));
    $('#waFile').on('change', function () {
        staged = this.files[0] || null;
        $('#waStaged').toggleClass('d-none', !staged)
            .text(staged ? 'Attached: ' + staged.name : '');
    });

    function send() {
        const body = $.trim($('#waInput').val());
        if ((!body && !staged) || !activeId) return;

        const form = new FormData();
        if (body) form.append('body', body);
        if (staged) form.append('file', staged);

        $('#waInput').val('');
        const sending = staged;
        staged = null;
        $('#waStaged').addClass('d-none').text('');
        $('#waSend').prop('disabled', true);

        $.ajax({
            url: '/whatsapp/conversations/' + activeId + '/send',
            type: 'POST', data: form, processData: false, contentType: false,
        }).done(function (r) {
            $('#waMsgs').append(messageHtml(r.message));
            scrollBottom();
            loadConversations();
        }).fail(function (x) {
            if (body) $('#waInput').val(body);
            staged = sending;
            const errors = x.responseJSON?.errors;
            Swal.fire('Not sent', errors ? Object.values(errors)[0][0] : (x.responseJSON?.message || 'The message could not be sent.'), 'error');
        }).always(function () { $('#waSend').prop('disabled', false); });
    }

    $('#waSend').on('click', send);
    $('#waInput').on('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); send(); } });
    $('#waBack').on('click', () => $('#waWrap').removeClass('has-active'));

    $(document).on('click', '.wa-msg-img', function () {
        Swal.fire({ imageUrl: $(this).data('full'), showConfirmButton: false, showCloseButton: true, width: 'auto', padding: '.5rem' });
    });

    // ── Realtime ─────────────────────────────────────────────────
    // Reuses the application's existing Reverb connection; the channels are
    // prefixed whatsapp.* so they can never collide with internal chat.
    let threadChannel = null;

    function subscribeThread(id) {
        if (!window.Echo) return;
        if (threadChannel) window.Echo.leave('whatsapp.conversation.' + threadChannel);

        threadChannel = id;
        window.Echo.private('whatsapp.conversation.' + id)
            .listen('.whatsapp.message.received', function (e) {
                if (e.conversation_id !== activeId) return;
                $.get('/whatsapp/conversations/' + activeId, { mark_read: 1 }).done(renderThread);
            })
            .listen('.whatsapp.conversation.updated', function (e) {
                if (e.conversation_id !== activeId || !e.message) return;
                // A delivery receipt: repaint just that bubble's ticks.
                const $m = $(`.wa-msg[data-id="${e.message.id}"] .wa-msg-meta`);
                if ($m.length) $m.find('i').remove(), $m.append(statusTick(e.message.status));
            });
    }

    if (window.Echo) {
        // Inbox-wide updates, for people who can see every conversation.
        @can('view all whatsapp')
        window.Echo.private('whatsapp.inbox').listen('.whatsapp.conversation.updated', () => loadConversations());
        @endcan
        window.Echo.private('whatsapp.user.{{ auth()->id() }}')
            .listen('.whatsapp.message.received', () => loadConversations());
    }

    // ── Helpers ──────────────────────────────────────────────────
    function scrollBottom() { const el = document.getElementById('waMsgs'); el.scrollTop = el.scrollHeight; }
    function timeOf(iso) { const d = new Date(iso); return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }
    function debounce(fn, ms) { let t; return function () { const a = arguments, c = this; clearTimeout(t); t = setTimeout(() => fn.apply(c, a), ms); }; }

    loadConversations();

    @if($openConversationId)
        openConversation({{ $openConversationId }});
    @endif
});
</script>
@endpush
