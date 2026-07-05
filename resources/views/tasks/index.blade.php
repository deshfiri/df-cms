@extends('layouts.app')
@section('title', 'Tasks')

@push('styles')
<style>
.task-comment { border-bottom: 1px solid var(--border); padding: 8px 0; }
.task-comment:last-child { border-bottom: none; }
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
    @php $statusCls = ['Pending'=>'spill-pending','In Progress'=>'spill-in-progress','On Hold'=>'spill-hold','Completed'=>'spill-approved','Cancelled'=>'spill-rejected']; @endphp
    @foreach($statusCls as $st => $cls)
    <button class="fpill" data-status="{{ $st }}">
        <span class="spill {{ $cls }}" style="padding:1px 7px;font-size:.65rem">{{ $st }}</span>
        <span class="fcnt">{{ $statusCounts[$st] ?? 0 }}</span>
    </button>
    @endforeach
    <button class="fpill" id="pillOverdue">
        <i class="bi bi-exclamation-triangle" style="font-size:.67rem"></i> Overdue
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
                        <label class="form-label fw-semibold small">Client <span class="text-danger">*</span></label>
                        <select id="taskClient" class="form-select form-select-sm select2">
                            <option value="">Select client…</option>
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
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Start Date</label>
                        <input type="date" id="taskStart" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Due Date</label>
                        <input type="date" id="taskDue" class="form-control form-control-sm">
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
    activeStatus = '';
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
}

$('#newTaskBtn').on('click', resetTaskModal);

$('#saveTaskBtn').on('click', function () {
    const id = $('#taskEditId').val();
    const payload = {
        title: $('#taskTitle').val(),
        client_id: $('#taskClient').val(),
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
        new bootstrap.Modal('#taskModal').show();
    });
});

function loadTaskDetail(id) {
    $.get('/tasks/' + id).done(function (r) {
        const t = r.task;
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
        </div>`;
        $('#taskDetailBody').html(html);

        let attHtml = t.attachments.length ? '' : '<div class="text-muted small">No attachments.</div>';
        t.attachments.forEach(a => {
            attHtml += `<div class="d-flex align-items-center gap-2 p-2 rounded mb-1" style="background:var(--surface2);border:1px solid var(--border)">
                <i class="bi bi-paperclip"></i>
                <a href="/tasks/${t.id}/attachments/${a.id}/download" class="flex-fill text-truncate" style="font-size:.78rem">${a.original_name}</a>
                <button class="btn btn-sm p-0 task-att-delete" data-task="${t.id}" data-id="${a.id}" style="color:var(--text3)"><i class="bi bi-x-circle"></i></button>
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

$(document).on('click', '.task-att-delete', function () {
    const taskId = $(this).data('task'), attId = $(this).data('id');
    $.ajax({ url: '/tasks/' + taskId + '/attachments/' + attId, type: 'DELETE' }).done(() => loadTaskDetail(taskId));
});

$(document).on('click', '.task-delete', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Delete task?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545' })
        .then(r => { if (r.isConfirmed) $.ajax({ url: '/tasks/' + id, type: 'DELETE' }).done(() => window.tTable.ajax.reload()); });
});
</script>
@endpush
