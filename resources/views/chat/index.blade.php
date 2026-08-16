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

    /* ── Attachments ─────────────────────────────────────────────── */
    .msg-img { display: block; max-width: 220px; max-height: 220px; border-radius: 8px; cursor: pointer; margin-top: .15rem; }
    .msg-file {
        display: flex; align-items: center; gap: .5rem; margin-top: .2rem;
        padding: .4rem .55rem; border-radius: 8px; text-decoration: none;
        background: rgba(0, 0, 0, .12);
    }
    .msg.them .msg-file { background: var(--surface2); color: var(--text); }
    .msg.me .msg-file { color: #fff; }
    .msg-file i { font-size: 1.1rem; flex-shrink: 0; }
    .msg-file-name { font-size: .78rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .msg-file-size { font-size: .64rem; opacity: .75; }

    /* Staged file, before it is sent */
    #attachBar {
        display: flex; align-items: center; gap: .6rem;
        padding: .5rem .8rem; border-top: 1px solid var(--border); background: var(--surface2);
    }
    #attachBar.d-none { display: none !important; }
    #attachThumb { width: 42px; height: 42px; object-fit: cover; border-radius: 6px; }
    #attachIcon { font-size: 1.3rem; color: var(--primary); }
    #attachName { font-size: .78rem; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    #attachSize { font-size: .66rem; color: var(--text3); }
    #attachClear { color: var(--text3); }

    /* Drop target, only visible mid-drag */
    #chatDropZone {
        position: absolute; inset: 0; z-index: 5; display: none;
        place-items: center; text-align: center;
        background: rgba(var(--primary-rgb), .12);
        border: 2px dashed var(--primary); border-radius: var(--radius);
        color: var(--primary); font-size: .85rem; font-weight: 600;
        pointer-events: none;
    }
    #chatDropZone.show { display: grid; }
    #chatDropZone i { font-size: 2rem; display: block; }
    #chatThread { position: relative; }
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
            {{-- Staged attachment, shown between the thread and the input --}}
            <div id="attachBar" class="d-none">
                <img id="attachThumb" alt="" class="d-none">
                <i id="attachIcon" class="bi bi-paperclip d-none"></i>
                <div class="min-w-0 flex-grow-1">
                    <div id="attachName"></div>
                    <div id="attachSize"></div>
                </div>
                <button class="btn btn-sm p-0" id="attachClear" title="Remove"><i class="bi bi-x-lg"></i></button>
            </div>

            <div class="chat-input-row">
                <input type="file" id="msgFile" class="d-none">
                <button class="btn btn-sm" id="msgAttach" title="Attach a file"
                    style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)">
                    <i class="bi bi-paperclip"></i>
                </button>
                <input type="text" id="msgInput" class="form-control form-control-sm" placeholder="Type a message…" autocomplete="off" maxlength="5000">
                <button class="btn btn-sm btn-primary" id="msgSend"><i class="bi bi-send"></i></button>
            </div>

            {{-- Drop target overlay, shown only while dragging a file over the thread --}}
            <div id="chatDropZone"><div><i class="bi bi-cloud-arrow-up"></i><div>Drop to send</div></div></div>
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

    // Lets the message toast in the layout switch threads without a reload,
    // and gives /chat?user={id} somewhere to land.
    window.ChatOpenThread = function (userId, name) {
        if (!userId) return;
        openChat(parseInt(userId, 10), name || 'Chat');
    };

    (function openFromQueryString() {
        var requested = new URLSearchParams(window.location.search).get('user');
        if (!requested) return;

        // Resolve the name from the conversation list once it has loaded, so
        // the header is not left showing a placeholder.
        $.get('/chat/with/' + requested).done(function (r) {
            openChat(parseInt(requested, 10), r.other?.name || 'Chat');
        });
    })();

    function renderMessages(msgs) {
        if (!msgs.length) { $('#msgList').html('<div class="chat-empty" style="flex:1"><div style="font-size:.82rem;color:var(--text3)">No messages yet. Say hello 👋</div></div>'); return; }
        $('#msgList').html('');
        msgs.forEach(appendMessage);
        scrollBottom();
    }

    function attachmentHtml(a) {
        if (!a) return '';

        if (a.is_image) {
            return `<img src="${esc(a.url)}" alt="${esc(a.name)}" class="msg-img" data-full="${esc(a.url)}">`;
        }

        return `<a href="${esc(a.url)}" class="msg-file">
                    <i class="bi bi-file-earmark-arrow-down"></i>
                    <span class="min-w-0">
                        <span class="msg-file-name d-block">${esc(a.name)}</span>
                        <span class="msg-file-size">${esc(a.size)}</span>
                    </span>
                </a>`;
    }

    function appendMessage(m) {
        const mine = m.sender_id === ME;
        const $empty = $('#msgList').find('.chat-empty');
        if ($empty.length) $('#msgList').html('');
        // An image may be sent with no caption, so the body can be empty.
        const text = m.body ? esc(m.body) : '';
        $('#msgList').append(`<div class="msg ${mine ? 'me' : 'them'}">${text}${attachmentHtml(m.attachment)}<div class="msg-meta">${timeOf(m.created_at)}</div></div>`);
        scrollBottom();
    }

    // Open an image full size.
    $(document).on('click', '.msg-img', function () {
        Swal.fire({
            imageUrl: $(this).data('full'),
            imageAlt: 'Attachment',
            showConfirmButton: false,
            showCloseButton: true,
            width: 'auto',
            padding: '0.5rem',
        });
    });

    function scrollBottom() { const el = document.getElementById('msgList'); el.scrollTop = el.scrollHeight; }

    // ── Attachments ──────────────────────────────────────────────────
    const MAX_ATTACHMENT = 20 * 1024 * 1024;   // matches the server's max:20480
    let pendingFile = null;

    function stageFile(file) {
        if (!file) return;
        if (file.size > MAX_ATTACHMENT) {
            Swal.fire('Too large', 'Attachments are limited to 20 MB.', 'info');
            return;
        }

        pendingFile = file;
        $('#attachBar').removeClass('d-none');
        $('#attachName').text(file.name || 'Pasted image');
        $('#attachSize').text(Math.max(1, Math.round(file.size / 1024)) + ' KB');

        // Preview images so it is obvious what is about to be sent.
        if (file.type && file.type.indexOf('image/') === 0) {
            const reader = new FileReader();
            reader.onload = e => $('#attachThumb').attr('src', e.target.result).removeClass('d-none');
            reader.readAsDataURL(file);
            $('#attachIcon').addClass('d-none');
        } else {
            $('#attachThumb').addClass('d-none').removeAttr('src');
            $('#attachIcon').removeClass('d-none');
        }

        $('#msgInput').focus();
    }

    function clearStagedFile() {
        pendingFile = null;
        $('#attachBar').addClass('d-none');
        $('#attachThumb').addClass('d-none').removeAttr('src');
        $('#msgFile').val('');
    }

    $('#msgAttach').on('click', () => $('#msgFile').trigger('click'));
    $('#msgFile').on('change', function () { stageFile(this.files[0]); });
    $('#attachClear').on('click', clearStagedFile);

    // Paste — screenshots arrive as a clipboard image with no filename.
    $('#msgInput').on('paste', function (e) {
        const items = (e.originalEvent.clipboardData || {}).items || [];
        for (let i = 0; i < items.length; i++) {
            if (items[i].kind === 'file') {
                const file = items[i].getAsFile();
                if (file) { e.preventDefault(); stageFile(file); return; }
            }
        }
    });

    // Drag and drop anywhere over the open thread.
    let dragDepth = 0;
    const $thread = $('#chatThread');

    $thread.on('dragenter dragover', function (e) {
        if (!activeUserId) return;
        e.preventDefault(); e.stopPropagation();
        if (e.type === 'dragenter') dragDepth++;
        $('#chatDropZone').addClass('show');
    });
    $thread.on('dragleave', function (e) {
        e.preventDefault(); e.stopPropagation();
        // dragleave also fires moving between child elements, so only hide the
        // overlay once the pointer has actually left the thread.
        if (--dragDepth <= 0) { dragDepth = 0; $('#chatDropZone').removeClass('show'); }
    });
    $thread.on('drop', function (e) {
        e.preventDefault(); e.stopPropagation();
        dragDepth = 0;
        $('#chatDropZone').removeClass('show');
        const files = e.originalEvent.dataTransfer?.files;
        if (files && files.length) stageFile(files[0]);
    });

    // Stop a stray drop elsewhere on the page from navigating away from the app.
    $(document).on('dragover drop', function (e) { e.preventDefault(); });

    // ── Send ─────────────────────────────────────────────────────────
    function send() {
        const body = $.trim($('#msgInput').val());
        if ((!body && !pendingFile) || !activeUserId) return;

        const form = new FormData();
        if (body) form.append('body', body);
        if (pendingFile) form.append('file', pendingFile, pendingFile.name || 'pasted-image.png');

        $('#msgInput').val('');
        const sending = pendingFile;
        clearStagedFile();
        $('#msgSend').prop('disabled', true);

        $.ajax({
            url: '/chat/with/' + activeUserId,
            type: 'POST',
            data: form,
            processData: false,
            contentType: false,
        }).done(function (r) {
            appendMessage(r.message);
            loadConversations();
        }).fail(function (x) {
            // Put the file back so an upload failure doesn't lose it.
            if (sending) stageFile(sending);
            if (body) $('#msgInput').val(body);
            Swal.fire('Error', x.responseJSON?.message || 'Message failed', 'error');
        }).always(function () {
            $('#msgSend').prop('disabled', false);
        });
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
