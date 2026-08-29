@extends('layouts.app')
@section('title', 'Tasks')

@push('styles')
<style>
.task-comment { border-bottom: 1px solid var(--border); padding: 8px 0; }
.task-comment:last-child { border-bottom: none; }

/* ── Quick date picks ─────────────────────────────────────────── */
.when-quick { display: flex; flex-wrap: wrap; gap: 6px; }
.when-chip {
    border: 1px solid var(--border); background: var(--surface2); color: var(--text2);
    border-radius: 999px; font-size: var(--fs-xs); padding: 3px 12px; cursor: pointer;
    transition: background .12s, border-color .12s, color .12s;
}
.when-chip:hover { border-color: var(--primary); color: var(--primary); }
.when-chip.active { background: var(--primary); border-color: var(--primary); color: #fff; }
.when-help { font-size: var(--fs-2xs); color: var(--text3); margin-top: 4px; display: block; }
</style>
@endpush

@section('content')

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-list-check me-2"></i>Tasks</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">{{ $overdueCount }} overdue</div>
    </div>
    @can('manage tasks')
    <button class="btn btn-sm btn-primary" id="newTaskBtn" data-bs-toggle="modal" data-bs-target="#taskModal">
        <i class="bi bi-plus-lg me-1"></i>New Task
    </button>
    @endcan
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

    <div class="ms-auto d-flex gap-2">
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

{{-- Create / Edit Task Modal --}}
<div class="modal fade" id="taskModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2 px-3">
                <h6 class="modal-title fw-bold" id="taskModalTitle"><i class="bi bi-plus-lg me-2"></i>New Task</h6>
                <button class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-3 py-3">
                <input type="hidden" id="taskEditId">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Title <span class="text-danger">*</span></label>
                        <input type="text" id="taskTitle" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">
                            Client <span style="color:var(--text3);font-weight:400">(optional)</span>
                        </label>
                        <select id="taskClient" class="form-select form-select-sm select2">
                            <option value="">No client — internal task</option>
                            @foreach($clients as $c)
                            <option value="{{ $c->id }}">{{ $c->client_name }} ({{ $c->dfid_number }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Assigned To</label>
                        <select id="taskAssigned" class="form-select form-select-sm select2">
                            <option value="">Unassigned</option>
                            @foreach($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Priority</label>
                        <select id="taskPriority" class="form-select form-select-sm">
                            @foreach(\App\Models\Task::$priorities as $p)
                            <option value="{{ $p }}" {{ $p === 'Medium' ? 'selected' : '' }}>{{ $p }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Status</label>
                        <select id="taskStatus" class="form-select form-select-sm">
                            @foreach(\App\Models\Task::$statuses as $s)
                            <option value="{{ $s }}">{{ $s }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Type</label>
                        <select id="taskType" class="form-select form-select-sm">
                            @foreach(\App\Models\Task::$types as $t)
                            <option value="{{ $t }}">{{ $t }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        {{-- Most tasks are for today or tomorrow. Picking that out
                             of a date picker twice is the slow way to say something
                             simple, so the common answers are one click. --}}
                        <label class="form-label fw-semibold small">When</label>
                        <div class="when-quick">
                            <button type="button" class="when-chip" data-when="today">Today</button>
                            <button type="button" class="when-chip" data-when="tomorrow">Tomorrow</button>
                            <button type="button" class="when-chip" data-when="week">In a week</button>
                            <button type="button" class="when-chip" data-when="clear">No dates</button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Start Date</label>
                        <input type="date" id="taskStart" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Due Date</label>
                        <input type="date" id="taskDue" class="form-control form-control-sm">
                        {{-- Same-day is normal, so nothing here nudges the date forward. --}}
                        <span class="when-help" id="dueHint"></span>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Estimated Hours</label>
                        <input type="number" step="0.5" min="0" id="taskEstHours" class="form-control form-control-sm">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Labels</label>
                        <select id="taskLabels" class="form-select form-select-sm select2" multiple>
                            @foreach($labels as $l)
                            <option value="{{ $l->id }}">{{ $l->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Description</label>
                        <textarea id="taskDescription" class="form-control form-control-sm" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2 px-3">
                <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button id="saveTaskBtn" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Save Task</button>
            </div>
        </div>
    </div>
</div>

{{-- Task Detail Modal --}}
<div class="modal fade" id="taskDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2 px-3">
                <h6 class="modal-title fw-bold" id="taskDetailTitle">Task Details</h6>
                <button class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-3 py-3" id="taskDetailBody">
                <div class="text-center py-5"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div></div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
var activeStatus = '';
var overdueOnly  = false;
var reviewOnly   = false;
var currentUserId  = {{ auth()->id() }};
var canManageTasks = @json(auth()->user()->can('manage tasks'));
var revisionReasons = @json($reasonCategories);

function syncPills() {
    $('.fpill').removeClass('active');
    if (overdueOnly) { $('#pillOverdue').addClass('active'); return; }
    if (!activeStatus) { $('#pillAll').addClass('active'); return; }
    $('.fpill[data-status="' + activeStatus + '"]').addClass('active');
}
syncPills();

$('.fpill[data-status]').on('click', function () {
    activeStatus = $(this).data('status');
    overdueOnly = false;
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
    $(this).toggleClass('active', reviewOnly);
    syncPills();
    window.tTable.ajax.reload();
});
$('#filterClient, #filterAssigned').on('change', function () { window.tTable.ajax.reload(); });

$(function () {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#taskModal') });

    window.tTable = $('#tasksTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[6, 'asc']],
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
            { data: 'DT_RowIndex', orderable: false, searchable: false },
            { data: 'title' },
            { data: 'client' },
            { data: 'assigned' },
            { data: 'priority_badge', orderable: false },
            { data: 'status_badge', orderable: false },
            { data: 'due' },
            { data: 'actions', orderable: false, searchable: false, className: 'text-end pe-3' },
        ]
    });
});

// ── Create / Edit ────────────────────────────────────────────────────────
function resetTaskModal() {
    $('#taskEditId').val('');
    $('#taskModalTitle').html('<i class="bi bi-plus-lg me-2"></i>New Task');
    $('#taskTitle,#taskDescription,#taskStart,#taskDue,#taskEstHours').val('');
    $('#taskClient,#taskAssigned').val('').trigger('change');
    $('#taskLabels').val([]).trigger('change');
    $('#taskPriority').val('Medium');
    $('#taskStatus').val('Pending');
    $('#taskType').val('Other');
    syncWhenChips();
}

// ── Quick date picks ─────────────────────────────────────────────────────
// A task for today is the common case, so it should not cost two trips
// through a date picker. Nothing is defaulted on open — a task with no dates
// at all stays valid, and guessing one would be worse than leaving it blank.

/** Local date as YYYY-MM-DD. toISOString() would shift by the UTC offset. */
function isoDate(date) {
    return date.getFullYear() + '-'
        + String(date.getMonth() + 1).padStart(2, '0') + '-'
        + String(date.getDate()).padStart(2, '0');
}

function addDays(days) {
    const d = new Date();
    d.setDate(d.getDate() + days);
    return isoDate(d);
}

/** Light up whichever chip matches what the two date fields currently say. */
function syncWhenChips() {
    const start = $('#taskStart').val();
    const due   = $('#taskDue').val();
    const today = isoDate(new Date());

    let match = '';
    if (!start && !due)                                match = 'clear';
    else if (start === today && due === today)         match = 'today';
    else if (start === today && due === addDays(1))    match = 'tomorrow';
    else if (start === today && due === addDays(7))    match = 'week';

    $('.when-chip').removeClass('active');
    if (match) $(`.when-chip[data-when="${match}"]`).addClass('active');

    // Same-day is a normal answer, so this explains rather than warns.
    $('#dueHint').text(due && due === today ? 'Due today.' : '');
}

$(document).on('click', '.when-chip', function () {
    const today = isoDate(new Date());

    switch ($(this).data('when')) {
        case 'today':    $('#taskStart').val(today); $('#taskDue').val(today); break;
        case 'tomorrow': $('#taskStart').val(today); $('#taskDue').val(addDays(1)); break;
        case 'week':     $('#taskStart').val(today); $('#taskDue').val(addDays(7)); break;
        case 'clear':    $('#taskStart').val(''); $('#taskDue').val(''); break;
    }

    syncWhenChips();
});

// Typing a date by hand should update the chips too, so they never disagree
// with the fields they describe.
$('#taskStart, #taskDue').on('change', syncWhenChips);

$('#newTaskBtn').on('click', resetTaskModal);

$('#saveTaskBtn').on('click', function () {
    const id = $('#taskEditId').val();
    const payload = {
        title: $('#taskTitle').val(),
        // Explicit null rather than '' — an internal task has no client.
        client_id: $('#taskClient').val() || null,
        assigned_to: $('#taskAssigned').val() || null,
        priority: $('#taskPriority').val(),
        status: $('#taskStatus').val(),
        type: $('#taskType').val(),
        start_date: $('#taskStart').val() || null,
        due_date: $('#taskDue').val() || null,
        estimated_hours: $('#taskEstHours').val() || null,
        description: $('#taskDescription').val(),
        label_ids: $('#taskLabels').val() || [],
    };

    const req = id
        ? $.ajax({ url: '/tasks/' + id, type: 'PUT', data: payload })
        : $.post('/tasks', payload);

    req.done(function () {
        bootstrap.Modal.getInstance('#taskModal').hide();
        window.tTable.ajax.reload();
        Swal.fire({ icon: 'success', title: 'Saved', timer: 1200, showConfirmButton: false });
    }).fail(function (r) {
        Swal.fire('Error', r.responseJSON?.message || 'Could not save task.', 'error');
    });
});

// ── View / delete ────────────────────────────────────────────────────────
$(document).on('click', '.task-view', function () {
    const id = $(this).data('id');
    new bootstrap.Modal('#taskDetailModal').show();
    loadTaskDetail(id);
});

$(document).on('click', '.task-edit', function () {
    const id = $(this).data('id');
    $.get('/tasks/' + id).done(function (r) {
        const t = r.task;
        resetTaskModal();
        $('#taskEditId').val(t.id);
        $('#taskModalTitle').html('<i class="bi bi-pencil me-2"></i>Edit Task');
        $('#taskTitle').val(t.title);
        $('#taskDescription').val(t.description);
        $('#taskClient').val(t.client_id).trigger('change');
        $('#taskAssigned').val(t.assigned_to).trigger('change');
        $('#taskPriority').val(t.priority);
        $('#taskStatus').val(t.status);
        $('#taskType').val(t.type);
        $('#taskStart').val(t.start_date ? t.start_date.substring(0, 10) : '');
        $('#taskDue').val(t.due_date ? t.due_date.substring(0, 10) : '');
        $('#taskEstHours').val(t.estimated_hours);
        $('#taskLabels').val(t.labels.map(l => l.id)).trigger('change');
        syncWhenChips();
        new bootstrap.Modal('#taskModal').show();
    });
});

function loadTaskDetail(id) {
    $.get('/tasks/' + id).done(function (r) {
        const t = r.task;
        const revOptions = revisionReasons.map(c => `<option value="${c}">${c}</option>`).join('');
        $('#taskDetailTitle').text(t.title);
        let html = `<div class="mb-3">
            <div class="d-flex gap-2 flex-wrap mb-2">
                <span class="badge" style="background:rgba(var(--primary-rgb),.1);color:var(--primary)">${t.client?.client_name || '-'}</span>
                <span class="spill spill-in-progress">${t.priority}</span>
                <span class="spill spill-pending">${t.status}</span>
                <span class="badge bg-secondary">${t.type}</span>
            </div>
            <p class="small text-muted">${t.description || 'No description.'}</p>
            <div class="small text-muted">Assigned: ${t.assignedUser?.name || 'Unassigned'} · Due: ${t.due_date ? t.due_date.substring(0,10) : '-'}</div>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold small">Attachments</label>
            <div id="taskAttachList" class="mb-2"></div>
            <input type="file" id="taskFileInput" class="form-control form-control-sm">
        </div>
        <div>
            <label class="form-label fw-semibold small">Comments</label>
            <div id="taskCommentList" class="mb-2"></div>
            <div class="d-flex gap-2">
                <input type="text" id="taskCommentInput" class="form-control form-control-sm" placeholder="Add a comment…">
                <button class="btn btn-sm btn-primary" id="taskCommentSend" data-id="${t.id}">Send</button>
            </div>
        </div>
        <div class="mt-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label fw-semibold small mb-0">Revisions</label>
                ${canManageTasks ? `<button class="btn btn-sm btn-outline-warning py-0 px-2" id="taskReviseToggle" style="font-size:.72rem"><i class="bi bi-arrow-counterclockwise me-1"></i>Request Revision</button>` : ''}
            </div>
            <div id="taskReviseForm" class="d-none p-2 rounded mb-2" style="background:var(--surface2);border:1px solid var(--border)">
                <select id="taskReviseReason" class="form-select form-select-sm mb-2">${revOptions}</select>
                <textarea id="taskReviseNote" class="form-control form-control-sm mb-2" rows="2" placeholder="Note (optional) — what needs to change?"></textarea>
                <div class="d-flex justify-content-between align-items-center">
                    <span style="font-size:.68rem;color:var(--text3)">“Employee Mistake” is the only reason counted against the quality KPI.</span>
                    <button class="btn btn-sm btn-warning py-0 px-2" id="taskReviseSubmit" data-id="${t.id}" style="font-size:.72rem">Submit</button>
                </div>
            </div>
            <div id="taskRevisionList"></div>
        </div>`;
        $('#taskDetailBody').html(html);

        let attHtml = t.attachments.length ? '' : '<div class="text-muted small">No attachments.</div>';
        t.attachments.forEach(a => {
            attHtml += `<div class="d-flex align-items-center gap-2 p-2 rounded mb-1" style="background:var(--surface2);border:1px solid var(--border)">
                <i class="bi bi-paperclip"></i>
                <a href="/tasks/${t.id}/attachments/${a.id}/download" class="flex-fill text-truncate" style="font-size:.78rem">${a.original_name}</a>
                ${(a.user_id === currentUserId || canManageTasks) ? `<button class="btn btn-sm p-0 task-att-delete" data-task="${t.id}" data-id="${a.id}" style="color:var(--text3)"><i class="bi bi-x-circle"></i></button>` : ''}
            </div>`;
        });
        $('#taskAttachList').html(attHtml);

        let cmtHtml = t.comments.length ? '' : '<div class="text-muted small">No comments yet.</div>';
        t.comments.forEach(c => {
            cmtHtml += `<div class="task-comment">
                <div style="font-size:.78rem"><strong>${c.user?.name || 'User'}</strong> <span class="text-muted" style="font-size:.68rem">${c.created_at}</span></div>
                <div style="font-size:.79rem">${c.comment}</div>
            </div>`;
        });
        $('#taskCommentList').html(cmtHtml);

        let revHtml = (t.revisions && t.revisions.length) ? '' : '<div class="text-muted small">No revisions.</div>';
        (t.revisions || []).forEach(rv => {
            revHtml += `<div class="p-2 rounded mb-1" style="background:var(--surface2);border:1px solid var(--border)">
                <div style="font-size:.76rem">
                    <span class="spill ${rv.reason_category === 'Employee Mistake' ? 'spill-cancelled' : 'spill-hold'}">${rv.reason_category}</span>
                    <span class="text-muted" style="font-size:.68rem"> by ${rv.requested_by?.name || 'User'} · ${(rv.created_at || '').substring(0, 10)}</span>
                </div>
                ${rv.note ? `<div style="font-size:.78rem;margin-top:2px">${rv.note}</div>` : ''}
            </div>`;
        });
        $('#taskRevisionList').html(revHtml);

        $('#taskFileInput').off('change').on('change', function () {
            const file = this.files[0];
            if (!file) return;
            const fd = new FormData();
            fd.append('file', file);
            $.ajax({ url: '/tasks/' + t.id + '/attachments', type: 'POST', data: fd, processData: false, contentType: false })
             .done(() => loadTaskDetail(t.id));
        });
    });
}

$(document).on('click', '#taskCommentSend', function () {
    const id = $(this).data('id');
    const comment = $.trim($('#taskCommentInput').val());
    if (!comment) return;
    $.post('/tasks/' + id + '/comments', { comment }).done(() => loadTaskDetail(id));
});

$(document).on('click', '#taskReviseToggle', function () {
    $('#taskReviseForm').toggleClass('d-none');
});

$(document).on('click', '#taskReviseSubmit', function () {
    const id = $(this).data('id');
    $.post('/tasks/' + id + '/revisions', {
        reason_category: $('#taskReviseReason').val(),
        note: $.trim($('#taskReviseNote').val()),
    }).done(function () {
        loadTaskDetail(id);
        window.tTable.ajax.reload();
        Swal.fire({ icon: 'success', title: 'Revision requested', timer: 1200, showConfirmButton: false });
    }).fail(function (r) {
        Swal.fire('Error', r.responseJSON?.message || 'Could not request revision.', 'error');
    });
});

$(document).on('click', '.task-att-delete', function () {
    const taskId = $(this).data('task'), attId = $(this).data('id');
    $.ajax({ url: '/tasks/' + taskId + '/attachments/' + attId, type: 'DELETE' }).done(() => loadTaskDetail(taskId));
});

// ── Start / pause work (assignee) ────────────────────────────────────────
// No confirmation: starting your own task is reversible and routine, and a
// dialog on every one would just be in the way.
$(document).on('click', '.task-progress', function () {
    var $btn = $(this).prop('disabled', true);

    $.post('/tasks/' + $btn.data('id') + '/progress', { status: $btn.data('status') })
        .done(function () {
            table.ajax.reload(null, false);
            Swal.fire({
                toast: true, position: 'bottom-end', icon: 'success',
                title: $btn.data('status') === 'In Progress' ? 'Marked in progress' : 'Put on hold',
                showConfirmButton: false, timer: 1600,
            });
        })
        .fail(function (x) {
            $btn.prop('disabled', false);
            Swal.fire('Error', x.responseJSON?.message || 'Could not update the status.', 'error');
        });
});

// ── Submit for review (assignee) ─────────────────────────────────────────
$(document).on('click', '.task-submit', function () {
    var id = $(this).data('id');
    var title = $(this).data('title');

    Swal.fire({
        title: 'Submit for review?',
        html: '<div class="mb-2" style="font-size:.85rem"><strong>' + $('<div>').text(title).html() + '</strong></div>'
            + '<div style="font-size:.8rem;color:var(--text3)">It goes back to whoever asked for it. They accept it or send it back.</div>',
        input: 'textarea',
        inputPlaceholder: 'Anything they should know (optional)',
        inputAttributes: { maxlength: 1000 },
        showCancelButton: true,
        confirmButtonText: 'Submit',
    }).then(function (r) {
        if (!r.isConfirmed) return;

        $.post('/tasks/' + id + '/submit', { note: r.value || '' })
            .done(function () {
                window.tTable.ajax.reload();
                Swal.fire({ icon: 'success', title: 'Submitted for review', timer: 1400, showConfirmButton: false });
            })
            .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Could not submit.', 'error'));
    });
});

// ── Review a submission (requester) ──────────────────────────────────────
$(document).on('click', '.task-review', function () {
    var id = $(this).data('id');
    var title = $(this).data('title');

    var reasonOptions = revisionReasons.map(function (rc) {
        return '<option value="' + $('<div>').text(rc).html() + '">' + $('<div>').text(rc).html() + '</option>';
    }).join('');

    Swal.fire({
        title: 'Review submission',
        html: '<div class="mb-2" style="font-size:.85rem"><strong>' + $('<div>').text(title).html() + '</strong></div>'
            + '<textarea id="revNote" class="form-control form-control-sm mb-2" rows="2" maxlength="1000" placeholder="Note (optional)"></textarea>'
            + '<select id="revReason" class="form-select form-select-sm">' + reasonOptions + '</select>'
            + '<div style="font-size:.72rem;color:var(--text3);margin-top:.35rem;text-align:left">'
            + 'The reason is only used when you send it back.</div>',
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonText: 'Accept',
        confirmButtonColor: '#16a34a',
        denyButtonText: 'Send back',
        denyButtonColor: '#dc3545',
        preConfirm: () => ({ accept: 1, note: $('#revNote').val() }),
        preDeny:    () => ({ accept: 0, note: $('#revNote').val(), reason_category: $('#revReason').val() }),
    }).then(function (r) {
        if (!r.isConfirmed && !r.isDenied) return;

        $.post('/tasks/' + id + '/review', r.value)
            .done(function () {
                window.tTable.ajax.reload();
                Swal.fire({
                    icon: 'success',
                    title: r.isConfirmed ? 'Accepted' : 'Sent back',
                    timer: 1400,
                    showConfirmButton: false,
                });
            })
            .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Could not save the review.', 'error'));
    });
});

$(document).on('click', '.task-delete', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Delete task?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545' })
        .then(r => { if (r.isConfirmed) $.ajax({ url: '/tasks/' + id, type: 'DELETE' }).done(() => window.tTable.ajax.reload()); });
});
</script>
@endpush
