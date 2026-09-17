{{--
    The create / edit task dialog, shared by the task list and the task page.

    Needs $clients, $users and $labels. Open it with openTaskEditor(id) to edit,
    or show #taskModal after resetTaskModal() to create. A successful save
    triggers `task:saved` on the document with the saved task, and each page
    decides what to refresh.
--}}
@push('styles')
<style>
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
                        <select id="taskClient" class="form-select form-select-sm task-select2">
                            <option value="">No client — internal task</option>
                            @foreach($clients as $c)
                            <option value="{{ $c->id }}">{{ $c->client_name }} ({{ $c->dfid_number }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Assigned To</label>
                        <select id="taskAssigned" class="form-select form-select-sm task-select2">
                            <option value="">Unassigned</option>
                            @foreach($users as $u)
                                {{-- Nobody assigns a task to themselves (enforced in TaskService). --}}
                                @if((int) $u->id === (int) auth()->id())
                                    <option value="{{ $u->id }}" disabled>{{ $u->name }} (you — can't assign to yourself)</option>
                                @else
                                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                                @endif
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
                        <label class="form-label fw-semibold small">Due</label>
                        <div class="d-flex gap-1">
                            <input type="date" id="taskDue" class="form-control form-control-sm">
                            {{-- Optional. Left blank, the task is due by the end of that day. --}}
                            <input type="time" id="taskDueTime" class="form-control form-control-sm" style="max-width:110px" title="Due time (optional)">
                        </div>
                        {{-- Same-day is normal, so nothing here nudges the date forward. --}}
                        <span class="when-help" id="dueHint"></span>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Estimated Hours</label>
                        <input type="number" step="0.5" min="0" id="taskEstHours" class="form-control form-control-sm">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Labels</label>
                        <select id="taskLabels" class="form-select form-select-sm task-select2" multiple>
                            @foreach($labels as $l)
                            <option value="{{ $l->id }}">{{ $l->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Description</label>
                        <textarea id="taskDescription" class="form-control form-control-sm" rows="3"></textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="taskRequiresAttachment">
                            <label class="form-check-label small" for="taskRequiresAttachment">
                                Submission must include a file
                                <span style="color:var(--text3)">— the assignee cannot submit without attaching their work</span>
                            </label>
                        </div>
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

@push('scripts')
<script>
$(function () {
    $('.task-select2').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#taskModal') });
});

function resetTaskModal() {
    $('#taskEditId').val('');
    $('#taskModalTitle').html('<i class="bi bi-plus-lg me-2"></i>New Task');
    $('#taskTitle,#taskDescription,#taskStart,#taskDue,#taskDueTime,#taskEstHours').val('');
    $('#taskClient,#taskAssigned').val('').trigger('change');
    $('#taskLabels').val([]).trigger('change');
    $('#taskPriority').val('Medium');
    $('#taskStatus').val('Pending');
    $('#taskType').val('Other');
    $('#taskRequiresAttachment').prop('checked', false);
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
        case 'clear':    $('#taskStart').val(''); $('#taskDue').val(''); $('#taskDueTime').val(''); break;
    }

    syncWhenChips();
});

// Typing a date by hand should update the chips too, so they never disagree
// with the fields they describe.
$(document).on('change', '#taskStart, #taskDue', syncWhenChips);

/** Load a task into the dialog and open it for editing. */
function openTaskEditor(id) {
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
        // A deadline with a time is shown in this viewer's own time zone; a
        // date-only one keeps its day and leaves the time blank.
        if (r.timer && r.timer.due_has_time && r.timer.due_at) {
            const due = new Date(r.timer.due_at);
            $('#taskDue').val(isoDate(due));
            $('#taskDueTime').val(String(due.getHours()).padStart(2, '0') + ':' + String(due.getMinutes()).padStart(2, '0'));
        } else {
            $('#taskDue').val(t.due_date ? t.due_date.substring(0, 10) : '');
            $('#taskDueTime').val('');
        }
        $('#taskEstHours').val(t.estimated_hours);
        $('#taskRequiresAttachment').prop('checked', !!t.requires_attachment);
        $('#taskLabels').val((t.labels || []).map(l => l.id)).trigger('change');
        syncWhenChips();
        bootstrap.Modal.getOrCreateInstance('#taskModal').show();
    }).fail(function (x) {
        if (x.status !== 403) Swal.fire('Error', x.responseJSON?.message || 'Could not load the task.', 'error');
    });
}

$('#saveTaskBtn').on('click', function () {
    const $btn = $(this);
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
        // A time turns the day into an exact moment, sent with this browser's
        // offset so the server stores the moment the person actually meant.
        due_at: ($('#taskDue').val() && $('#taskDueTime').val())
            ? new Date($('#taskDue').val() + 'T' + $('#taskDueTime').val()).toISOString()
            : null,
        estimated_hours: $('#taskEstHours').val() || null,
        description: $('#taskDescription').val(),
        requires_attachment: $('#taskRequiresAttachment').is(':checked') ? 1 : 0,
        label_ids: $('#taskLabels').val() || [],
    };

    $btn.prop('disabled', true);
    const req = id
        ? $.ajax({ url: '/tasks/' + id, type: 'PUT', data: payload })
        : $.post('/tasks', payload);

    req.done(function (r) {
        bootstrap.Modal.getInstance('#taskModal').hide();
        Swal.fire({ icon: 'success', title: 'Saved', timer: 1200, showConfirmButton: false });
        $(document).trigger('task:saved', [r.task]);
    }).fail(function (x) {
        const errors = x.responseJSON && x.responseJSON.errors;
        Swal.fire('Could not save the task', errors ? Object.values(errors).flat().join(' ') : (x.responseJSON?.message || 'Please try again.'), 'error');
    }).always(function () {
        $btn.prop('disabled', false);
    });
});
</script>
@endpush
