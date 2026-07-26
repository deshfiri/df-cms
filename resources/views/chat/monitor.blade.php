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
        $('#monMsgs').append(`<div class="mmsg ${side}"><div class="mmsg-who">${esc(m.sender_name)}</div>${esc(m.body)}<div class="mmsg-meta">${timeOf(m.created_at)}</div></div>`);
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
