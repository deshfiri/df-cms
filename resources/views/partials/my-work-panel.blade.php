{{--
    My Work: the signed-in person's workload now, and their output today, this
    week and this month, with a 14-day chart. Everything comes from
    MyWorkService (see there for exactly what each number counts), loaded with
    the browser's time zone so "today" means the viewer's today.

    @include('partials.my-work-panel', ['showLoad' => true])
      showLoad  false hides the "right now" tiles, for a page that already shows them.
--}}
@php
    $showLoad = $showLoad ?? true;
    $canTasks = auth()->user()->canAny(['view tasks', 'manage tasks']);
@endphp

@once
@push('styles')
<style>
    .mwp-head { display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap; }
    .mwp-head h6 { margin: 0; font-weight: 700; }
    .mwp-sub { font-size: .7rem; color: var(--text3); }
    .mwp-tabs { display: inline-flex; gap: 2px; background: var(--surface2); border: 1px solid var(--border); border-radius: 999px; padding: 2px; }
    .mwp-tab { border: 0; background: transparent; color: var(--text2); font-size: .74rem; font-weight: 600; border-radius: 999px; padding: .22rem .75rem; cursor: pointer; }
    .mwp-tab:hover { color: var(--text); }
    .mwp-tab[aria-selected="true"] { background: var(--surface); color: var(--primary); box-shadow: var(--shadow-sm); }
    .mwp-tab:focus-visible { outline: 2px solid var(--primary); outline-offset: 1px; }

    .mwp-load { display: grid; grid-template-columns: repeat(auto-fit, minmax(118px, 1fr)); gap: .6rem; }
    @media (max-width: 479.98px) { .mwp-load { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    .mwp-tile { display: block; text-decoration: none; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); padding: .6rem .7rem; min-width: 0; }
    a.mwp-tile:hover { border-color: var(--primary); }
    .mwp-v { font-size: 1.35rem; font-weight: 700; color: var(--text); line-height: 1.15; font-variant-numeric: tabular-nums; }
    .mwp-k { font-size: .64rem; text-transform: uppercase; letter-spacing: .04em; color: var(--text3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mwp-s { font-size: .64rem; color: var(--text3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .mwp-out { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .6rem; }
    .mwp-out .mwp-tile { background: var(--surface2); }
    .mwp-chart { position: relative; height: 210px; }
    .mwp-state { font-size: .78rem; color: var(--text3); text-align: center; padding: .6rem; }
    .mwp-skel .mwp-v { color: transparent; background: var(--surface2); border-radius: 4px; width: 2.2rem; }
</style>
@endpush
@endonce

<div class="card section-card mb-3" id="myWorkPanel"
     data-url="{{ route('my-work.stats') }}"
     data-chart-src="{{ App\Support\ShellAsset::url('vendor/js/chart.umd.min.js') }}">
    <div class="card-header py-2 mwp-head">
        <div>
            <h6><i class="bi bi-person-workspace me-1"></i>My Work</h6>
            <div class="mwp-sub" id="mwpSub">Your tasks and workflow items</div>
        </div>
        <div class="mwp-tabs" role="tablist" aria-label="Period">
            <button type="button" class="mwp-tab" role="tab" data-period="today" aria-selected="true">Today</button>
            <button type="button" class="mwp-tab" role="tab" data-period="week" aria-selected="false">This week</button>
            <button type="button" class="mwp-tab" role="tab" data-period="month" aria-selected="false">This month</button>
        </div>
    </div>
    <div class="card-body mwp-skel" id="mwpBody">
        @if($showLoad)
            <div class="mwp-load mb-3">
                @php
                    $tiles = [
                        ['assigned', 'Assigned', 'open tasks', 'var(--primary)', $canTasks ? route('tasks.index') : null],
                        ['active', 'In progress', 'being worked on', 'var(--c-green)', null],
                        ['pending', 'Pending', 'not started / on hold', 'var(--text)', null],
                        ['awaiting_review', 'Awaiting review', 'you handed in', 'var(--c-yellow)', null],
                        ['to_review', 'To review', 'handed in to you', 'var(--c-yellow)', $canTasks ? route('tasks.index', ['review' => 1]) : null],
                        ['overdue', 'Overdue', 'tasks + workflow', 'var(--c-red)', null],
                    ];
                @endphp
                @foreach($tiles as [$key, $label, $sub, $color, $href])
                    @if($href)<a href="{{ $href }}" class="mwp-tile">@else<div class="mwp-tile">@endif
                        <div class="mwp-v" data-now="{{ $key }}" style="color:{{ $color }}">0</div>
                        <div class="mwp-k">{{ $label }}</div>
                        <div class="mwp-s" data-now-sub="{{ $key }}">{{ $sub }}</div>
                    @if($href)</a>@else</div>@endif
                @endforeach
                {{-- Shown only to people who work a workflow stage. --}}
                <a href="{{ route('flow.queue') }}" class="mwp-tile" id="mwpFlowTile" hidden>
                    <div class="mwp-v" data-now="flow_mine" style="color:var(--primary)">0</div>
                    <div class="mwp-k">Workflow items</div>
                    <div class="mwp-s" data-now-sub="flow_available">claimed by you</div>
                </a>
            </div>
        @endif

        <div class="mwp-out mb-3">
            <div class="mwp-tile"><div class="mwp-v" data-out="completed" style="color:var(--c-green)">0</div><div class="mwp-k">Completed</div><div class="mwp-s" data-out-sub>—</div></div>
            <div class="mwp-tile"><div class="mwp-v" data-out="submitted" style="color:var(--primary)">0</div><div class="mwp-k">Submitted</div><div class="mwp-s">for review</div></div>
            <div class="mwp-tile"><div class="mwp-v" data-out="received">0</div><div class="mwp-k">New tasks</div><div class="mwp-s">assigned to you</div></div>
        </div>

        <div class="mwp-chart"><canvas id="mwpChart" aria-label="Your completed, submitted and new tasks over the last 14 days" role="img"></canvas></div>
        <div class="mwp-state" id="mwpState" hidden></div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const panel = document.getElementById('myWorkPanel');
    if (!panel) return;

    const zone = (Intl.DateTimeFormat().resolvedOptions() || {}).timeZone || '';
    const body = document.getElementById('mwpBody');
    const state = document.getElementById('mwpState');
    let data = null, chart = null, period = 'today';

    try { period = localStorage.getItem('mwp.period') || 'today'; } catch (e) {}
    if (!['today', 'week', 'month'].includes(period)) period = 'today';

    function setText(sel, value) { panel.querySelectorAll(sel).forEach(el => { el.textContent = value; }); }

    function localDay(ymd, opts) {
        const [y, m, d] = ymd.split('-').map(Number);
        return new Date(y, m - 1, d).toLocaleDateString(undefined, opts);
    }

    function paint() {
        if (!data) return;
        panel.querySelectorAll('.mwp-tab').forEach(t => t.setAttribute('aria-selected', String(t.dataset.period === period)));

        Object.entries(data.now).forEach(([key, n]) => setText('[data-now="' + key + '"]', n));
        setText('[data-now-sub="overdue"]', data.now.flow_participant
            ? data.now.overdue_tasks + ' task' + (data.now.overdue_tasks === 1 ? '' : 's') + ' · ' + data.now.overdue_flow + ' workflow'
            : 'past the deadline');
        setText('[data-now-sub="flow_available"]', data.now.flow_available + ' available to claim');
        const flowTile = document.getElementById('mwpFlowTile');
        if (flowTile) flowTile.hidden = !data.now.flow_participant;

        const p = data.periods[period];
        Object.entries(p).forEach(([key, n]) => setText('[data-out="' + key + '"]', n));
        setText('[data-out-sub]', period === 'today' ? 'today' : 'since ' + localDay(data.period_start[period], { weekday: 'short', day: 'numeric', month: 'short' }));

        document.getElementById('mwpSub').textContent = 'Your tasks and workflow items · times in ' + data.timezone.replace(/_/g, ' ');
        body.classList.remove('mwp-skel');
        drawChart();
    }

    function withChart(cb) {
        if (window.Chart) return cb();
        const existing = document.querySelector('script[data-mwp-chart]');
        if (existing) { existing.addEventListener('load', cb); return; }
        const s = document.createElement('script');
        s.src = panel.dataset.chartSrc;
        s.dataset.mwpChart = '1';
        s.onload = cb;
        document.head.appendChild(s);
    }

    function drawChart() {
        withChart(function () {
            const ct = typeof chartTheme === 'function' ? chartTheme() : { colors: ['#2563eb', '#16a34a', '#f59e0b'], gridColor: 'rgba(0,0,0,.06)', textColor: '#64748b' };
            const s = data.series;
            const labels = s.labels.map(d => localDay(d, { day: 'numeric', month: 'short' }));
            const datasets = [
                { type: 'bar', label: 'Completed', data: s.completed, backgroundColor: 'rgba(22,163,74,.75)', borderRadius: 4, maxBarThickness: 18 },
                { type: 'bar', label: 'Submitted', data: s.submitted, backgroundColor: ct.colors[0], borderRadius: 4, maxBarThickness: 18 },
                { type: 'line', label: 'New tasks', data: s.received, borderColor: '#f59e0b', backgroundColor: '#f59e0b', tension: .3, pointRadius: 2.5, borderWidth: 2 },
            ];

            if (chart) {
                chart.data.labels = labels;
                chart.data.datasets.forEach((ds, i) => { ds.data = datasets[i].data; });
                chart.update('none');
                return;
            }

            chart = new Chart(document.getElementById('mwpChart'), {
                data: { labels, datasets },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'bottom', labels: { color: ct.textColor, boxWidth: 10, font: { size: 11 } } } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: ct.textColor, maxRotation: 0, autoSkip: true, font: { size: 10 } }, border: { display: false } },
                        y: { beginAtZero: true, grid: { color: ct.gridColor }, ticks: { color: ct.textColor, precision: 0, font: { size: 10 } }, border: { display: false } },
                    },
                },
            });
            (window._charts = window._charts || []).push(chart);
        });
    }

    function load() {
        return fetch(panel.dataset.url + '?tz=' + encodeURIComponent(zone), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(r => r.ok ? r.json() : Promise.reject(r))
            .then(function (json) { data = json; state.hidden = true; paint(); })
            .catch(function (r) {
                // Keep the last good numbers on screen if there are any.
                if (data) return;
                state.hidden = false;
                state.innerHTML = (r && r.status === 419 ? 'Your session has expired — refresh the page.' : 'Your work summary could not be loaded.')
                    + ' <button type="button" class="btn btn-sm btn-link p-0 align-baseline" id="mwpRetry">Try again</button>';
            });
    }

    panel.addEventListener('click', function (e) {
        const tab = e.target.closest('.mwp-tab');
        if (tab) {
            period = tab.dataset.period;
            try { localStorage.setItem('mwp.period', period); } catch (err) {}
            paint();
        }
        if (e.target.id === 'mwpRetry') load();
    });

    load();
    setInterval(function () { if (!document.hidden) load(); }, 120000);
})();
</script>
@endpush
