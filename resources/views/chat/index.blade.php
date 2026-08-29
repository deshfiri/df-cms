@extends('layouts.app')
@section('title', 'Chat')

@push('styles')
    <style>
        .chat-wrap {
            display: flex;
            gap: 0;
            height: calc(100vh - 150px);
            min-height: 460px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            background: var(--surface);
            box-shadow: var(--shadow-sm);
        }

        .chat-sidebar {
            width: 320px;
            flex-shrink: 0;
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            background: var(--surface);
        }

        .chat-side-head {
            padding: .75rem .9rem;
            border-bottom: 1px solid var(--border);
        }

        .chat-search {
            width: 100%;
        }

        .chat-list {
            flex: 1;
            overflow-y: auto;
        }

        .chat-item {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .6rem .9rem;
            cursor: pointer;
            border-bottom: 1px solid var(--border);
        }

        .chat-item:hover {
            background: var(--surface2);
        }

        .chat-item.active {
            background: rgba(var(--primary-rgb), .1);
        }

        .chat-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: var(--surface2);
            color: var(--text2);
            display: grid;
            place-items: center;
            font-weight: 700;
            font-size: .8rem;
            position: relative;
            flex-shrink: 0;
        }

        .chat-dot {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 11px;
            height: 11px;
            border-radius: 50%;
            background: #94a3b8;
            border: 2px solid var(--surface);
        }

        .chat-dot.online {
            background: #22c55e;
        }

        .chat-item-body {
            flex: 1;
            min-width: 0;
        }

        .chat-item-name {
            font-weight: 600;
            font-size: .84rem;
            color: var(--text);
            display: flex;
            justify-content: space-between;
            gap: .4rem;
        }

        .chat-item-name span.time {
            font-size: .66rem;
            color: var(--text3);
            font-weight: 400;
            flex-shrink: 0;
        }

        .chat-item-last {
            font-size: .76rem;
            color: var(--text3);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chat-unread {
            background: var(--primary);
            color: #fff;
            font-size: .66rem;
            font-weight: 700;
            border-radius: 999px;
            padding: 0 6px;
            min-width: 18px;
            height: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .chat-main-head {
            padding: .7rem .95rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: .6rem;
        }

        .chat-msgs {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: .35rem;
            background: var(--bg);
        }

        .msg {
            max-width: 74%;
            padding: .45rem .7rem;
            border-radius: 12px;
            font-size: .84rem;
            line-height: 1.35;
            word-wrap: break-word;
        }

        .msg.them {
            align-self: flex-start;
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text);
            border-bottom-left-radius: 4px;
        }

        .msg.me {
            align-self: flex-end;
            background: var(--primary);
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        /* Keeps the line breaks someone actually typed. Scoped to the text itself,
                       never the bubble, so the surrounding markup's indentation is not rendered. */
        .msg-text {
            display: block;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .msg-meta {
            font-size: .62rem;
            opacity: .7;
            margin-top: 2px;
            text-align: right;
        }

        /* align-items:flex-end so the buttons stay level with the last line as the
                       composer grows, rather than floating in the middle of it. */
        .chat-input-row {
            padding: .6rem .8rem;
            border-top: 1px solid var(--border);
            display: flex;
            gap: .5rem;
            background: var(--surface);
            align-items: flex-end;
        }

        #msgInput {
            resize: none;
            overflow-y: auto;
            min-height: 31px;
            /* one line, matching form-control-sm */
            max-height: 132px;
            /* roughly six lines, then it scrolls */
            line-height: 1.4;
        }

        .chat-typing {
            font-size: .7rem;
            color: var(--text3);
            height: 14px;
            padding: 0 1rem;
        }

        .chat-empty {
            flex: 1;
            display: grid;
            place-items: center;
            color: var(--text3);
            text-align: center;
        }

        .chat-day {
            align-self: center;
            font-size: .66rem;
            color: var(--text3);
            background: var(--surface2);
            border-radius: 999px;
            padding: 1px 10px;
            margin: .25rem 0;
        }

        @media (max-width: 640px) {
            .chat-sidebar {
                width: 100%;
            }

            .chat-wrap.has-active .chat-sidebar {
                display: none;
            }
        }

        /* ── Attachments ─────────────────────────────────────────────── */
        .msg-img {
            display: block;
            max-width: 220px;
            max-height: 220px;
            border-radius: 8px;
            cursor: pointer;
            margin-top: .15rem;
        }

        .msg-file {
            display: flex;
            align-items: center;
            gap: .5rem;
            margin-top: .2rem;
            padding: .4rem .55rem;
            border-radius: 8px;
            text-decoration: none;
            background: rgba(0, 0, 0, .12);
        }

        .msg.them .msg-file {
            background: var(--surface2);
            color: var(--text);
        }

        .msg.me .msg-file {
            color: #fff;
        }

        .msg-file i {
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .msg-file-name {
            font-size: .78rem;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .msg-file-size {
            font-size: .64rem;
            opacity: .75;
        }

        /* An attachment removed by the retention policy. Deliberately quiet — it is
                       a note about history, not a broken thing to be fixed. */
        .msg-expired {
            display: flex;
            align-items: center;
            gap: .4rem;
            margin-top: .25rem;
            padding: .35rem .5rem;
            border-radius: 8px;
            border: 1px dashed currentColor;
            opacity: .7;
            font-size: .74rem;
            font-style: italic;
        }

        .msg-expired i {
            font-size: .9rem;
            flex-shrink: 0;
            font-style: normal;
        }

        /* Staged file, before it is sent */
        #attachBar {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .5rem .8rem;
            border-top: 1px solid var(--border);
            background: var(--surface2);
        }

        #attachBar.d-none {
            display: none !important;
        }

        #attachThumb {
            width: 42px;
            height: 42px;
            object-fit: cover;
            border-radius: 6px;
        }

        #attachIcon {
            font-size: 1.3rem;
            color: var(--primary);
        }

        #attachName {
            font-size: .78rem;
            font-weight: 600;
            color: var(--text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        #attachSize {
            font-size: .66rem;
            color: var(--text3);
        }

        #attachClear {
            color: var(--text3);
        }

        /* Drop target, only visible mid-drag */
        #chatDropZone {
            position: absolute;
            inset: 0;
            z-index: 5;
            display: none;
            place-items: center;
            text-align: center;
            background: rgba(var(--primary-rgb), .12);
            border: 2px dashed var(--primary);
            border-radius: var(--radius);
            color: var(--primary);
            font-size: .85rem;
            font-weight: 600;
            pointer-events: none;
        }

        #chatDropZone.show {
            display: grid;
        }

        #chatDropZone i {
            font-size: 2rem;
            display: block;
        }

        #chatThread {
            position: relative;
        }

        /* ── Message actions, reactions, deleted state ───────────────── */
        .msg {
            position: relative;
        }

        .msg-deleted {
            font-style: italic;
            opacity: .7;
        }

        .msg-deleted.me,
        .msg-deleted.them {
            background: var(--surface2);
            color: var(--text3);
            border: 1px dashed var(--border);
        }

        .msg-tools {
            position: absolute;
            top: -10px;
            display: none;
            gap: 2px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 2px;
            box-shadow: var(--shadow-sm);
            z-index: 2;
        }

        .msg.them .msg-tools {
            right: -6px;
        }

        .msg.me .msg-tools {
            left: -6px;
        }

        /* Touch devices have no hover, so the tools stay visible there. */
        @media (hover: hover) {
            .msg:hover .msg-tools {
                display: flex;
            }
        }

        @media (hover: none) {
            .msg-tools {
                display: flex;
            }
        }

        .msg-tool {
            border: none;
            background: none;
            cursor: pointer;
            line-height: 1;
            color: var(--text3);
            font-size: .72rem;
            padding: 3px 5px;
            border-radius: 50%;
        }

        .msg-tool:hover {
            color: var(--primary);
            background: var(--surface2);
        }

        /* ── Quoted message inside a reply ──────────────────────────────── */
        .msg-quote {
            display: block;
            width: 100%;
            text-align: left;
            cursor: pointer;
            border: none;
            border-left: 3px solid currentColor;
            border-radius: 6px;
            padding: .28rem .5rem;
            margin-bottom: .3rem;
            font-size: .74rem;
            line-height: 1.3;
            /* Tinted with the bubble's own colour so it reads as part of it in
                           both directions, without a second palette for me/them. */
            background: rgba(var(--primary-rgb), .09);
            color: inherit;
        }

        .msg.me .msg-quote {
            background: rgba(255, 255, 255, .18);
        }

        .msg-quote:hover {
            filter: brightness(1.08);
        }

        .msg-quote-who {
            font-weight: 700;
            display: block;
            opacity: .95;
        }

        .msg-quote-text {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            opacity: .85;
            word-break: break-word;
        }

        .msg-quote.is-deleted .msg-quote-text {
            font-style: italic;
            opacity: .65;
        }

        /* Where a jumped-to message flashes, so "which one?" is never a guess. */
        @keyframes msgFlash {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(var(--primary-rgb), 0);
            }

            20%,
            60% {
                box-shadow: 0 0 0 3px rgba(var(--primary-rgb), .55);
            }
        }

        .msg-flash {
            animation: msgFlash 1.6s ease-in-out;
        }

        /* ── "Replying to" bar above the composer ───────────────────────── */
        .reply-bar {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .4rem .6rem;
            margin: 0 .55rem;
            border-left: 3px solid var(--primary);
            border-radius: 6px;
            background: var(--surface2);
        }

        .reply-bar-body {
            min-width: 0;
            flex: 1;
        }

        .reply-bar-who {
            font-size: .7rem;
            font-weight: 700;
            color: var(--primary);
        }

        .reply-bar-text {
            font-size: .74rem;
            color: var(--text3);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .msg-reacts {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 5px;
        }

        .react-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text2);
            border-radius: 999px;
            cursor: pointer;
            font-size: .92rem;
            line-height: 1;
            padding: 3px 9px;
            transition: transform .1s ease;
        }

        .react-chip span {
            font-size: .7rem;
            font-weight: 700;
        }

        .react-chip:hover {
            transform: scale(1.08);
        }

        .react-chip.mine {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(var(--primary-rgb), .12);
        }

        /* Fixed, and a child of <body>, so the scrolling message list cannot clip it. */
        .react-picker {
            position: fixed;
            z-index: 21000;
            display: flex;
            gap: 3px;
            padding: 6px 8px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 999px;
            box-shadow: var(--shadow-lg);
        }

        .react-picker button {
            border: none;
            background: none;
            cursor: pointer;
            font-size: 1.5rem;
            line-height: 1;
            padding: 4px 6px;
            border-radius: 50%;
            transition: transform .12s ease;
        }

        .react-picker button:hover {
            background: var(--surface2);
            transform: scale(1.3);
        }

        /* ── Sidebar tabs: conversations vs. call log ─────────────────── */
        .chat-tabs {
            display: flex;
            gap: .3rem;
            margin-bottom: .55rem;
        }

        .chat-tab {
            flex: 1;
            cursor: pointer;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text2);
            font-size: .76rem;
            font-weight: 600;
            padding: .3rem .5rem;
            border-radius: var(--radius);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .35rem;
        }

        .chat-tab.active {
            background: rgba(var(--primary-rgb), .12);
            border-color: var(--primary);
            color: var(--primary);
        }

        .chat-tab .chat-unread {
            background: #dc3545;
        }

        /* ── Call log ─────────────────────────────────────────────────── */
        .call-item {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .6rem .9rem;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
        }

        .call-item:hover {
            background: var(--surface2);
        }

        .call-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            font-size: .78rem;
            background: var(--surface2);
            color: var(--text2);
        }

        .call-icon.missed {
            background: rgba(220, 53, 69, .12);
            color: #dc3545;
        }

        .call-body {
            flex: 1;
            min-width: 0;
        }

        .call-name {
            font-size: .84rem;
            font-weight: 600;
            color: var(--text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .call-name.missed {
            color: #dc3545;
        }

        .call-meta {
            font-size: .72rem;
            color: var(--text3);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .call-time {
            font-size: .66rem;
            color: var(--text3);
            flex-shrink: 0;
            align-self: flex-start;
            padding-top: 2px;
        }

        /* ── Voice messages ───────────────────────────────────────────── */
        .msg-voice {
            display: flex;
            align-items: center;
            gap: .45rem;
            margin-top: .2rem;
        }

        .msg-voice audio {
            height: 34px;
            width: 210px;
            max-width: 100%;
        }

        .msg-voice-len {
            font-size: .64rem;
            opacity: .75;
            flex-shrink: 0;
            font-variant-numeric: tabular-nums;
        }

        /* The dark control strip Chrome paints is unreadable on the sent bubble. */
        .msg.me .msg-voice audio {
            filter: invert(1) hue-rotate(180deg);
        }

        /* Recording bar, shown in place of the input row while the mic is live */
        .chat-record-row {
            padding: .6rem .8rem;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: .6rem;
            background: var(--surface);
        }

        .rec-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #dc3545;
            flex-shrink: 0;
            animation: recPulse 1.1s ease-in-out infinite;
        }

        @keyframes recPulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: .2;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .rec-dot {
                animation: none;
            }
        }

        #recTime {
            font-size: .84rem;
            font-weight: 700;
            color: var(--text);
            font-variant-numeric: tabular-nums;
        }

        .rec-hint {
            flex: 1;
            font-size: .74rem;
            color: var(--text3);
        }

        #msgMic.is-recording {
            background: #dc3545 !important;
            border-color: #dc3545 !important;
            color: #fff !important;
        }
    </style>
