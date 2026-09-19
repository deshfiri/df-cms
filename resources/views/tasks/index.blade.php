@extends('layouts.app')
@section('title', 'Tasks')

@push('styles')
<style>
#tasksTable .task-title-link { color: var(--text); font-weight: 600; text-decoration: none; }
#tasksTable .task-title-link:hover { color: var(--primary); }
#tasksTable .task-title-sub { font-size: .68rem; color: var(--text3); }
</style>
@endpush

@section('content')

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-list-check me-2"></i>Tasks</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">{{ $overdueCount }} overdue</div>
    </div>
    @canany(['manage tasks', 'manage all tasks'])
    <button class="btn btn-sm btn-primary" id="newTaskBtn" data-bs-toggle="modal" data-bs-target="#taskModal">
        <i class="bi bi-plus-lg me-1"></i>New Task
    </button>
    @endcanany
</div>

{{-- Filter pills --}}
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <button class="fpill" data-status="" id="pillAll">All</button>
    @php $statusCls = ['Pending'=>'spill-pending','In Progress'=>'spill-in-progress','On Hold'=>'spill-hold','Submitted'=>'spill-warning','Completed'=>'spill-approved','Cancelled'=>'spill-rejected']; @endphp
    @foreach($statusCls as $st => $cls)
    <button class="fpill" data-status="{{ $st }}">
        <span class="spill {{ $cls }}" style="padding:1px 7px;font-size:.65rem">{{ $st }}</span>
        <span class="fcnt">{{ $statusCounts[$st] ?? 0 }}</span>
    </button>
    @endforeach
    <button class="fpill" id="pillOverdue">
        <i class="bi bi-exclamation-triangle" style="font-size:.67rem"></i> Overdue
    </button>
    {{-- Work this person delegated that has been handed back to them. --}}
    <button class="fpill" id="pillReview">
        <i class="bi bi-clipboard-check" style="font-size:.67rem"></i> Awaiting my review
        @if($awaitingMyReview > 0)
            <span class="fcnt" style="background:var(--c-yellow-bg);color:var(--c-yellow)">{{ $awaitingMyReview }}</span>
        @endif
    </button>

    <div class="ms-auto d-flex gap-2 flex-wrap">
        <select id="filterClient" class="form-select form-select-sm" style="width:180px">
            <option value="">All Clients</option>
            @foreach($clients as $c)
            <option value="{{ $c->id }}">{{ $c->client_name }} ({{ $c->dfid_number }})</option>
            @endforeach
        </select>
        <select id="filterAssigned" class="form-select form-select-sm" style="width:160px">
            <option value="">All Users</option>
            @foreach($users as $u)
            <option value="{{ $u->id }}">{{ $u->name }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            @include('partials.live-counts')
            <table id="tasksTable" class="table table-hover align-middle w-100 mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Client</th>
                        <th>Assigned</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Due</th>
                        <th width="90" class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

@include('tasks.partials.form-modal')
@include('tasks.partials.actions')
@endsection

@push('scripts')
<script>
var activeStatus = '';
var overdueOnly  = false;
var reviewOnly   = false;

$(function () {
    livePillCounts('#tasksTable', {
        extra: [
            { selector: '#pillOverdue', key: 'overdue' },
            { selector: '#pillReview',  key: 'review'  },
        ],
    });
});

function syncPills() {
    $('.fpill').removeClass('active');
    // Checked first: the review pill used to lose its highlight here, because
    // this cleared every pill and then lit "All" instead.
    if (reviewOnly) { $('#pillReview').addClass('active'); return; }
    if (overdueOnly) { $('#pillOverdue').addClass('active'); return; }
    if (!activeStatus) { $('#pillAll').addClass('active'); return; }
    $('.fpill[data-status="' + activeStatus + '"]').addClass('active');
}
syncPills();

$('.fpill[data-status]').on('click', function () {
    activeStatus = $(this).data('status');
    overdueOnly = false;
    reviewOnly = false;
    syncPills();
    window.tTable.ajax.reload();
});
$('#pillOverdue').on('click', function () {
    overdueOnly = !overdueOnly;
    reviewOnly = false;
    activeStatus = '';
    syncPills();
    window.tTable.ajax.reload();
});

// ── Awaiting my review ───────────────────────────────────────────────────
// Work I delegated that somebody has handed back.
$('#pillReview').on('click', function () {
    reviewOnly = !reviewOnly;
    overdueOnly = false;
    activeStatus = '';
    syncPills();
    window.tTable.ajax.reload();
});

// ── Arriving from a link ─────────────────────────────────────────────────
// /tasks?review=1 shows my review queue. Read once, before the table's first
// load, so the list starts in the right state rather than flashing and
// reloading. (/tasks?task=12 is redirected to the task's page by the server.)
(function applyQueryString() {
    var params = new URLSearchParams(window.location.search);

    if (params.get('review') === '1') {
        reviewOnly = true;
        syncPills();
        history.replaceState(null, '', window.location.pathname);
    }
})();
$('#filterClient, #filterAssigned').on('change', function () { window.tTable.ajax.reload(); });

$(function () {
    window.tTable = $('#tasksTable').DataTable({
        processing: true,
        serverSide: true,
        // Newest first.
        order: [[0, 'desc']],
        ajax: {
            url: '{{ route("tasks.index") }}',
            data: function (d) {
                d.status       = activeStatus;
                d.overdue_only = overdueOnly ? 1 : 0;
                d.client_id    = $('#filterClient').val();
                d.assigned_to  = $('#filterAssigned').val();
                d.review       = reviewOnly ? 1 : 0;
            }
        },
        columns: [
            { data: 'number', name: 'id', searchable: false },
            { data: 'title_link', name: 'title' },
            { data: 'client', orderable: false, searchable: false },
            { data: 'assigned', orderable: false, searchable: false },
            { data: 'priority_badge', orderable: false, searchable: false },
            { data: 'status_badge', orderable: false, searchable: false },
            { data: 'due', name: 'due_at', searchable: false },
            { data: 'actions', orderable: false, searchable: false, className: 'text-end pe-3' },
        ]
    });
    $('#tasksTable').on('draw.dt', function () { localizeTimes(this); });
});

// ── Create / edit ────────────────────────────────────────────────────────
$('#newTaskBtn').on('click', resetTaskModal);
$(document).on('click', '.task-edit', function () { openTaskEditor($(this).data('id')); });
$(document).on('task:saved task:changed', function () { window.tTable.ajax.reload(null, false); });

$(document).on('click', '.task-delete', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Delete task?', text: 'Its files, comments and history go with it.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' })
        .then(r => {
            if (!r.isConfirmed) return;
            $.ajax({ url: '/tasks/' + id, type: 'DELETE' })
                .done(() => window.tTable.ajax.reload(null, false))
                .fail(x => { if (x.status !== 403) Swal.fire('Error', x.responseJSON?.message || 'Could not delete the task.', 'error'); });
        });
});
</script>
@endpush
