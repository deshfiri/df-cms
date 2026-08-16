@extends('layouts.app')
@section('title', 'Chat')

@push('styles')
<style>
    .chat-wrap { display: flex; gap: 0; height: calc(100vh - 150px); min-height: 460px; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; background: var(--surface); box-shadow: var(--shadow-sm); }
    .chat-sidebar { width: 320px; flex-shrink: 0; border-right: 1px solid var(--border); display: flex; flex-direction: column; background: var(--surface); }
    .chat-side-head { padding: .75rem .9rem; border-bottom: 1px solid var(--border); }
    .chat-search { width: 100%; }
    .chat-list { flex: 1; overflow-y: auto; }
    .chat-item { display: flex; align-items: center; gap: .6rem; padding: .6rem .9rem; cursor: pointer; border-bottom: 1px solid var(--border); }
    .chat-item:hover { background: var(--surface2); }
    .chat-item.active { background: rgba(var(--primary-rgb), .1); }
    .chat-avatar { width: 38px; height: 38px; border-radius: 50%; background: var(--surface2); color: var(--text2); display: grid; place-items: center; font-weight: 700; font-size: .8rem; position: relative; flex-shrink: 0; }
    .chat-dot { position: absolute; bottom: 0; right: 0; width: 11px; height: 11px; border-radius: 50%; background: #94a3b8; border: 2px solid var(--surface); }
    .chat-dot.online { background: #22c55e; }
    .chat-item-body { flex: 1; min-width: 0; }
    .chat-item-name { font-weight: 600; font-size: .84rem; color: var(--text); display: flex; justify-content: space-between; gap: .4rem; }
    .chat-item-name span.time { font-size: .66rem; color: var(--text3); font-weight: 400; flex-shrink: 0; }
    .chat-item-last { font-size: .76rem; color: var(--text3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .chat-unread { background: var(--primary); color: #fff; font-size: .66rem; font-weight: 700; border-radius: 999px; padding: 0 6px; min-width: 18px; height: 18px; display: inline-flex; align-items: center; justify-content: center; }
    .chat-main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .chat-main-head { padding: .7rem .95rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: .6rem; }
    .chat-msgs { flex: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; gap: .35rem; background: var(--bg); }
    .msg { max-width: 74%; padding: .45rem .7rem; border-radius: 12px; font-size: .84rem; line-height: 1.35; word-wrap: break-word; }
    .msg.them { align-self: flex-start; background: var(--surface); border: 1px solid var(--border); color: var(--text); border-bottom-left-radius: 4px; }
    .msg.me { align-self: flex-end; background: var(--primary); color: #fff; border-bottom-right-radius: 4px; }
    .msg-meta { font-size: .62rem; opacity: .7; margin-top: 2px; text-align: right; }
    .chat-input-row { padding: .6rem .8rem; border-top: 1px solid var(--border); display: flex; gap: .5rem; background: var(--surface); }
    .chat-typing { font-size: .7rem; color: var(--text3); height: 14px; padding: 0 1rem; }
    .chat-empty { flex: 1; display: grid; place-items: center; color: var(--text3); text-align: center; }
    .chat-day { align-self: center; font-size: .66rem; color: var(--text3); background: var(--surface2); border-radius: 999px; padding: 1px 10px; margin: .25rem 0; }
    @media (max-width: 640px) { .chat-sidebar { width: 100%; } .chat-wrap.has-active .chat-sidebar { display: none; } }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h4 class="page-title mb-0"><i class="bi bi-chat-dots me-2"></i>Chat</h4>
    @can('monitor chats')
        <a href="{{ route('chat.monitor') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye me-1"></i>Monitor</a>
    @endcan
</div>

<div class="chat-wrap" id="chatWrap">
    <div class="chat-sidebar">
        <div class="chat-side-head">
            <input type="text" id="chatSearch" class="form-control form-control-sm chat-search" placeholder="Search people to chat…" autocomplete="off">
        </div>
        <div class="chat-list" id="chatList">
            <div class="text-center py-4 small" style="color:var(--text3)">Loading…</div>
        </div>
    </div>

    <div class="chat-main">
        <div class="chat-empty" id="chatEmpty">
            <div>
                <i class="bi bi-chat-square-text" style="font-size:2.4rem"></i>
                <div class="mt-2" style="font-size:.9rem">Pick a conversation or search someone to start chatting.</div>
            </div>
        </div>

        <div id="chatThread" class="d-none" style="flex:1; display:flex; flex-direction:column; min-height:0">
            <div class="chat-main-head">
                <button class="btn btn-sm btn-link d-sm-none p-0 me-1" id="chatBack" style="color:var(--text2)"><i class="bi bi-arrow-left"></i></button>
                <div class="chat-avatar" id="threadAvatar">?
                    <span class="chat-dot" id="threadDot"></span>
                </div>
                <div>
                    <div class="fw-bold" style="font-size:.9rem" id="threadName">—</div>
                    <div style="font-size:.68rem;color:var(--text3)" id="threadStatus">offline</div>
                </div>
                {{-- Every conversation here is strictly 1:1 (Conversation::between),
                     so the call button applies to all of them. Presence is not a
                     gate: the list can be stale and the backend is the authority. --}}
                <button class="btn btn-sm ms-auto" id="threadCall" title="Start audio call"
                    style="width:34px;height:34px;border-radius:50%;background:var(--surface2);border:1px solid var(--border);color:var(--primary);padding:0">
                    <i class="bi bi-telephone-fill" style="font-size:.85rem"></i>
                </button>
            </div>
            <div class="chat-msgs" id="msgList"></div>
            <div class="chat-typing" id="typingIndicator"></div>
            <div class="chat-input-row">
                <input type="text" id="msgInput" class="form-control form-control-sm" placeholder="Type a message…" autocomplete="off" maxlength="5000">
                <button class="btn btn-sm btn-primary" id="msgSend"><i class="bi bi-send"></i></button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const ME = window.CURRENT_USER_ID;
    let activeUserId = null, activeConvId = null, activeChannel = null, typingTimer = null, searchTimer = null;

    const esc = s => $('<div>').text(s == null ? '' : s).html();
    const initials = n => (n || '?').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
    const timeOf = iso => { try { return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); } catch (e) { return ''; } };

    function updateNavBadge(total) {
        const badge = document.getElementById('chatUnreadBadge');
        if (!badge) return;
        if (total > 0) { badge.textContent = total; badge.style.display = 'inline-flex'; }
        else { badge.textContent = ''; badge.style.display = 'none'; }
    }

    // ── Conversation list ────────────────────────────────────────────
    function loadConversations() {
        $.get('{{ route('chat.conversations') }}').done(function (r) {
            renderConversations(r.conversations);
            updateNavBadge(r.unread_total);
        });
    }

    function renderConversations(list) {
        if (!list.length) { $('#chatList').html('<div class="text-center py-4 small" style="color:var(--text3)">No conversations yet.</div>'); return; }
        let html = '';
        list.forEach(c => {
            const online = window.OnlineUsers.has(c.user_id);
            html += `<div class="chat-item ${c.conversation_id === activeConvId ? 'active' : ''}" data-user="${c.user_id}" data-conv="${c.conversation_id}" data-name="${esc(c.name)}">
                <div class="chat-avatar">${esc(initials(c.name))}<span class="chat-dot ${online ? 'online' : ''}" data-user-dot="${c.user_id}"></span></div>
                <div class="chat-item-body">
                    <div class="chat-item-name"><span>${esc(c.name)}</span><span class="time">${c.last_at || ''}</span></div>
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <div class="chat-item-last">${c.last_from_me ? 'You: ' : ''}${esc(c.last_body || '')}</div>
                        ${c.unread ? `<span class="chat-unread">${c.unread}</span>` : ''}
                    </div>
                </div>
            </div>`;
        });
        $('#chatList').html(html);
    }

    // ── People search (start new chat) ───────────────────────────────
    $('#chatSearch').on('input', function () {
        const q = $.trim($(this).val());
        clearTimeout(searchTimer);
        if (!q) { loadConversations(); return; }
        searchTimer = setTimeout(() => {
            $.get('{{ route('chat.users') }}', { q }).done(function (r) {
                if (!r.users.length) { $('#chatList').html('<div class="text-center py-4 small" style="color:var(--text3)">No people found.</div>'); return; }
                let html = '';
                r.users.forEach(u => {
                    const online = window.OnlineUsers.has(u.id);
                    html += `<div class="chat-item" data-user="${u.id}" data-name="${esc(u.name)}">
                        <div class="chat-avatar">${esc(initials(u.name))}<span class="chat-dot ${online ? 'online' : ''}" data-user-dot="${u.id}"></span></div>
                        <div class="chat-item-body"><div class="chat-item-name"><span>${esc(u.name)}</span></div><div class="chat-item-last">Start a conversation</div></div>
                    </div>`;
                });
                $('#chatList').html(html);
            });
        }, 250);
    });

    // ── Open a conversation ──────────────────────────────────────────
    $(document).on('click', '.chat-item', function () {
        openChat($(this).data('user'), $(this).data('name'));
    });

    function openChat(userId, name) {
        activeUserId = userId;
        $('#chatEmpty').addClass('d-none');
        $('#chatThread').removeClass('d-none');
        $('#chatWrap').addClass('has-active');
        $('#threadName').text(name);
        $('#threadAvatar').html(esc(initials(name)) + '<span class="chat-dot" id="threadDot"></span>');
        $('#msgList').html('<div class="text-center py-4 small" style="color:var(--text3)">Loading…</div>');
        $('#typingIndicator').text('');
        updateThreadPresence();

        $.get('/chat/with/' + userId).done(function (r) {
            activeConvId = r.conversation_id;
            window.ActiveConversationId = activeConvId;
            renderMessages(r.messages);
            subscribeConversation(activeConvId);
            $('.chat-item').removeClass('active');
            $(`.chat-item[data-conv="${activeConvId}"]`).addClass('active').find('.chat-unread').remove();
            updateNavBadge(r.unread_total);
            $('#msgInput').focus();
        });
    }

    function renderMessages(msgs) {
        if (!msgs.length) { $('#msgList').html('<div class="chat-empty" style="flex:1"><div style="font-size:.82rem;color:var(--text3)">No messages yet. Say hello 👋</div></div>'); return; }
        $('#msgList').html('');
        msgs.forEach(appendMessage);
        scrollBottom();
    }

    function appendMessage(m) {
        const mine = m.sender_id === ME;
        const $empty = $('#msgList').find('.chat-empty');
        if ($empty.length) $('#msgList').html('');
        $('#msgList').append(`<div class="msg ${mine ? 'me' : 'them'}">${esc(m.body)}<div class="msg-meta">${timeOf(m.created_at)}</div></div>`);
        scrollBottom();
    }

    function scrollBottom() { const el = document.getElementById('msgList'); el.scrollTop = el.scrollHeight; }

    // ── Send ─────────────────────────────────────────────────────────
    function send() {
        const body = $.trim($('#msgInput').val());
        if (!body || !activeUserId) return;
        $('#msgInput').val('');
        $.post('/chat/with/' + activeUserId, { body }).done(function (r) {
            appendMessage(r.message);
            loadConversations();
        }).fail(function (x) { Swal.fire('Error', x.responseJSON?.message || 'Message failed', 'error'); });
    }
    $('#msgSend').on('click', send);
    $('#msgInput').on('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); send(); return; }
        if (activeChannel) { activeChannel.whisper('typing', { id: ME }); }
    });
    $('#chatBack').on('click', function () { $('#chatWrap').removeClass('has-active'); });

    // ── Realtime: subscribe to the active conversation ───────────────
    function subscribeConversation(convId) {
        if (activeChannel && window.Echo) { window.Echo.leave('conversation.' + activeChannel._convId); }
        if (!window.Echo) return;
        activeChannel = window.Echo.private('conversation.' + convId);
        activeChannel._convId = convId;
        activeChannel.listen('.message.sent', function (e) {
            if (e.conversation_id !== activeConvId) return;
            if (e.sender_id === ME) return; // already shown from the POST response
            appendMessage(e);
            // We're viewing this thread → mark read immediately and sync the badge.
            $.post('/chat/' + activeConvId + '/read').done(function (r) { updateNavBadge(r.unread_total); });
        });
        activeChannel.listenForWhisper('typing', function (e) {
            if (!e || e.id === ME) return;
            $('#typingIndicator').text($('#threadName').text() + ' is typing…');
            clearTimeout(typingTimer);
            typingTimer = setTimeout(() => $('#typingIndicator').text(''), 1800);
        });
    }

    // ── Audio call ───────────────────────────────────────────────────
    // The shared module owns all call state; this only supplies who to ring.
    $('#threadCall').on('click', function () {
        if (!activeUserId || !window.DfcpCall) return;
        window.DfcpCall.start(activeUserId, $('#threadName').text());
    });

    // ── Presence (online dots) ───────────────────────────────────────
    function updateThreadPresence() {
        const online = activeUserId && window.OnlineUsers.has(activeUserId);
        $('#threadDot').toggleClass('online', !!online);
        $('#threadStatus').text(online ? 'online' : 'offline');
    }
    document.addEventListener('online-changed', function () {
        $('.chat-dot[data-user-dot]').each(function () {
            $(this).toggleClass('online', window.OnlineUsers.has($(this).data('user-dot')));
        });
        updateThreadPresence();
    });

    // A message arrived on my personal channel (from the layout listener) → refresh the list.
    document.addEventListener('chat-message', function () { loadConversations(); });

    loadConversations();
});
</script>
@endpush
