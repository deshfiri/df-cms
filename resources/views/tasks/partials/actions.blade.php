{{--
    What someone does to a task: start or pause it, hand it in, rule on it.

    Shared by the task list and the task page, so a button behaves the same
    wherever it is. Buttons carry the task in data attributes:
        .task-progress  data-id, data-status
        .task-submit    data-id, data-title, data-requires (1 when a file is required)
        .task-review    data-id, data-title
    Every success triggers `task:changed` on the document with the updated task;
    each page decides what to refresh. Every rule is enforced by the server —
    these only decide what to offer.
--}}
@include('partials.upload-queue')
@push('scripts')
<script>
/** Anything a user typed — a filename, a comment — goes into the page as text, never markup. */
window.escHtml = window.escHtml || function (s) {
    return $('<div>').text(s == null ? '' : String(s)).html().replace(/"/g, '&quot;');
};

/** The clearest message in a JSON error — a validation error names the problem best. */
window.ajaxMessage = window.ajaxMessage || function (x, fallback) {
    const json = x && x.responseJSON;
    if (json && json.errors) return Object.values(json.errors).flat().join(' ');
    if (x && x.status === 0) return 'The server could not be reached. Check your connection and try again.';
    if (x && x.status === 419) return 'Your session has expired. Refresh the page and try again.';
    return (json && json.message) || fallback;
};

/** <time class="local-dt" datetime="…"> shown in the viewer's own time zone. */
window.localizeTimes = window.localizeTimes || function (root) {
    const fmt = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    (root || document).querySelectorAll('time.local-dt[datetime]').forEach(function (el) {
        const ms = Date.parse(el.getAttribute('datetime'));
        if (!isNaN(ms)) { el.textContent = fmt.format(new Date(ms)); el.title = el.textContent; }
    });
};

var TASK_REVISION_REASONS ={{ Js::from(\App\Models\TaskRevision::$reasonCategories) }};

// ── Start / pause work (assignee) ────────────────────────────────────────
// No confirmation: starting your own task is reversible and routine, and a
// dialog on every one would just be in the way.
$(document).on('click', '.task-progress', function () {
    var $btn = $(this).prop('disabled', true);
    var status = $btn.data('status');

    $.post('/tasks/' + $btn.data('id') + '/progress', { status: status })
        .done(function (r) {
            Swal.fire({
                toast: true, position: 'bottom-end', icon: 'success',
                title: status === 'In Progress' ? 'Marked in progress' : (status === 'On Hold' ? 'Put on hold' : 'Status updated'),
                showConfirmButton: false, timer: 1600,
            });
            $(document).trigger('task:changed', [r.task]);
        })
        .fail(function (x) {
            if (x.status !== 403) Swal.fire('Could not update the status', ajaxMessage(x, 'Please try again.'), 'error');
        })
        .always(function () { $btn.prop('disabled', false); });
});

// ── Submit the task (assignee) ───────────────────────────────────────────
// Files go up through the upload queue as soon as they are picked — one per
// request, so any number of them fits under the server's per-request limit —
// and join the task's files as they land. Submit waits for them, then hands
// in their ids with the note.
var TASK_UPLOAD_MAX = {{ \App\Support\UploadLimit::bytes(20480) }};
var TASK_UPLOAD_HINT = {{ Js::from('Any file type · up to ' . \App\Support\UploadLimit::label(\App\Support\UploadLimit::bytes(20480)) . ' each · added to the task as they upload') }};

$(document).on('click', '.task-submit', function () {
    var id = $(this).data('id');
    var title = $(this).data('title');
    var requires = String($(this).data('requires')) === '1';
    var uploadedIds = [];
    var queue = null;

    Swal.fire({
        title: 'Submit task',
        html: '<div class="mb-2" style="font-size:.85rem"><strong>' + escHtml(title) + '</strong></div>'
            + '<div style="font-size:.8rem;color:var(--text3)" class="mb-2">It goes to whoever asked for it. They accept it or send it back.</div>'
            + '<textarea id="submitNote" class="form-control form-control-sm mb-2" rows="2" maxlength="1000" placeholder="Anything they should know (optional)"></textarea>'
            + '<div class="text-start mb-1" style="font-size:.78rem;color:var(--text2)">'
            +   (requires
                    ? '<i class="bi bi-paperclip me-1"></i><strong>A file is required.</strong> Attach your work here, unless you already added it to the task.'
                    : '<i class="bi bi-paperclip me-1"></i>Files <span style="color:var(--text3)">(optional)</span>')
            + '</div>'
            + '<input type="file" id="submitFiles" class="form-control form-control-sm" multiple>',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-send me-1"></i>Submit',
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading() && !(queue && queue.busy()),
        didOpen: function () {
            queue = makeUploadQueue(document.getElementById('submitFiles'), {
                url: '/tasks/' + id + '/attachments',
                maxBytes: TASK_UPLOAD_MAX,
                hint: TASK_UPLOAD_HINT,
                onUploaded: function (json) { if (json && json.attachment) uploadedIds.push(json.attachment.id); },
            });
        },
        // Closing the dialog stops what has not landed yet; what has stays on the task.
        willClose: function () { if (queue && queue.busy()) queue.cancelAll(); },
        preConfirm: function () {
            return queue.idle().then(function () {
                if (queue.failed()) {
                    Swal.showValidationMessage('Some files did not upload. Try them again or dismiss them first.');
                    return false;
                }
                return $.post('/tasks/' + id + '/submit', { note: $('#submitNote').val() || '', attachment_ids: uploadedIds })
                    .catch(function (x) { Swal.showValidationMessage(ajaxMessage(x, 'Could not submit the task.')); });
            });
        },
    }).then(function (r) {
        if (!r.isConfirmed || !r.value) {
            if (uploadedIds.length) $(document).trigger('task:files-changed');
            return;
        }
        Swal.fire({ icon: 'success', title: 'Submitted for review', timer: 1400, showConfirmButton: false });
        $(document).trigger('task:changed', [r.value.task]);
    });
});

// ── Review a submission (requester) ──────────────────────────────────────
$(document).on('click', '.task-review', function () {
    var id = $(this).data('id');
    var title = $(this).data('title');

    var reasonOptions = TASK_REVISION_REASONS.map(rc => '<option value="' + escHtml(rc) + '">' + escHtml(rc) + '</option>').join('');

    Swal.fire({
        title: 'Review submission',
        html: '<div class="mb-2" style="font-size:.85rem"><strong>' + escHtml(title) + '</strong></div>'
            + '<textarea id="revNote" class="form-control form-control-sm mb-2" rows="2" maxlength="1000" placeholder="Note (optional)"></textarea>'
            + '<select id="revReason" class="form-select form-select-sm">' + reasonOptions + '</select>'
            + '<div style="font-size:.72rem;color:var(--text3);margin-top:.35rem;text-align:left">'
            + 'The reason is only used when you send it back. Only “Employee Mistake” counts against the quality KPI.</div>',
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
            .done(function (res) {
                Swal.fire({ icon: 'success', title: r.isConfirmed ? 'Accepted' : 'Sent back', timer: 1400, showConfirmButton: false });
                $(document).trigger('task:changed', [res.task]);
            })
            .fail(function (x) {
                if (x.status !== 403) Swal.fire('Could not save the review', ajaxMessage(x, 'Please try again.'), 'error');
            });
    });
});
</script>
@endpush
