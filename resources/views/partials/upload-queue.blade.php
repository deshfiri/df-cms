{{--
    Bulk uploads: pick or drop any number of files, and they go up through a
    small queue — one file per request, a couple at a time.

      - One request per file, so a batch never meets the server's limit for a
        single request, and one bad file does not sink the rest.
      - Two at a time, so a big batch does not swamp the server (or everyone
        else's page loads on it).
      - Progress, cancel and retry per file. A file over the real limit is
        refused before it is sent, saying what the limit is.
      - The page hears once when the batch has settled, so it refreshes its
        list once rather than once per file.
      - Leaving the page mid-upload asks first.

    Usage, after the input exists (the input is made `multiple` and gets a drop zone):
        const q = makeUploadQueue('#taskFileInput', {
            url: '/tasks/12/attachments',          // POSTed once per file
            field: 'file',                         // default 'file'
            data: (file, count) => ({ kind: 'file' }), // extra fields — object or function
            maxBytes: 20971520,                    // App\Support\UploadLimit::bytes(...)
            hint: 'Any file type · up to 20 MB each',
            onUploaded: (json, file) => {},        // after each file
            onSettled: ({ uploaded, failed }) => {}, // once the batch is done, if anything uploaded
        });
        q.busy(); q.failed(); q.idle().then(...); q.cancelAll();
--}}
@include('partials.dropzone')
@once
@push('styles')
<style>
    .uq { margin-top: .6rem; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); overflow: hidden; text-align: left; }
    .uq-head { display: flex; justify-content: space-between; align-items: center; gap: .5rem; padding: .35rem .6rem; font-size: .72rem; color: var(--text2); background: var(--surface2); border-bottom: 1px solid var(--border); }
    .uq-cancel-all { background: none; border: 0; padding: 0; font-size: .7rem; color: var(--text3); }
    .uq-cancel-all:hover { color: var(--c-red); }
    .uq-rows { max-height: 260px; overflow-y: auto; }
    .uq-row { display: flex; align-items: center; gap: .55rem; padding: .45rem .6rem; border-bottom: 1px solid var(--border); }
    .uq-row:last-child { border-bottom: 0; }
    .uq-icon { font-size: 1rem; color: var(--text3); flex-shrink: 0; }
    .uq-main { flex: 1; min-width: 0; }
    .uq-line { display: flex; gap: .5rem; align-items: baseline; font-size: .76rem; }
    .uq-name { color: var(--text); font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
    .uq-size { color: var(--text3); font-size: .68rem; flex-shrink: 0; }
    .uq-bar { height: 4px; border-radius: 2px; background: var(--surface2); overflow: hidden; margin-top: 4px; }
    .uq-bar > span { display: block; height: 100%; width: 0; background: var(--primary); transition: width .2s; }
    .uq-status { font-size: .66rem; color: var(--text3); margin-top: 2px; }
    .uq-row[data-state="done"] .uq-icon { color: var(--c-green); }
    .uq-row[data-state="done"] .uq-bar > span { background: var(--c-green); }
    .uq-row[data-state="failed"] .uq-icon, .uq-row[data-state="failed"] .uq-status { color: var(--c-red); }
    .uq-row[data-state="failed"] .uq-bar { display: none; }
    .uq-btn { background: none; border: 0; color: var(--text3); padding: .2rem .3rem; border-radius: 6px; line-height: 1; }
    .uq-btn:hover { color: var(--primary); background: var(--surface2); }
</style>
@endpush

@push('scripts')
<script>
(function () {
    'use strict';

    const CONCURRENCY = 2;

    function human(bytes) {
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1).replace(/\.0$/, '') + ' MB';
        if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
        return bytes + ' B';
    }

    function csrf() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return (meta && meta.content) || (window.DFCP && window.DFCP.csrf) || '';
    }

    /** The clearest reason a request gave — a validation error names the problem best. */
    function reasonOf(xhr) {
        if (xhr.status === 0) return 'Could not reach the server. Try again.';
        if (xhr.status === 413) return 'Too large for the server.';
        if (xhr.status === 419) return 'Your session has expired. Refresh the page.';
        if (xhr.status === 403) return 'You can\'t add files here.';
        let json = null;
        try { json = JSON.parse(xhr.responseText); } catch (e) { /* not JSON */ }
        if (json && json.errors) return Object.values(json.errors).flat()[0];
        if (json && json.message) return json.message;
        return 'Upload failed (' + xhr.status + '). Try again.';
    }

    // Leaving while anything is still going up would cut it off.
    const queues = [];
    window.addEventListener('beforeunload', function (e) {
        if (queues.some(q => q.busy())) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    window.makeUploadQueue = function (input, options) {
        const el = typeof input === 'string' ? document.querySelector(input) : input;
        if (!el) return null;
        if (el._uploadQueue) return el._uploadQueue;
        options = options || {};

        const maxBytes = options.maxBytes || Infinity;
        const concurrency = options.concurrency || CONCURRENCY;
        const jobs = [];
        const waiters = [];

        el.multiple = true;
        makeDropzone(el, { hint: options.hint });

        const box = document.createElement('div');
        box.className = 'uq';
        box.hidden = true;
        box.setAttribute('aria-live', 'polite');
        box.innerHTML = '<div class="uq-head"><span class="uq-summary"></span>'
            + '<button type="button" class="uq-cancel-all">Cancel all</button></div>'
            + '<div class="uq-rows"></div>';
        const anchor = el.closest('.dzone') || el;
        anchor.parentNode.insertBefore(box, anchor.nextSibling);

        const rowsEl = box.querySelector('.uq-rows');
        const summaryEl = box.querySelector('.uq-summary');
        const cancelAllBtn = box.querySelector('.uq-cancel-all');

        const inFlight = j => j.state === 'waiting' || j.state === 'uploading';
        const busy = () => jobs.some(inFlight);
        const failedCount = () => jobs.filter(j => j.state === 'failed').length;

        function makeRow(job) {
            const row = document.createElement('div');
            row.className = 'uq-row';
            row.innerHTML = '<i class="bi bi-file-earmark uq-icon"></i>'
                + '<div class="uq-main"><div class="uq-line"><span class="uq-name"></span><span class="uq-size"></span></div>'
                + '<div class="uq-bar"><span></span></div><div class="uq-status"></div></div>'
                + '<button type="button" class="uq-btn uq-retry" title="Try again" hidden><i class="bi bi-arrow-clockwise"></i></button>'
                + '<button type="button" class="uq-btn uq-remove"><i class="bi bi-x-lg"></i></button>';
            // Names go in as text, never markup.
            row.querySelector('.uq-name').textContent = job.file.name;
            row.querySelector('.uq-name').title = job.file.name;
            row.querySelector('.uq-size').textContent = human(job.file.size);
            row.querySelector('.uq-retry').addEventListener('click', () => retry(job));
            row.querySelector('.uq-remove').addEventListener('click', () => remove(job));
            rowsEl.appendChild(row);
            job.row = row;
        }

        function paint(job, state, text, pct) {
            job.state = state;
            const row = job.row;
            row.dataset.state = state;
            row.querySelector('.uq-status').textContent = text;
            if (pct != null) row.querySelector('.uq-bar > span').style.width = pct + '%';
            row.querySelector('.uq-icon').className = 'bi uq-icon '
                + ({ done: 'bi-check-circle-fill', failed: 'bi-exclamation-circle-fill' }[state] || 'bi-file-earmark');
            row.querySelector('.uq-retry').hidden = !(state === 'failed' && !job.final);
            row.querySelector('.uq-remove').title = inFlight(job) ? 'Cancel' : 'Dismiss';
        }

        function summarize() {
            const done = jobs.filter(j => j.state === 'done').length;
            const failed = failedCount();
            box.hidden = jobs.length === 0;
            cancelAllBtn.hidden = !busy();
            summaryEl.textContent = busy()
                ? done + ' of ' + jobs.length + ' uploaded' + (failed ? ' · ' + failed + ' failed' : '')
                : [done && done + ' uploaded', failed && failed + ' failed — try again or dismiss'].filter(Boolean).join(' · ');
        }

        function start(job) {
            const fd = new FormData();
            Object.entries(job.extra || {}).forEach(function ([key, value]) {
                if (value !== null && value !== undefined) fd.append(key, value);
            });
            fd.append(options.field || 'file', job.file);

            const xhr = new XMLHttpRequest();
            job.xhr = xhr;
            xhr.open('POST', typeof options.url === 'function' ? options.url(job.file) : options.url);
            xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');

            xhr.upload.onprogress = function (e) {
                if (!e.lengthComputable || job.xhr !== xhr) return;
                const pct = Math.round(e.loaded / e.total * 100);
                paint(job, 'uploading', pct < 100 ? 'Uploading… ' + pct + '%' : 'Saving…', pct);
            };
            xhr.onload = function () {
                if (job.xhr !== xhr) return;
                job.xhr = null;
                if (xhr.status >= 200 && xhr.status < 300) {
                    try { job.response = JSON.parse(xhr.responseText); } catch (e) { job.response = null; }
                    paint(job, 'done', 'Uploaded', 100);
                    if (options.onUploaded) options.onUploaded(job.response, job.file);
                } else {
                    // Trying again cannot fix these.
                    job.final = [403, 413, 419, 422].includes(xhr.status);
                    paint(job, 'failed', reasonOf(xhr));
                }
                pump();
            };
            xhr.onerror = function () {
                if (job.xhr !== xhr) return;
                job.xhr = null;
                paint(job, 'failed', reasonOf(xhr));
                pump();
            };

            paint(job, 'uploading', 'Uploading… 0%', 0);
            xhr.send(fd);
        }

        function pump() {
            let running = jobs.filter(j => j.state === 'uploading').length;
            jobs.forEach(function (job) {
                if (running < concurrency && job.state === 'waiting') {
                    start(job);
                    running++;
                }
            });
            settleIfIdle();
        }

        function settleIfIdle() {
            summarize();
            if (busy()) return;

            const fresh = jobs.filter(j => j.state === 'done' && !j.reported);
            fresh.forEach(j => { j.reported = true; });
            waiters.splice(0).forEach(resolve => resolve());

            if (fresh.length && options.onSettled) {
                options.onSettled({ uploaded: fresh.map(j => j.response), failed: failedCount() });
            }
            // Finished rows step aside once the page's own list shows them;
            // failures stay, so they can be tried again.
            fresh.forEach(j => setTimeout(() => drop(j), 1500));
        }

        function drop(job) {
            const i = jobs.indexOf(job);
            if (i !== -1) jobs.splice(i, 1);
            if (job.row) job.row.remove();
            summarize();
        }

        function remove(job) {
            if (job.xhr) {
                const xhr = job.xhr;
                job.xhr = null;
                xhr.abort();
            }
            drop(job);
            pump();
        }

        function retry(job) {
            job.final = false;
            paint(job, 'waiting', 'Waiting…', 0);
            pump();
        }

        function cancelAll() {
            jobs.filter(inFlight).forEach(function (job) {
                if (job.xhr) {
                    const xhr = job.xhr;
                    job.xhr = null;
                    xhr.abort();
                }
                drop(job);
            });
            pump();
        }

        function add(files) {
            const picked = Array.from(files || []);
            picked.forEach(function (file) {
                const job = { file: file, state: 'waiting', xhr: null, response: null, final: false, reported: false };
                // Read now, so a field on the page (a label) goes with the files it was typed for.
                job.extra = typeof options.data === 'function' ? options.data(file, picked.length) : (options.data || {});
                jobs.push(job);
                makeRow(job);

                if (file.size > maxBytes) {
                    job.final = true;
                    paint(job, 'failed', 'Too large — files can be up to ' + human(maxBytes) + ' here.');
                } else {
                    paint(job, 'waiting', 'Waiting…', 0);
                }
            });
            pump();
        }

        cancelAllBtn.addEventListener('click', cancelAll);

        // Native listener: page code often calls $(input).off('change').
        el.addEventListener('change', function () {
            if (!el.files || !el.files.length) return;
            const picked = Array.from(el.files);
            // Emptied straight away, ready for the next pick; the queue has them now.
            el.value = '';
            el.dispatchEvent(new Event('change'));
            add(picked);
        });

        const api = {
            add: add,
            busy: busy,
            failed: failedCount,
            cancelAll: cancelAll,
            /** Resolves once nothing is waiting or uploading. */
            idle: () => busy() ? new Promise(resolve => waiters.push(resolve)) : Promise.resolve(),
        };
        el._uploadQueue = api;
        queues.push(api);

        return api;
    };
})();
</script>
@endpush
@endonce
