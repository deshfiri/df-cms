@extends('layouts.app')
@section('title', 'Performance · ' . $employee->name)

@php
    $levelSpill = fn ($level) => match ($level) {
        'Excellent'         => 'spill-completed',
        'Very Good', 'Good' => 'spill-running',
        'Needs Improvement' => 'spill-warning',
        'Poor'              => 'spill-cancelled',
        default             => 'spill-hold',
    };
    $componentLabels = [
        'task_completion' => 'Task Completion',
        'on_time'         => 'On-Time Delivery',
        'revision'        => 'Quality',
        'sales'           => 'Sales Achievement',
        'satisfaction'    => 'Client Satisfaction',
    ];
    $c = $result['components'];
    $money = fn ($v) => number_format((float) $v, 2);
@endphp

@push('styles')
<style>
    .sc-hero {
        display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;
        background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
        padding: 1.25rem 1.5rem; box-shadow: var(--shadow-sm);
    }
    .sc-ring {
        width: 96px; height: 96px; border-radius: 50%; display: grid; place-items: center;
        background: conic-gradient(var(--primary) calc(var(--val) * 1%), var(--surface2) 0);
        flex-shrink: 0;
    }
    .sc-ring-inner {
        width: 76px; height: 76px; border-radius: 50%; background: var(--surface);
        display: grid; place-items: center; line-height: 1;
    }
    .sc-ring-val { font-size: 1.5rem; font-weight: 800; color: var(--text); }
    .sc-ring-max { font-size: .62rem; color: var(--text3); }
    .sc-comp-card { height: 100%; }
    .sc-metric { display: flex; justify-content: space-between; padding: .3rem 0; font-size: .82rem; border-bottom: 1px dashed var(--border); }
    .sc-metric:last-child { border-bottom: 0; }
    .sc-metric .k { color: var(--text2); }
    .sc-metric .v { font-weight: 600; color: var(--text); }
    .sc-weight { font-size: .66rem; color: var(--text3); }
    .sc-headline { font-size: 2rem; font-weight: 800; }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-person-badge me-2"></i>{{ $employee->name }}</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">
            {{ $employee->getRoleNames()->first() ?? 'No department' }} &middot; Scorecard
        </div>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <a href="{{ route('performance.index', ['period' => $period]) }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Scoreboard
        </a>
        <form method="GET">
            <select name="period" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:150px">
                @foreach ($periods as $value => $label)
                    <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </form>
    </div>
</div>

{{-- Hero --}}
<div class="sc-hero mb-3">
    <div class="sc-ring" style="--val: {{ $result['final_score'] ?? 0 }}">
        <div class="sc-ring-inner">
            <div class="text-center">
                <div class="sc-ring-val">{{ $result['final_score'] !== null ? number_format($result['final_score'], 1) : '—' }}</div>
                <div class="sc-ring-max">/ 100</div>
            </div>
        </div>
    </div>
    <div>
        @if ($result['performance_level'])
            <span class="spill {{ $levelSpill($result['performance_level']) }}" style="font-size:.8rem">{{ $result['performance_level'] }}</span>
        @else
            <span class="spill spill-hold">No data for this period</span>
        @endif
        <div class="mt-2" style="font-size:.82rem;color:var(--text2)">
            @if ($result['strongest'])
                Strongest: <strong style="color:var(--text)">{{ $componentLabels[$result['strongest']] }}</strong>
                &middot; Weakest: <strong style="color:var(--text)">{{ $componentLabels[$result['weakest']] }}</strong>
            @else
                No tasks, sales targets, or ratings recorded for {{ $periods[$period] ?? $period }}.
            @endif
        </div>
    </div>
</div>

@if (count($trend['labels']) > 1)
<div class="card section-card mb-3">
    <div class="card-header py-2"><h6 class="fw-bold mb-0"><i class="bi bi-graph-up me-1"></i>Final score trend</h6></div>
    <div class="card-body"><div style="height:200px"><canvas id="trendChart"></canvas></div></div>
</div>
@endif

