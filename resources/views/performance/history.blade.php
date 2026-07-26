@extends('layouts.app')
@section('title', 'Performance History')

@php
    $levelSpill = fn ($level) => match ($level) {
        'Excellent'         => 'spill-completed',
        'Very Good', 'Good' => 'spill-running',
        'Needs Improvement' => 'spill-warning',
        'Poor'              => 'spill-cancelled',
        default             => 'spill-hold',
    };
    $pct = fn ($v) => $v === null ? '<span style="color:var(--text3)">&mdash;</span>' : number_format((float) $v, 1) . '%';
    $fmtPeriod = fn ($p) => \Illuminate\Support\Carbon::createFromFormat('Y-m', $p)->format('M Y');
@endphp

@push('styles')
<style>
    #histTable td, #histTable th { vertical-align: middle; }
    .hist-rank { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; background: var(--surface2); color: var(--text2); font-size: .72rem; font-weight: 700; }
    .hist-rank.top { background: var(--primary); color: #fff; }
    .hist-emp-link { color: var(--text); text-decoration: none; font-weight: 600; }
    .hist-emp-link:hover { color: var(--primary); }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-clock-history me-2"></i>Performance History</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">
            Finalized monthly snapshots @if ($generatedAt)&middot; generated {{ $generatedAt->format('d M Y, H:i') }}@endif
        </div>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <a href="{{ route('performance.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-graph-up-arrow me-1"></i>Live Scoreboard</a>
        @if ($availablePeriods->isNotEmpty())
            <form method="GET">
                <select name="period" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:150px">
                    @foreach ($availablePeriods as $p)
                        <option value="{{ $p }}" @selected($period === $p)>{{ $fmtPeriod($p) }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>
</div>

@if ($snapshots->isEmpty())
    <div class="card section-card">
        <div class="card-body text-center py-5" style="color:var(--text3)">
            <i class="bi bi-camera" style="font-size:2rem"></i>
            <div class="mt-2" style="font-size:.9rem;color:var(--text2)">No snapshots have been generated yet.</div>
            <div class="mt-1" style="font-size:.8rem">
                They are captured automatically on the 1st of each month for the month just ended,
                or on demand with <code>php artisan performance:snapshot</code>.
            </div>
        </div>
    </div>
@else
    <div class="card section-card">
        <div class="card-header py-3"><h6 class="fw-bold mb-0">Scoreboard &mdash; {{ $fmtPeriod($period) }}</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="histTable" class="table table-hover mb-0" style="width:100%;font-size:.85rem">
                    <thead>
                        <tr>
                            <th class="ps-3">Rank</th>
                            <th>Employee</th>
                            <th>Task</th>
                            <th>On-time</th>
                            <th>Quality</th>
                            <th>Sales</th>
                            <th>Satisfaction</th>
                            <th>Final</th>
                            <th>Level</th>
                            <th class="pe-3">Team rank</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($snapshots as $s)
                            <tr>
                                <td class="ps-3"><span class="hist-rank {{ $s->rank_company === 1 ? 'top' : '' }}">{{ $s->rank_company }}</span></td>
                                <td><a class="hist-emp-link" href="{{ route('performance.show', ['user' => $s->user_id, 'period' => $s->period]) }}">{{ $s->user->name ?? '—' }}</a></td>
                                <td>{!! $pct($s->task_completion_score) !!}</td>
                                <td>{!! $pct($s->on_time_score) !!}</td>
                                <td>{!! $pct($s->revision_score) !!}</td>
                                <td>{!! $pct($s->sales_score) !!}</td>
                                <td>{!! $pct($s->satisfaction_score) !!}</td>
                                <td class="fw-bold">{{ number_format((float) $s->final_score, 1) }}</td>
                                <td><span class="spill {{ $levelSpill($s->performance_level) }}">{{ $s->performance_level }}</span></td>
                                <td class="pe-3">{{ $s->rank_department ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
@endsection

@push('scripts')
<script>
    $(function () {
        if ($('#histTable').length) {
            $('#histTable').DataTable({ order: [], paging: true, pageLength: 25, lengthChange: false, info: false, language: { search: '', searchPlaceholder: 'Search employee…' }, columnDefs: [{ orderable: false, targets: [8] }] });
        }
    });
</script>
@endpush
