@extends('layouts.app')
@section('title', 'Chat Monitor')

@push('styles')
<style>
    .mon-wrap { display: flex; gap: 0; height: calc(100vh - 150px); min-height: 460px; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; background: var(--surface); box-shadow: var(--shadow-sm); }
    .mon-side { width: 340px; flex-shrink: 0; border-right: 1px solid var(--border); display: flex; flex-direction: column; }
    .mon-list { flex: 1; overflow-y: auto; }
    .mon-item { padding: .65rem .9rem; cursor: pointer; border-bottom: 1px solid var(--border); }
    .mon-item:hover { background: var(--surface2); }
    .mon-item.active { background: rgba(var(--primary-rgb), .1); }
    .mon-item-title { font-weight: 600; font-size: .84rem; color: var(--text); }
    .mon-item-sub { font-size: .72rem; color: var(--text3); display: flex; justify-content: space-between; gap: .5rem; }
    .mon-main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .mon-head { padding: .7rem .95rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
    .mon-msgs { flex: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; gap: .35rem; background: var(--bg); }
    .mmsg { max-width: 74%; padding: .45rem .7rem; border-radius: 12px; font-size: .84rem; line-height: 1.35; word-wrap: break-word; }
    .mmsg.a { align-self: flex-start; background: var(--surface); border: 1px solid var(--border); color: var(--text); }
    .mmsg.b { align-self: flex-end; background: rgba(var(--primary-rgb), .12); color: var(--text); }
    .mmsg-who { font-size: .64rem; font-weight: 700; opacity: .75; margin-bottom: 1px; }

    /* Retracted: still readable here, but unmistakably marked. */
    .mmsg.is-deleted { border: 1px dashed #dc3545; background: rgba(220, 53, 69, .07); }
    .mmsg-deleted-tag {
        display: inline-flex; align-items: center; gap: 3px; margin-left: .4rem;
        background: #dc3545; color: #fff; border-radius: 999px;
        font-size: .56rem; font-weight: 700; padding: 1px 6px; text-transform: uppercase;
    }
    .mmsg-img { display: block; max-width: 200px; max-height: 200px; border-radius: 8px; margin-top: .2rem; }
    .mmsg-file {
        display: inline-flex; align-items: center; gap: .35rem; margin-top: .2rem;
        font-size: .75rem; text-decoration: none; color: var(--primary);
    }
    /* Line breaks as they were typed. Scoped to the text, not the bubble, so the
       surrounding markup's indentation is not rendered along with it. */
    .mmsg-text { display: block; white-space: pre-wrap; overflow-wrap: anywhere; }
    .mmsg-quote {
        border-left: 2px solid var(--primary); border-radius: 4px;
        background: rgba(var(--primary-rgb), .08);
        padding: .2rem .4rem; margin-bottom: .25rem;
        font-size: .68rem; line-height: 1.3; color: var(--text3);
    }
    .mmsg-quote-who { font-weight: 700; display: block; color: var(--text2); }
    .mmsg-reacts { display: flex; gap: 4px; margin-top: 3px; font-size: .66rem; color: var(--text3); }

    /* A fixed 340px sidebar beside a thread leaves nothing usable on a phone,
       so stack them and let the list take a capped share of the height. */
    @media (max-width: 768px) {
        .mon-wrap { flex-direction: column; height: auto; min-height: 0; }
        .mon-side { width: 100%; border-right: none; border-bottom: 1px solid var(--border); }
        .mon-list { max-height: 32vh; }
        .mon-msgs { min-height: 50vh; }
        .mmsg { max-width: 92%; }
    }
    .mmsg-meta { font-size: .62rem; opacity: .6; margin-top: 2px; text-align: right; }
    .mon-empty { flex: 1; display: grid; place-items: center; color: var(--text3); text-align: center; }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-eye me-2"></i>Chat Monitor</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Read-only oversight of all conversations &middot; live</div>
    </div>
    <a href="{{ route('chat.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chat-dots me-1"></i>My Chat</a>
</div>

<div class="mon-wrap">
    <div class="mon-side">
        <div class="mon-list" id="monList"><div class="text-center py-4 small" style="color:var(--text3)">Loading…</div></div>
    </div>
    <div class="mon-main">
        <div class="mon-empty" id="monEmpty"><div><i class="bi bi-eye" style="font-size:2.2rem"></i><div class="mt-2" style="font-size:.9rem">Select a conversation to view it.</div></div></div>
        <div id="monThread" class="d-none" style="flex:1; display:flex; flex-direction:column; min-height:0">
            <div class="mon-head">
                <div class="fw-bold" style="font-size:.9rem" id="monTitle">—</div>
                <span class="spill spill-running" id="monLive" style="display:none">● live</span>
            </div>
            <div class="mon-msgs" id="monMsgs"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    let activeConvId = null, activeChannel = null, sideA = null; // sideA = first sender id → left

    const esc = s => $('<div>').text(s == null ? '' : s).html();
    const timeOf = iso => { try { return new Date(iso).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }); } catch (e) { return ''; } };

    function loadList() {
        $.get('{{ route('chat.monitor.conversations') }}').done(function (r) {
            if (!r.conversations.length) { $('#monList').html('<div class="text-center py-4 small" style="color:var(--text3)">No conversations.</div>'); return; }
            let html = '';
            r.conversations.forEach(c => {
                html += `<div class="mon-item ${c.id === activeConvId ? 'active' : ''}" data-id="${c.id}">
                    <div class="mon-item-title">${esc(c.user_one)} <i class="bi bi-arrow-left-right" style="font-size:.7rem;color:var(--text3)"></i> ${esc(c.user_two)}</div>
                    <div class="mon-item-sub"><span>${c.messages_count} messages</span><span>${c.last_at || ''}</span></div>
                </div>`;
            });
            $('#monList').html(html);
        });
    }

    $(document).on('click', '.mon-item', function () {
        activeConvId = $(this).data('id');
        $('.mon-item').removeClass('active'); $(this).addClass('active');
        $('#monEmpty').addClass('d-none'); $('#monThread').removeClass('d-none');
        $('#monMsgs').html('<div class="text-center py-4 small" style="color:var(--text3)">Loading…</div>');
        sideA = null;
        $.get('/chat/monitor/' + activeConvId).done(function (r) {
            $('#monTitle').text(r.participants.join('  ⇄  '));
            $('#monMsgs').html('');
            r.messages.forEach(appendMsg);
            scrollBottom();
            subscribe(activeConvId);
        });
    });

    function appendMsg(m) {
        if (sideA === null) sideA = m.sender_id;
        const side = m.sender_id === sideA ? 'a' : 'b';

        // Retracted messages still show what was said — flagged, not hidden.
        // Participants see only "this message was deleted"; monitoring exists
        // precisely to see past that.
        const deletedTag = m.deleted
            ? '<span class="mmsg-deleted-tag" title="Deleted by the sender — visible to monitors only"><i class="bi bi-trash"></i> deleted</span>'
            : '';

        let attachment = '';
        if (m.attachment) {
            attachment = m.attachment.is_image
                ? `<a href="${esc(m.attachment.url)}" target="_blank" rel="noopener"><img src="${esc(m.attachment.url)}" alt="" class="mmsg-img"></a>`
                : `<a href="${esc(m.attachment.url)}" class="mmsg-file"><i class="bi bi-paperclip"></i> ${esc(m.attachment.name)} <span style="opacity:.7">${esc(m.attachment.size)}</span></a>`;
        }

        const reacts = (m.reactions || []).length
            ? '<div class="mmsg-reacts">' + m.reactions.map(r => `<span>${esc(r.emoji)} ${r.count}</span>`).join('') + '</div>'
            : '';

        // Without the quote a reply reads as a non-sequitur, which defeats the
        // point of reading a conversation back.
        const quote = m.reply_to
            ? `<div class="mmsg-quote"><span class="mmsg-quote-who">${esc(m.reply_to.sender_name)}</span>${esc(m.reply_to.preview || '')}</div>`
            : '';

        $('#monMsgs').append(
            `<div class="mmsg ${side} ${m.deleted ? 'is-deleted' : ''}">
                <div class="mmsg-who">${esc(m.sender_name)}${deletedTag}</div>
                ${quote}${m.body ? `<span class="mmsg-text">${esc(m.body)}</span>` : ''}${attachment}
                <div class="mmsg-meta">${timeOf(m.created_at)}</div>
                ${reacts}
            </div>`
        );
    }
    function scrollBottom() { const el = document.getElementById('monMsgs'); el.scrollTop = el.scrollHeight; }

    function subscribe(convId) {
        if (activeChannel && window.Echo) window.Echo.leave('conversation.' + activeChannel._id);
        $('#monLive').hide();
        if (!window.Echo) return;
        activeChannel = window.Echo.private('conversation.' + convId);
        activeChannel._id = convId;
        activeChannel.listen('.message.sent', function (e) {
            if (e.conversation_id !== activeConvId) return;
            appendMsg(e); scrollBottom();
        });
        $('#monLive').show();
    }

    loadList();
});
</script>
@endpush
