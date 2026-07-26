@extends('layouts.portal')
@section('title', 'Notifications')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Notifications</h5>
    <button class="btn btn-sm btn-outline-secondary" id="markAllRead">Mark all as read</button>
</div>

<div class="card p-0" id="notifList">
    <div class="text-center py-4" style="color:var(--text3);font-size:.85rem">Loading…</div>
</div>
@endsection

@push('scripts')
<script>
function loadNotifications() {
    $.get('{{ route("portal.notifications.index") }}').done(function (r) {
        if (!r.notifications.length) {
            $('#notifList').html('<div class="text-center py-4" style="color:var(--text3);font-size:.85rem">No notifications yet.</div>');
            return;
        }
        var html = '';
        r.notifications.forEach(function (n) {
            html += '<a href="' + n.url + '" class="d-block px-3 py-3 border-bottom notif-item" data-id="' + n.id + '" '
                + 'style="text-decoration:none;' + (n.read ? '' : 'background:rgba(var(--primary-rgb),.05)') + '">'
                + '<div style="font-size:.85rem;font-weight:600;color:var(--text)">' + n.title + '</div>'
                + '<div style="font-size:.78rem;color:var(--text2)">' + n.message + '</div>'
                + '<div style="font-size:.7rem;color:var(--text3)" class="mt-1">' + n.created_at + '</div>'
                + '</a>';
        });
        $('#notifList').html(html);
    });
}
loadNotifications();
$(document).on('click', '.notif-item', function () {
    $.post('{{ url("portal/notifications") }}/' + $(this).data('id') + '/read');
});
$('#markAllRead').on('click', function () {
    $.post('{{ route("portal.notifications.read-all") }}').done(loadNotifications);
});
</script>
@endpush