<div class="row g-3">
    {{-- Task Completion --}}
    <div class="col-lg-4 col-md-6">
        <div class="card section-card sc-comp-card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0"><i class="bi bi-list-check me-1"></i>Task Completion</h6>
                @isset($result['weights_used']['task_completion'])
                    <span class="sc-weight">weight {{ $result['weights_used']['task_completion'] }}%</span>
                @endisset
            </div>
            <div class="card-body">
                <div class="sc-metric"><span class="k">Completion rate</span><span class="v">{{ $c['taskCompletion']['completion_pct'] !== null ? $c['taskCompletion']['completion_pct'] . '%' : '—' }}</span></div>
                <div class="sc-metric"><span class="k">Total tasks</span><span class="v">{{ $c['taskCompletion']['total'] }}</span></div>
                <div class="sc-metric"><span class="k">Completed</span><span class="v">{{ $c['taskCompletion']['completed'] }}</span></div>
                <div class="sc-metric"><span class="k">In progress / pending</span><span class="v">{{ $c['taskCompletion']['in_progress'] }} / {{ $c['taskCompletion']['pending'] }}</span></div>
                <div class="sc-metric"><span class="k">Overdue</span><span class="v">{{ $c['taskCompletion']['overdue'] }}</span></div>
                <div class="sc-metric"><span class="k">Cancelled</span><span class="v">{{ $c['taskCompletion']['cancelled'] }}</span></div>
            </div>
        </div>
    </div>

    {{-- On-Time --}}
    <div class="col-lg-4 col-md-6">
        <div class="card section-card sc-comp-card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0"><i class="bi bi-clock-history me-1"></i>On-Time Delivery</h6>
                @isset($result['weights_used']['on_time'])
                    <span class="sc-weight">weight {{ $result['weights_used']['on_time'] }}%</span>
                @endisset
            </div>
            <div class="card-body">
                <div class="sc-metric"><span class="k">On-time rate</span><span class="v">{{ $c['onTime']['on_time_rate'] !== null ? $c['onTime']['on_time_rate'] . '%' : '—' }}</span></div>
                <div class="sc-metric"><span class="k">Completed tasks</span><span class="v">{{ $c['onTime']['total_completed'] }}</span></div>
                <div class="sc-metric"><span class="k">Early / on deadline</span><span class="v">{{ $c['onTime']['before_deadline'] }} / {{ $c['onTime']['on_deadline'] }}</span></div>
                <div class="sc-metric"><span class="k">Late</span><span class="v">{{ $c['onTime']['after_deadline'] }}</span></div>
                <div class="sc-metric"><span class="k">Avg delay</span><span class="v">{{ $c['onTime']['avg_delay_days'] !== null ? $c['onTime']['avg_delay_days'] . ' days' : '—' }}</span></div>
            </div>
        </div>
    </div>

    {{-- Quality / Revisions --}}
    <div class="col-lg-4 col-md-6">
        <div class="card section-card sc-comp-card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0"><i class="bi bi-arrow-repeat me-1"></i>Quality</h6>
                @isset($result['weights_used']['revision'])
                    <span class="sc-weight">weight {{ $result['weights_used']['revision'] }}%</span>
                @endisset
            </div>
            <div class="card-body">
                <div class="sc-metric"><span class="k">Revision rate (KPI)</span><span class="v">{{ $c['revision']['revision_rate_kpi'] !== null ? $c['revision']['revision_rate_kpi'] . '%' : '—' }}</span></div>
                <div class="sc-metric"><span class="k">Tasks submitted</span><span class="v">{{ $c['revision']['total_submitted'] }}</span></div>
                <div class="sc-metric"><span class="k">Approved first time</span><span class="v">{{ $c['revision']['approved_first_submission'] }}</span></div>
                <div class="sc-metric"><span class="k">Needed revision</span><span class="v">{{ $c['revision']['requiring_revision'] }}</span></div>
                <div class="sc-metric"><span class="k">Avg revisions / task</span><span class="v">{{ $c['revision']['avg_revisions_per_task'] ?? '—' }}</span></div>
            </div>
        </div>
    </div>

    {{-- Sales --}}
    <div class="col-lg-6">
        <div class="card section-card sc-comp-card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0"><i class="bi bi-cash-stack me-1"></i>Sales Achievement</h6>
                @isset($result['weights_used']['sales'])
                    <span class="sc-weight">weight {{ $result['weights_used']['sales'] }}%</span>
                @endisset
            </div>
            <div class="card-body">
                @if ($c['sales'])
                    <div class="sc-metric"><span class="k">Target achieved</span><span class="v">{{ $c['sales']['pct'] !== null ? $c['sales']['pct'] . '%' : '—' }}</span></div>
                    <div class="sc-metric"><span class="k">Target</span><span class="v">{{ $money($c['sales']['target_amount']) }}</span></div>
                    <div class="sc-metric"><span class="k">Achieved</span><span class="v">{{ $money($c['sales']['achieved']) }}</span></div>
                    <div class="sc-metric"><span class="k">Remaining</span><span class="v">{{ $money($c['sales']['remaining']) }}</span></div>
                    <div class="sc-metric"><span class="k">Daily run-rate needed</span><span class="v">{{ $c['sales']['daily_required'] !== null ? $money($c['sales']['daily_required']) : '—' }}</span></div>
                @else
                    <div class="text-center py-3" style="color:var(--text3);font-size:.82rem">No sales target set for this period.</div>
                @endif
            </div>
        </div>
    </div>

    {{-- Satisfaction --}}
    <div class="col-lg-6">
        <div class="card section-card sc-comp-card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0"><i class="bi bi-emoji-smile me-1"></i>Client Satisfaction</h6>
                @isset($result['weights_used']['satisfaction'])
                    <span class="sc-weight">weight {{ $result['weights_used']['satisfaction'] }}%</span>
                @endisset
            </div>
            <div class="card-body">
                @if ($c['satisfaction'])
                    <div class="sc-metric"><span class="k">Score</span><span class="v">{{ $c['satisfaction']['score'] }}</span></div>
                    <div class="sc-metric"><span class="k">Average rating</span><span class="v">{{ $c['satisfaction']['avg_rating'] }} / 5</span></div>
                    <div class="sc-metric"><span class="k">Ratings received</span><span class="v">{{ $c['satisfaction']['count'] }}</span></div>
                    <div class="sc-metric"><span class="k">Positive (≥4)</span><span class="v">{{ $c['satisfaction']['positive'] }}</span></div>
                    <div class="sc-metric"><span class="k">Complaints (≤2)</span><span class="v">{{ $c['satisfaction']['complaints'] }}</span></div>
                @else
                    <div class="text-center py-3" style="color:var(--text3);font-size:.82rem">No client ratings recorded for this period.</div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
{{-- Chart.js is not in the layout; this page needs it. --}}
<script src="{{ App\Support\ShellAsset::url('vendor/js/chart.umd.min.js') }}"></script>
@if (count($trend['labels']) > 1)
<script>
$(function () {
    var ct = chartTheme();
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    var ctx = document.getElementById('trendChart').getContext('2d');
    var rgb = getComputedStyle(document.documentElement).getPropertyValue('--primary-rgb').trim() || '37,99,235';
    var grad = ctx.createLinearGradient(0, 0, 0, 200);
    grad.addColorStop(0, 'rgba(' + rgb + ',.25)');
    grad.addColorStop(1, 'rgba(0,0,0,0)');
    var chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: @json($trend['labels']),
            datasets: [{
                label: 'Final score',
                data: @json($trend['scores']),
                borderColor: ct.colors[0], borderWidth: 2.5,
                backgroundColor: grad, fill: true, tension: .35,
                pointRadius: 4, pointBackgroundColor: ct.colors[0],
                pointBorderColor: isDark ? '#111827' : '#fff', pointBorderWidth: 2,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' ' + c.parsed.y + ' / 100' } } },
            scales: {
                x: { grid: { color: ct.gridColor, drawTicks: false }, ticks: { color: ct.textColor, padding: 6 }, border: { display: false } },
                y: { min: 0, max: 100, grid: { color: ct.gridColor }, ticks: { color: ct.textColor, padding: 8, stepSize: 20 }, border: { display: false } }
            }
        }
    });
    (window._charts = window._charts || []).push(chart);
});
</script>
@endif
@endpush
