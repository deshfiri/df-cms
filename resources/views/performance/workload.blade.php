@extends('layouts.app')
@section('title', 'Workload')

@php
    $statusSpill = fn ($s) => match ($s) {
        'Available'  => 'spill-completed',
        'Normal'     => 'spill-running',
        'Busy'       => 'spill-warning',
        'Overloaded' => 'spill-cancelled',
        default      => 'spill-hold',
    };
    $barColor = fn ($u) => $u === null ? 'var(--text3)' : ($u >= ($settings->overload_threshold_pct) ? '#dc3545' : ($u >= $settings->busy_threshold_pct ? '#f59e0b' : 'var(--primary)'));
@endphp

@push('styles')
<style>
    #wlTable td, #wlTable th { vertical-align: middle; }
    .wl-emp-link { color: var(--text); text-decoration: none; font-weight: 600; }
    .wl-emp-link:hover { color: var(--primary); }
    .wl-bar { height: 8px; border-radius: 999px; background: var(--surface2); overflow: hidden; min-width: 90px; }
    .wl-bar > span { display: block; height: 100%; border-radius: 999px; }
    .wl-flag { font-size: .68rem; padding: .12rem .5rem; border-radius: 999px; border: 1px solid var(--border); color: var(--text2); }
    .wl-flag.on { color: var(--primary); border-color: var(--primary); }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-speedometer me-2"></i>Workload</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Active-task load vs. configured capacity &mdash; live</div>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <form method="GET">
            <select name="department" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:160px">
                <option value="">All departments</option>
                @foreach ($departments as $dept)
                    <option value="{{ $dept }}" @selected($department === $dept)>{{ $dept }}</option>
                @endforeach
            </select>
        </form>
        <a href="{{ route('performance.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-graph-up-arrow me-1"></i>Scoreboard</a>
        @can('manage performance')
            <a href="{{ route('performance.config') }}?tab=capacity" class="btn btn-sm btn-outline-secondary"><i class="bi bi-person-workspace me-1"></i>Set Capacity</a>
        @endcan
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-4">
        <div class="kpi-card">
            <div class="kpi-icon"><i class="bi bi-people"></i></div>
            <div><div class="kpi-val">{{ $configured }}</div><div class="kpi-lbl">With capacity set</div></div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="kpi-card">
            <div class="kpi-icon"><i class="bi bi-speedometer2"></i></div>
            <div><div class="kpi-val">{{ $avgUtil !== null ? $avgUtil . '%' : '—' }}</div><div class="kpi-lbl">Average utilization</div></div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="kpi-card">
            <div class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <div><div class="kpi-val">{{ $overloaded }}</div><div class="kpi-lbl">Overloaded</div></div>
        </div>
    </div>
</div>

<div class="d-flex gap-2 mb-3 flex-wrap" style="font-size:.72rem">
    <span class="wl-flag {{ $settings->auto_assign_enabled ? 'on' : '' }}">
        <i class="bi bi-{{ $settings->auto_assign_enabled ? 'check-circle' : 'circle' }} me-1"></i>Auto-assign {{ $settings->auto_assign_enabled ? 'on' : 'off' }}
    </span>
    <span class="wl-flag {{ $settings->strict_workload_limit ? 'on' : '' }}">
        <i class="bi bi-{{ $settings->strict_workload_limit ? 'check-circle' : 'circle' }} me-1"></i>Strict limit {{ $settings->strict_workload_limit ? 'on' : 'off' }}
    </span>
    @can('manage performance')
        <a href="{{ route('performance.config') }}?tab=settings" style="font-size:.72rem;color:var(--text3);text-decoration:none;align-self:center">Change in Settings ›</a>
    @endcan
</div>

<div class="card section-card">
    <div class="card-header py-3"><h6 class="fw-bold mb-0">Employees</h6></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="wlTable" class="table table-hover mb-0" style="width:100%;font-size:.85rem">
                <thead>
                    <tr>
                        <th class="ps-3">Employee</th>
                        <th>Department</th>
                        <th>Active tasks</th>
                        <th>Points</th>
                        <th>Capacity</th>
                        <th>Utilization</th>
                        <th class="pe-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td class="ps-3"><a class="wl-emp-link" href="{{ route('performance.show', $r['user']->id) }}">{{ $r['user']->name }}</a></td>
                            <td><span style="color:var(--text2)">{{ $r['department'] ?? '—' }}</span></td>
                            <td>{{ $r['active_tasks'] }}{{ $r['max_tasks'] ? ' / ' . $r['max_tasks'] : '' }}</td>
                            <td>{{ $r['points'] }}{{ $r['max_points'] ? ' / ' . $r['max_points'] : '' }}</td>
                            <td>
                                @if ($r['has_capacity'])
                                    <span style="color:var(--text2);font-size:.8rem">{{ $r['max_points'] ? $r['max_points'] . ' pts' : ($r['max_tasks'] ? $r['max_tasks'] . ' tasks' : 'no limit') }}</span>
                                @else
                                    <span style="color:var(--text3);font-size:.8rem">not set</span>
                                @endif
                            </td>
                            <td data-order="{{ $r['utilization'] ?? -1 }}">
                                @if ($r['utilization'] !== null)
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="wl-bar"><span style="width:{{ min($r['utilization'], 100) }}%;background:{{ $barColor($r['utilization']) }}"></span></div>
                                        <span style="font-size:.78rem;font-weight:600">{{ $r['utilization'] }}%</span>
                                    </div>
                                @else
                                    <span style="color:var(--text3)">&mdash;</span>
                                @endif
                            </td>
                            <td class="pe-3"><span class="spill {{ $statusSpill($r['status']) }}">{{ $r['status'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="cfg-help mt-2" style="font-size:.72rem;color:var(--text3)"><i class="bi bi-info-circle me-1"></i>Utilization uses max points when set, otherwise max active tasks. Employees without a capacity are shown but can't be auto-assigned or utilization-ranked.</div>
@endsection

@push('scripts')
<script>
    $(function () {
        $('#wlTable').DataTable({
            order: [[5, 'desc']], paging: true, pageLength: 25, lengthChange: false, info: false,
            language: { search: '', searchPlaceholder: 'Search employee…' },
            columnDefs: [{ orderable: false, targets: [6] }],
        });
    });
</script>
@endpush