@endpush

@section('content')
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <h4 class="page-title mb-0"><i class="bi bi-chat-dots me-2"></i>Chat</h4>
        @can('monitor chats')
            <a href="{{ route('chat.monitor') }}" class="btn btn-sm btn-outline-secondary"><i
                    class="bi bi-eye me-1"></i>Monitor</a>
        @endcan
    </div>

    <div class="chat-wrap" id="chatWrap">
        <div class="chat-sidebar">
            <div class="chat-side-head">
                <div class="chat-tabs">
                    <button type="button" class="chat-tab active" data-tab="chats"><i
                            class="bi bi-chat-dots"></i>Chats</button>
                    <button type="button" class="chat-tab" data-tab="calls">
                        <i class="bi bi-telephone"></i>Calls
                        <span class="chat-unread d-none" id="missedBadge"></span>
                    </button>
                </div>
                <input type="text" id="chatSearch" class="form-control form-control-sm chat-search"
                    placeholder="Search people to chat…" autocomplete="off">
            </div>
            <div class="chat-list" id="chatList">
                <div class="text-center py-4 small" style="color:var(--text3)">Loading…</div>
            </div>
            {{-- Call log: every call in either direction, including the ones nobody
            answered. Hidden until the Calls tab is picked. --}}
            <div class="chat-list d-none" id="callList"></div>
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
                    <button class="btn btn-sm btn-link d-sm-none p-0 me-1" id="chatBack" style="color:var(--text2)"><i
                            class="bi bi-arrow-left"></i></button>
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

                {{-- What this message will quote, shown until it is sent or cancelled --}}
                <div id="replyBar" class="reply-bar d-none">
                    <i class="bi bi-reply-fill" style="color:var(--primary)"></i>
                    <div class="reply-bar-body">
                        <div class="reply-bar-who" id="replyBarWho"></div>
                        <div class="reply-bar-text" id="replyBarText"></div>
                    </div>
                    <button class="btn btn-sm p-0" id="replyCancel" title="Cancel reply" style="color:var(--text3)"><i
                            class="bi bi-x-lg"></i></button>
                </div>

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
                    {{-- A textarea, not an input: Enter sends, Shift+Enter starts a
                    new line. It grows with the message and stops at a few lines,
                    so a long one scrolls rather than swallowing the thread. --}}
                    <textarea id="msgInput" class="form-control form-control-sm" placeholder="Type a message…"
                        autocomplete="off" maxlength="5000" rows="1"></textarea>
                    <button class="btn btn-sm" id="msgMic" title="Record a voice message"
                        style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)">
                        <i class="bi bi-mic-fill"></i>
                    </button>
                    <button class="btn btn-sm btn-primary" id="msgSend"><i class="bi bi-send"></i></button>
                </div>

                {{-- Replaces the input row while recording, so there is no way to
                half-send a message with a live mic still running. --}}
                <div class="chat-record-row d-none" id="recordRow">
                    <span class="rec-dot"></span>
                    <span id="recTime">0:00</span>
                    <span class="rec-hint">Recording — tap send when you're done.</span>
                    <button class="btn btn-sm" id="recCancel" title="Discard recording"
                        style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)">
                        <i class="bi bi-trash"></i>
                    </button>
                    <button class="btn btn-sm btn-primary" id="recSend" title="Send voice message"><i
                            class="bi bi-send"></i></button>
                </div>

                {{-- Drop target overlay, shown only while dragging a file over the thread --}}
                <div id="chatDropZone">
                    <div><i class="bi bi-cloud-arrow-up"></i>
                        <div>Drop to send</div>
                    </div>
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

            // ── Call log ─────────────────────────────────────────────────────
            // A missed call used to leave no trace anywhere in the UI: it was recorded
            // in `calls` and never shown. This is the view of that table.
            const CALL_HISTORY_URL = '{{ route('calls.history') }}';
            const CALL_SEEN_URL = '{{ route('calls.history.seen') }}';

            function setMissedBadge(n) {
                const badge = $('#missedBadge');
                if (n > 0) { badge.text(n).removeClass('d-none'); }
                else { badge.text('').addClass('d-none'); }
            }

            function loadCalls(render) {
                return $.get(CALL_HISTORY_URL).done(function (r) {
                    setMissedBadge(r.missed_unseen);
                    if (render !== false) renderCalls(r.calls);
                });
            }

            function renderCalls(list) {
                if (!list || !list.length) {
                    $('#callList').html('<div class="text-center py-4 small" style="color:var(--text3)">No calls yet.</div>');
                    return;
                }

                let html = '';
                list.forEach(c => {
                    // A missed call is worth spotting at a glance; everything else is
                    // just a log line.
                    const icon = c.missed
                        ? 'bi-telephone-x'
                        : (c.direction === 'incoming' ? 'bi-telephone-inbound' : 'bi-telephone-outbound');
                    const meta = c.duration ? `${esc(c.outcome)} · ${esc(c.duration)}` : esc(c.outcome);

                    html += `<div class="call-item" data-user="${c.other_user_id}" data-name="${esc(c.other_name)}" title="${esc(c.started_exact || '')}">
                                <div class="call-icon ${c.missed ? 'missed' : ''}"><i class="bi ${icon}"></i></div>
                                <div class="call-body">
                                    <div class="call-name ${c.missed ? 'missed' : ''}">${esc(c.other_name)}</div>
                                    <div class="call-meta">${meta}</div>
                                </div>
                                <div class="call-time">${esc(c.started_at || '')}</div>
                            </div>`;
                });
                $('#callList').html(html);
            }

            $(document).on('click', '.chat-tab', function () {
                const tab = $(this).data('tab');
                $('.chat-tab').removeClass('active');
                $(this).addClass('active');

                const onCalls = tab === 'calls';
                $('#chatList').toggleClass('d-none', onCalls);
                $('#callList').toggleClass('d-none', !onCalls);
                // The search box starts conversations; it has nothing to do with the log.
                $('#chatSearch').toggleClass('d-none', onCalls);

                if (!onCalls) { loadConversations(); return; }

                $('#callList').html('<div class="text-center py-4 small" style="color:var(--text3)">Loading…</div>');
                loadCalls().done(function () {
                    // Opening the log is the acknowledgement — clear the badge, but leave
                    // the rows styled as missed so the history still reads correctly.
                    $.post(CALL_SEEN_URL).done(() => setMissedBadge(0));
                });
            });

            // Open the conversation with whoever the call was with.
            $(document).on('click', '.call-item', function () {
                openChat($(this).data('user'), $(this).data('name'));
            });

            // The call panel lives in the layout and owns the call lifecycle; it tells
            // us when a call settles so the log does not go stale behind the user.
            window.addEventListener('dfcp:call-finished', function () {
                const onCalls = $('.chat-tab[data-tab="calls"]').hasClass('active');
                loadCalls(onCalls);
            });

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
                // A pending reply belongs to the thread it was started in.
                cancelReply();
                clearStagedFile();
                // A half-typed message does not follow you into someone else's thread.
                $('#msgInput').val('');
                resetComposerHeight();
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

            /**
             * A file the retention policy removed.
             *
             * Rendered rather than omitted: an attachment-only message would otherwise
             * appear to say nothing at all, and "which file was that?" is exactly what
             * someone reading old history needs to know.
             */
            function expiredAttachmentHtml(e) {
                if (!e) return '';

                return `<span class="msg-expired" title="Removed on ${esc(e.purged_at)}">
                                    <i class="bi bi-clock-history"></i>
                                    <span>${esc(e.name || 'Attachment')} — no longer available</span>
                                </span>`;
            }

            function attachmentHtml(a) {
                if (!a) return '';

                if (a.is_image) {
                    return `<img src="${esc(a.url)}" alt="${esc(a.name)}" class="msg-img" data-full="${esc(a.url)}">`;
                }

                // The length comes from the server: a MediaRecorder clip carries no
                // duration in its header, so the player would otherwise show "Infinity".
                if (a.is_voice) {
                    return `<div class="msg-voice">
                                        <audio controls preload="none" src="${esc(a.url)}"></audio>
                                        <span class="msg-voice-len">${esc(a.duration || '')}</span>
                                    </div>`;
                }

                return `<a href="${esc(a.url)}" class="msg-file">
                                    <i class="bi bi-file-earmark-arrow-down"></i>
                                    <span class="min-w-0">
                                        <span class="msg-file-name d-block">${esc(a.name)}</span>
                                        <span class="msg-file-size">${esc(a.size)}</span>
                                    </span>
                                </a>`;
            }

            const REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

            function reactionsHtml(list) {
                if (!list || !list.length) return '';
                return '<div class="msg-reacts">' + list.map(r => {
                    // The broadcast payload cannot know who "you" are — one event goes
                    // to both participants — so it sends the reactor ids and we resolve
                    // ownership here. The REST response already includes `mine`.
                    const mine = (typeof r.mine === 'boolean') ? r.mine : (r.users || []).indexOf(ME) !== -1;
                    return `<button class="react-chip ${mine ? 'mine' : ''}" data-emoji="${esc(r.emoji)}">${esc(r.emoji)}<span>${r.count}</span></button>`;
                }).join('') + '</div>';
            }

            /**
             * The quote block shown at the top of a reply.
             *
             * `mine` comes from the REST response; the broadcast payload cannot know
             * who "you" are (one event serves both participants) so it sends the
             * original sender's id and ownership is resolved here — same arrangement
             * as reactions above.
             */
            function quoteHtml(q) {
                if (!q) return '';

                const mine = (typeof q.mine === 'boolean') ? q.mine : q.sender_id === ME;
                const who = mine ? 'You' : q.sender_name;

                return `<button type="button" class="msg-quote ${q.deleted ? 'is-deleted' : ''}" data-jump="${q.id}">
                                    <span class="msg-quote-who">${esc(who)}</span>
                                    <span class="msg-quote-text">${esc(q.preview || '')}</span>
                                </button>`;
            }

            function messageHtml(m) {
                const mine = m.sender_id === ME;

                if (m.deleted) {
                    return `<div class="msg ${mine ? 'me' : 'them'} msg-deleted" data-id="${m.id}">
                                        <i class="bi bi-slash-circle me-1"></i>This message was deleted
                                        <div class="msg-meta">${timeOf(m.created_at)}</div>
                                    </div>`;
                }

                // An image may be sent with no caption, so the body can be empty.
                //
                // Wrapped in its own element rather than dropped straight into the
                // bubble: pre-wrap has to apply to the message text alone, or the
                // indentation of the template below would render as whitespace too.
                const text = m.body ? `<span class="msg-text">${esc(m.body)}</span>` : '';

                return `<div class="msg ${mine ? 'me' : 'them'}" data-id="${m.id}">
                                    <div class="msg-tools">
                                        <button class="msg-tool msg-reply" title="Reply"><i class="bi bi-reply"></i></button>
                                        <button class="msg-tool react-open" title="React"><i class="bi bi-emoji-smile"></i></button>
                                        ${m.can_delete ? '<button class="msg-tool msg-del" title="Delete"><i class="bi bi-trash"></i></button>' : ''}
                                    </div>
                                    ${quoteHtml(m.reply_to)}${text}${attachmentHtml(m.attachment)}${expiredAttachmentHtml(m.attachment_expired)}
                                    <div class="msg-meta">${timeOf(m.created_at)}</div>
                                    ${reactionsHtml(m.reactions)}
                                </div>`;
            }

            // ── Reply ────────────────────────────────────────────────────────
            // Which message the next send will quote. Cleared on send, on cancel,
            // and whenever the thread changes — a reply must never follow you into
            // a different conversation.
            let replyTo = null;

            function startReply(id, who, preview) {
                replyTo = { id, who, preview };
                $('#replyBarWho').text('Replying to ' + who);
                $('#replyBarText').text(preview || '');
                $('#replyBar').removeClass('d-none');
                $('#msgInput').focus();
            }

            function cancelReply() {
                replyTo = null;
                $('#replyBar').addClass('d-none');
            }

            /**
             * Read a bubble back into the snippet the reply bar needs.
             *
             * Cosmetic and short-lived — the authoritative preview comes back from the
             * server once the reply is sent. Everything that is not the caption is
             * stripped first, so a file's own name and size cannot pass for body text.
             */
            function summarise($msg) {
                if ($msg.hasClass('msg-deleted')) return 'This message was deleted';

                const $clone = $msg.clone();
                $clone.find('.msg-tools, .msg-meta, .msg-reacts, .msg-quote, .msg-file, .msg-voice').remove();

                const text = $.trim($clone.text());
                if (text) return text;

                // Attachment with no caption — name it by what it is.
                if ($msg.find('.msg-img').length) return '📷 Photo';
                if ($msg.find('.msg-voice').length) return '🎤 Voice message';
                if ($msg.find('.msg-file').length) return '📎 ' + $.trim($msg.find('.msg-file-name').text());

                return '';
            }

            $(document).on('click', '.msg-reply', function () {
                const $msg = $(this).closest('.msg');
                const who = $msg.hasClass('me') ? 'yourself' : $('#threadName').text();

                startReply($msg.data('id'), who, summarise($msg));
            });

            $('#replyCancel').on('click', cancelReply);

            // Escape backs out of a reply before it clears the input.
            $('#msgInput').on('keydown', function (e) {
                if (e.key === 'Escape' && replyTo) { e.preventDefault(); cancelReply(); }
            });

            // Tapping a quote jumps to the original and flashes it.
            $(document).on('click', '.msg-quote', function () {
                const $target = $(`.msg[data-id="${$(this).data('jump')}"]`);

                if (!$target.length) {
                    Swal.fire({
                        toast: true, position: 'top', icon: 'info',
                        title: 'That message is further back than this thread loads.',
                        showConfirmButton: false, timer: 2600,
                    });
                    return;
                }

                $target[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                $target.removeClass('msg-flash');
                // Reflow between removing and re-adding, or the animation will not
                // restart when the same message is jumped to twice in a row.
                void $target[0].offsetWidth;
                $target.addClass('msg-flash');
            });

            function appendMessage(m) {
                const $empty = $('#msgList').find('.chat-empty');
                if ($empty.length) $('#msgList').html('');
                $('#msgList').append(messageHtml(m));
                scrollBottom();
            }

            // ── Delete ───────────────────────────────────────────────────────
            $(document).on('click', '.msg-del', function () {
                const $msg = $(this).closest('.msg');
                Swal.fire({
                    title: 'Delete this message?',
                    text: 'The other person will see that a message was deleted.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Delete',
                    confirmButtonColor: '#dc3545',
                }).then(res => {
                    if (!res.isConfirmed) return;
                    $.ajax({ url: '/chat/messages/' + $msg.data('id'), type: 'DELETE' })
                        .done(() => markDeleted($msg.data('id')))
                        .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Could not delete it.', 'error'));
                });
            });

            function markDeleted(id) {
                $(`.msg[data-id="${id}"]`).each(function () {
                    const time = $(this).find('.msg-meta').text();
                    $(this).addClass('msg-deleted')
                        .html(`<i class="bi bi-slash-circle me-1"></i>This message was deleted<div class="msg-meta">${esc(time)}</div>`);
                });
            }

            // ── Reactions ────────────────────────────────────────────────────
            // The picker is appended to <body> with fixed positioning rather than
            // inside the bubble. The message list is an overflow:auto scroll container,
            // which clips any absolutely positioned child that extends past its edge —
            // so a picker anchored inside the bubble was cut off on the topmost message
            // and half-hidden everywhere else.
            $(document).on('click', '.react-open', function (e) {
                e.stopPropagation();
                const $msg = $(this).closest('.msg');
                $('.react-picker').remove();

                const picker = $('<div class="react-picker"></div>');
                REACTIONS.forEach(emoji => {
                    $('<button type="button"></button>').text(emoji)
                        .on('click', function (ev) {
                            ev.stopPropagation();
                            sendReaction($msg.data('id'), emoji);
                            picker.remove();
                        })
                        .appendTo(picker);
                });

                $('body').append(picker);

                // Sit above the bubble, nudged inside the viewport if it would overhang.
                const box = $msg[0].getBoundingClientRect();
                const width = picker.outerWidth();
                let left = $msg.hasClass('me') ? box.right - width : box.left;
                left = Math.max(8, Math.min(left, window.innerWidth - width - 8));

                let top = box.top - picker.outerHeight() - 8;
                if (top < 8) top = box.bottom + 8;      // no room above: flip below

                picker.css({ left: left + 'px', top: top + 'px' });
            });

            // Clicking an existing chip toggles your own reaction off or on.
            $(document).on('click', '.react-chip', function () {
                sendReaction($(this).closest('.msg').data('id'), $(this).data('emoji'));
            });

            $(document).on('click', () => $('.react-picker').remove());
            // Fixed positioning does not follow the list, so close rather than drift.
            $('#msgList').on('scroll', () => $('.react-picker').remove());
            $(window).on('resize', () => $('.react-picker').remove());

            function sendReaction(id, emoji) {
                $.post('/chat/messages/' + id + '/react', { emoji })
                    .done(r => paintReactions(id, r.reactions))
                    .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Could not react.', 'error'));
            }

            function paintReactions(id, list) {
                const $msg = $(`.msg[data-id="${id}"]`);
                if (!$msg.length) return;
                $msg.find('.msg-reacts').remove();
                if (list && list.length) $msg.append(reactionsHtml(list));
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
                if (replyTo) form.append('reply_to_id', replyTo.id);

                $('#msgInput').val('');
                resetComposerHeight();
                const sending = pendingFile;
                const quoting = replyTo;
                clearStagedFile();
                cancelReply();
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
                    // Put everything back so a failure doesn't lose the message,
                    // the attachment, or what it was answering.
                    if (sending) stageFile(sending);
                    if (quoting) startReply(quoting.id, quoting.who, quoting.preview);
                    if (body) { $('#msgInput').val(body); autoGrow(); }
                    Swal.fire('Error', x.responseJSON?.message || 'Message failed', 'error');
                }).always(function () {
                    $('#msgSend').prop('disabled', false);
                });
            }
            $('#msgSend').on('click', send);

            // ── Voice messages ───────────────────────────────────────────────
            // Recorded with MediaRecorder and sent through the same attachment
            // endpoint as any other file; the duration rides alongside it.
            let recorder = null, recChunks = [], recStartedAt = 0, recTicker = null, recStream = null, recDiscard = false;
            // Whoever was on screen when recording began. Switching threads mid-clip
            // must not redirect the recording to a different person.
            let recTarget = null;
            const REC_MAX_SECONDS = 600;   // server rejects anything longer

            function recSeconds() {
                return Math.round((Date.now() - recStartedAt) / 1000);
            }

            function recClock(s) {
                return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
            }

            /** Pick a container this browser can actually produce. */
            function recMime() {
                const options = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];
                for (const m of options) {
                    if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(m)) return m;
                }
                return '';   // let the browser choose
            }

            function recExtension(mime) {
                if (mime.indexOf('mp4') !== -1) return 'm4a';
                if (mime.indexOf('ogg') !== -1) return 'ogg';
                return 'webm';
            }

            function micProblem(err) {
                const name = (err && err.name) || '';
                if (name === 'NotAllowedError' || name === 'SecurityError') return 'Your browser blocked microphone access. Allow the microphone for this site, then try again.';
                if (name === 'NotFoundError' || name === 'OverconstrainedError') return 'No microphone was detected on this device.';
                if (name === 'NotReadableError') return 'Your microphone is already in use by another application.';
                // getUserMedia is unavailable on a plain http:// origin other than
                // localhost — worth saying outright rather than "recording failed".
                if (name === 'Unsupported') return 'Recording needs a secure connection. Open the app over https, or on localhost.';
                return 'Microphone permission is required to record a voice message.';
            }

            function showRecordUI(on) {
                $('#recordRow').toggleClass('d-none', !on);
                $('.chat-input-row').toggleClass('d-none', on);
                $('#msgMic').toggleClass('is-recording', on);
            }

            function startRecording() {
                if (!activeUserId || recorder) return;

                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
                    Swal.fire('Microphone', micProblem({ name: 'Unsupported' }), 'warning');
                    return;
                }

                // A live call already owns the microphone; grabbing it here would cut
                // the call's audio on some platforms.
                if (window.DfcpCall && window.DfcpCall.isActive()) {
                    Swal.fire('Microphone', 'You are on a call — the microphone is already in use.', 'warning');
                    return;
                }

                navigator.mediaDevices.getUserMedia({ audio: true, video: false }).then(function (stream) {
                    const mime = recMime();
                    recStream = stream;
                    recChunks = [];
                    recDiscard = false;
                    recTarget = activeUserId;
                    recorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);

                    recorder.ondataavailable = e => { if (e.data && e.data.size) recChunks.push(e.data); };
                    recorder.onstop = finishRecording;
                    recorder.start();

                    recStartedAt = Date.now();
                    $('#recTime').text('0:00');
                    showRecordUI(true);

                    recTicker = setInterval(function () {
                        const s = recSeconds();
                        $('#recTime').text(recClock(s));
                        if (s >= REC_MAX_SECONDS) stopRecording(false);   // cap, then send
                    }, 250);
                }).catch(function (err) {
                    Swal.fire('Microphone', micProblem(err), 'error');
                });
            }

            function stopRecording(discard) {
                if (!recorder) return;
                recDiscard = !!discard;
                clearInterval(recTicker);
                recTicker = null;
                try { recorder.stop(); } catch (e) { releaseMic(); }
                showRecordUI(false);
            }

            function releaseMic() {
                if (recStream) recStream.getTracks().forEach(t => { try { t.stop(); } catch (e) { } });
                recStream = null;
                recorder = null;
                recChunks = [];
            }

            function finishRecording() {
                const seconds = Math.min(REC_MAX_SECONDS, recSeconds());
                const type = (recorder && recorder.mimeType) || 'audio/webm';
                const blob = recChunks.length ? new Blob(recChunks, { type }) : null;
                const discard = recDiscard;

                releaseMic();

                if (discard || !blob || !blob.size) return;
                if (seconds < 1) {
                    Swal.fire({ toast: true, position: 'bottom-end', icon: 'info', title: 'Too short to send', showConfirmButton: false, timer: 1800 });
                    return;
                }

                sendVoice(blob, seconds, type);
            }

            function sendVoice(blob, seconds, type) {
                const form = new FormData();
                form.append('file', blob, 'voice-message-' + Date.now() + '.' + recExtension(type));
                form.append('duration', seconds);

                const target = recTarget || activeUserId;

                $.ajax({
                    url: '/chat/with/' + target,
                    type: 'POST',
                    data: form,
                    processData: false,
                    contentType: false,
                }).done(function (r) {
                    // The thread may have been switched while the upload was in flight.
                    if (target === activeUserId) appendMessage(r.message);
                    loadConversations();
                }).fail(function (x) {
                    Swal.fire('Error', x.responseJSON?.message || 'Voice message failed to send', 'error');
                });
            }

            $('#msgMic').on('click', startRecording);
            $('#recSend').on('click', function () { stopRecording(false); });
            $('#recCancel').on('click', function () { stopRecording(true); });
            /**
             * Grow the composer to fit what is in it.
             *
             * Height is reset to auto first: without that, scrollHeight only ever
             * reports the current (already grown) height, so the box could never shrink
             * back down after text is deleted.
             */
            function autoGrow() {
                const el = document.getElementById('msgInput');
                if (!el) return;
                el.style.height = 'auto';
                el.style.height = Math.min(el.scrollHeight, 132) + 'px';
            }

            function resetComposerHeight() {
                const el = document.getElementById('msgInput');
                if (el) el.style.height = '';
            }

            $('#msgInput').on('input', autoGrow);

            $('#msgInput').on('keydown', function (e) {
                // Enter sends; Shift+Enter (or Ctrl/Cmd+Enter) starts a new line. The
                // browser inserts the newline itself, so those cases just fall through.
                if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    send();
                    return;
                }

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
                // A delete or reaction after the fact.
                activeChannel.listen('.message.updated', function (e) {
                    if (!e) return;
                    if (e.deleted) { markDeleted(e.id); return; }
                    paintReactions(e.id, e.reactions);
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
            // Badge only — the log itself is rendered when the tab is opened.
            loadCalls(false);
        });
    </script>
@endpush