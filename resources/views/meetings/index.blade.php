@extends('layouts.app')
@section('title', 'Meetings')

@push('styles')
<style>
.meeting-card {
    border-left: 3px solid var(--primary);
    border-radius: 0 8px 8px 0;
    background: var(--surface);
    border-top: 1px solid var(--border);
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    padding: 14px 16px;
    transition: box-shadow .15s;
}
.meeting-card:hover { box-shadow: var(--shadow); }
.meeting-card.status-completed  { border-left-color: #2563eb; }
.meeting-card.status-cancelled  { border-left-color: #dc2626; }
.meeting-card.status-rescheduled{ border-left-color: #d97706; }
.meeting-card.status-scheduled  { border-left-color: var(--primary); }
.meeting-card.status-pending    { border-left-color: #64748b; }
.meeting-card.status-no-show    { border-left-color: #dc2626; }
.meeting-card.overdue           { border-left-color: #dc2626; }

.day-badge {
    width: 48px; text-align: center; flex-shrink: 0;
}
.day-badge .day-num { font-size: 1.3rem; font-weight: 700; color: var(--primary); line-height: 1; }
.day-badge .day-mon { font-size: .66rem; color: var(--text3); text-transform: uppercase; letter-spacing: .04em; }

.filter-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 20px; font-size: .73rem; font-weight: 500;
    border: 1px solid var(--border); background: var(--surface); color: var(--text2);
    cursor: pointer; transition: all .1s;
}
.filter-chip.active, .filter-chip:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
</style>
@endpush

@section('content')

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0">Meetings</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">All client meetings</div>
    </div>
</div>

{{-- Stats row --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="card text-center py-3">
            <div class="fw-bold fs-4 mb-0" style="color:var(--text3)">{{ $upcomingCount }}</div>
            <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Upcoming</div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card text-center py-3">
            <div class="fw-bold fs-4 mb-0 c-green">{{ $todayCount }}</div>
            <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Today</div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card text-center py-3">
            <div class="fw-bold fs-4 mb-0 c-red">{{ $overdueCount }}</div>
            <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Overdue</div>
        </div>
    </div>
</div>

{{-- Filters --}}
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <div class="row g-2 align-items-end">
            <div class="col-sm-3">
                <label class="form-label small mb-1">Status</label>
                <select id="filterStatus" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="Pending">Pending</option>
                    <option value="Scheduled">Scheduled</option>
                    <option value="Completed">Completed</option>
                    <option value="Cancelled">Cancelled</option>
                    <option value="No Show">No Show</option>
                    <option value="Rescheduled">Rescheduled</option>
                </select>
            </div>
            <div class="col-sm-3">
                <label class="form-label small mb-1">Type</label>
                <select id="filterType" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="in_person">In Person</option>
                    <option value="phone">Phone Call</option>
                    <option value="video">Video Call</option>
                    <option value="online">Online</option>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" id="filterFrom" class="form-control form-control-sm">
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" id="filterTo" class="form-control form-control-sm">
            </div>
            <div class="col-sm-2 d-flex gap-2">
                <button id="applyFilter" class="btn btn-sm btn-primary flex-fill"><i class="bi bi-funnel me-1"></i>Filter</button>
                <button id="clearFilter" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
    </div>
</div>

{{-- Meetings list --}}
<div id="allMeetingsList">
    <div class="text-center py-5">
        <div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div>
    </div>
</div>

<div id="meetingsPagination" class="d-flex justify-content-center mt-3"></div>

@endsection

@push('scripts')
<script>
var currentPage = 1;

function loadAllMeetings(page) {
    page = page || 1;
    currentPage = page;
    var params = {
        status: $('#filterStatus').val(),
        type:   $('#filterType').val(),
        from:   $('#filterFrom').val(),
        to:     $('#filterTo').val(),
        page:   page,
    };
    $('#allMeetingsList').html('<div class="text-center py-5"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div></div>');
    $.get('/meetings', params, function(res) {
        if (!res.data || !res.data.length) {
            $('#allMeetingsList').html('<div class="text-center py-5 empty-state"><i class="bi bi-calendar-x" style="font-size:2.5rem;color:var(--text3)"></i><div class="mt-2" style="color:var(--text3)">No meetings found</div></div>');
            $('#meetingsPagination').html('');
            return;
        }
        var html = '<div class="d-flex flex-column gap-2">';
        res.data.forEach(function(m) { html += renderMeetingCard(m); });
        html += '</div>';
        $('#allMeetingsList').html(html);
        renderPagination(res);
    }).fail(function() {
        $('#allMeetingsList').html('<div class="text-center py-4 small c-red"><i class="bi bi-exclamation-circle me-1"></i>Failed to load</div>');
    });
}

function esc(str) { return str ? String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : ''; }

var statusCls = {
    'Pending':     'spill-pending',
    'Scheduled':   'spill-running',
    'Completed':   'spill-completed',
    'Cancelled':   'spill-cancelled',
    'No Show':     'spill-rejected',
    'Rescheduled': 'spill-warning',
};
var typeIcons = { in_person: 'bi-person-fill', phone: 'bi-telephone-fill', video: 'bi-camera-video-fill', online: 'bi-globe' };

function statusSlug(status) { return (status || '').toLowerCase().replace(/\s+/g, '-'); }

function renderMeetingCard(m) {
    var sc    = statusCls[m.status] || 'spill-hold';
    var tIcon = typeIcons[m.type] || 'bi-calendar-event';
    var overdue = m.is_overdue ? '<span class="spill spill-cancelled ms-1" style="font-size:.6rem">Overdue</span>' : '';
    var dateParts = m.scheduled_date ? m.scheduled_date.split(' ') : ['', '', ''];
    var day = dateParts[0], mon = dateParts.slice(1).join(' ');

    var clientLink = '<a href="/clients/' + m.client_id + '" style="color:var(--primary);font-weight:600">' + esc(m.client_name) + '</a>' +
        (m.client_dfid ? ' <span style="font-size:.68rem;color:var(--text3);font-family:monospace">' + esc(m.client_dfid) + '</span>' : '');

    var locationHtml = m.location ? '<span><i class="bi bi-geo-alt me-1"></i>' + esc(m.location) + '</span>' : '';
    var linkHtml = m.join_url ? '<a href="' + esc(m.join_url) + '" target="_blank" style="color:var(--primary)"><i class="bi bi-box-arrow-up-right me-1"></i>Join</a>' : '';
    var notesHtml = (m.notes && m.status === 'Completed') ? '<div class="mt-2 p-2 rounded" style="background:var(--surface2);border:1px solid var(--border);font-size:.74rem;color:var(--text2)"><i class="bi bi-journal-text me-1"></i>' + esc(m.notes) + '</div>' : '';

    return '<div class="meeting-card status-' + statusSlug(m.status) + (m.is_overdue ? ' overdue' : '') + '">' +
        '<div class="d-flex align-items-start gap-3">' +
            '<div class="day-badge">' +
                '<div class="day-num">' + day + '</div>' +
                '<div class="day-mon">' + mon + '</div>' +
            '</div>' +
            '<div class="flex-grow-1 min-w-0">' +
                '<div class="d-flex align-items-center gap-2 flex-wrap mb-1">' +
                    '<span class="fw-semibold" style="font-size:.88rem">' + esc(m.title) + '</span>' +
                    '<span class="spill ' + sc + '" style="font-size:.63rem">' + m.status + '</span>' +
                    overdue +
                '</div>' +
                '<div class="mb-1" style="font-size:.78rem">' + clientLink + '</div>' +
                '<div class="d-flex align-items-center gap-3 flex-wrap" style="font-size:.73rem;color:var(--text3)">' +
                    '<span><i class="bi ' + tIcon + ' me-1"></i>' + m.type_label + '</span>' +
                    '<span><i class="bi bi-clock me-1"></i>' + m.scheduled_time + ' &middot; ' + m.duration_human + '</span>' +
                    locationHtml + linkHtml +
                '</div>' +
                notesHtml +
                '<div class="mt-1" style="font-size:.68rem;color:var(--text3)">Scheduled by ' + esc(m.created_by_name || '') + '</div>' +
            '</div>' +
            '<div>' +
                '<a href="/clients/' + m.client_id + '?tab=meetings" class="btn btn-sm py-0 px-2" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2);font-size:.72rem">' +
                    '<i class="bi bi-arrow-up-right me-1"></i>View' +
                '</a>' +
            '</div>' +
        '</div>' +
    '</div>';
}

function renderPagination(res) {
    if (res.last_page <= 1) { $('#meetingsPagination').html(''); return; }
    var html = '<nav><ul class="pagination pagination-sm">';
    for (var i = 1; i <= res.last_page; i++) {
        html += '<li class="page-item' + (i === res.current_page ? ' active' : '') + '">' +
            '<button class="page-link page-btn" data-page="' + i + '">' + i + '</button></li>';
    }
    html += '</ul></nav>';
    $('#meetingsPagination').html(html);
}

$(document).on('click', '.page-btn', function() { loadAllMeetings($(this).data('page')); });
$('#applyFilter').on('click', function() { loadAllMeetings(1); });
$('#clearFilter').on('click', function() {
    $('#filterStatus,#filterType,#filterFrom,#filterTo').val('');
    loadAllMeetings(1);
});

$(function() { loadAllMeetings(1); });
</script>
@endpush
