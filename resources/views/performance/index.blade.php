@extends('layouts.app')
@section('title', 'Performance')

@php
    $levelSpill = fn ($level) => match ($level) {
        'Excellent'         => 'spill-completed',
        'Very Good', 'Good' => 'spill-running',
        'Needs Improvement' => 'spill-warning',
        'Poor'              => 'spill-cancelled',
        default             => 'spill-hold',
    };
    $pct = fn ($v) => $v === null ? '<span style="color:var(--text3)">&mdash;</span>' : number_format($v, 1) . '%';
@endphp

@push('styles')
<style>
    .perf-score { font-weight: 700; }
    .perf-filters .form-select { min-width: 160px; }
    #perfTable td, #perfTable th { vertical-align: middle; }
    .perf-emp-link { color: var(--text); text-decoration: none; font-weight: 600; }
    .perf-emp-link:hover { color: var(--primary); }
    .perf-rank {
        display: inline-flex; align-items: center; justify-content: center;
        width: 26px; height: 26px; border-radius: 50%;
        background: var(--surface2); color: var(--text2); font-size: .72rem; font-weight: 700;
    }
    .perf-rank.top { background: var(--primary); color: #fff; }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Performance</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Employee KPI scoreboard &mdash; live-computed for the selected month</div>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <form method="GET" class="d-flex gap-2 perf-filters flex-wrap">
            <select name="department" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All departments</option>
                @foreach ($departments as $dept)
                    <option value="{{ $dept }}" @selected($department === $dept)>{{ $dept }}</option>
                @endforeach
            </select>
            <select name="period" class="form-select form-select-sm" onchange="this.form.submit()">
                @foreach ($periods as $value => $label)
                    <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </form>
        <a href="{{ route('performance.workload') }}" class="btn btn-sm btn-outline-secondary" title="Workload">
            <i class="bi bi-speedometer me-1"></i>Workload
        </a>
        <a href="{{ route('performance.history') }}" class="btn btn-sm btn-outline-secondary" title="History">
            <i class="bi bi-clock-history me-1"></i>History
        </a>
        @can('manage performance')
            <a href="{{ route('performance.config') }}" class="btn btn-sm btn-outline-secondary" title="Configure">
                <i class="bi bi-sliders me-1"></i>Configure
            </a>
        @endcan
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-4">
        <div class="kpi-card">
            <div class="kpi-icon"><i class="bi bi-people"></i></div>
            <div>
                <div class="kpi-val">{{ $scoredCount }}</div>
                <div class="kpi-lbl">Employees scored</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="kpi-card">
            <div class="kpi-icon"><i class="bi bi-speedometer2"></i></div>
            <div>
                <div class="kpi-val">{{ $avgScore !== null ? number_format($avgScore, 1) : '—' }}</div>
                <div class="kpi-lbl">Average score</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="kpi-card">
            <div class="kpi-icon"><i class="bi bi-trophy"></i></div>
            <div>
                <div class="kpi-val" style="font-size:1.05rem">{{ $topPerformer['name'] ?? '—' }}</div>
                <div class="kpi-lbl">Top performer{{ $topPerformer ? ' · ' . number_format($topPerformer['final_score'], 1) : '' }}</div>
            </div>
        </div>
    </div>
</div>

<div class="card section-card">
    <div class="card-header py-3"><h6 class="fw-bold mb-0">Scoreboard &mdash; {{ $periods[$period] ?? $period }}</h6></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="perfTable" class="table table-hover mb-0" style="width:100%">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Task</th>
                        <th>On-time</th>
                        <th>Quality</th>
                        <th>Sales</th>
                        <th>Satisfaction</th>
                        <th>Final</th>
                        <th>Level</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td data-order="{{ $row['rank'] ?? 999 }}">
                                @if ($row['rank'])
                                    <span class="perf-rank {{ $row['rank'] === 1 ? 'top' : '' }}">{{ $row['rank'] }}</span>
                                @else
                                    <span style="color:var(--text3)">&mdash;</span>
                                @endif
                            </td>
                            <td>
                                <a class="perf-emp-link" href="{{ route('performance.show', ['user' => $row['id'], 'period' => $period]) }}">{{ $row['name'] }}</a>
                            </td>
                            <td><span style="color:var(--text2);font-size:.82rem">{{ $row['department'] ?? '—' }}</span></td>
                            <td>{!! $pct($row['scores']['task_completion']) !!}</td>
                            <td>{!! $pct($row['scores']['on_time']) !!}</td>
                            <td>{!! $pct($row['scores']['revision']) !!}</td>
                            <td>{!! $pct($row['scores']['sales']) !!}</td>
                            <td>{!! $pct($row['scores']['satisfaction']) !!}</td>
                            <td data-order="{{ $row['final_score'] ?? -1 }}" class="perf-score">
                                {{ $row['final_score'] !== null ? number_format($row['final_score'], 1) : '—' }}
                            </td>
                            <td>
                                @if ($row['performance_level'])
                                    <span class="spill {{ $levelSpill($row['performance_level']) }}">{{ $row['performance_level'] }}</span>
                                @else
                                    <span class="spill spill-hold">No data</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $(function () {
        $('#perfTable').DataTable({
            order: [],
            paging: true,
            pageLength: 25,
            lengthChange: false,
            info: false,
            language: { search: '', searchPlaceholder: 'Search employee…' },
            columnDefs: [{ orderable: false, targets: [9] }],
        });
    });
</script>
@endpush
