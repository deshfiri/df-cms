@extends('layouts.app')
@section('title', 'Dashboard')

@push('styles')
<style>
.dash-kpi {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-md); padding: var(--space-4);
    transition: transform .18s, box-shadow .18s, border-color .18s, background .18s;
    text-decoration: none; display: block;
}
.dash-kpi:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0,0,0,.09);
    border-color: var(--primary);
}
.dash-kpi-icon {
    width: 40px; height: 40px; border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0;
}
.dash-kpi-val { font-size: var(--fs-h1); font-weight: var(--fw-bold); color: var(--text); line-height: 1; letter-spacing: -.04em; }
.dash-kpi-lbl { font-size: var(--fs-2xs); font-weight: var(--fw-medium); color: var(--text3); text-transform: uppercase; letter-spacing: .05em; margin-top: var(--space-1); }

.dash-widget { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); }
.dash-widget .wh { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: between; }
.dash-widget .wh h6 { font-size: var(--fs-sm); font-weight: var(--fw-semibold); color: var(--text); margin: 0; flex: 1; }

.emp-avatar {
    width: 30px; height: 30px; border-radius: 50%;
    background: rgba(var(--primary-rgb),.12); color: var(--primary);
    font-size: .71rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.act-dot {
    width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: .7rem; font-weight: 700;
    background: rgba(var(--primary-rgb),.1); color: var(--primary);
}
.chart-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); }
.chart-title { font-size: var(--fs-2xs); font-weight: var(--fw-semibold); text-transform: uppercase; letter-spacing: .06em; color: var(--text3); }

/* ── Workflow Pipeline visualization ─────────────────────────── */
.pipeline-track {
    display: flex; gap: var(--space-3); overflow-x: auto; padding: 2px 2px 10px;
    scroll-snap-type: x proximity;
}
.pipeline-track::-webkit-scrollbar { height: 5px; }
.pipeline-track::-webkit-scrollbar-thumb { background: var(--border); border-radius: 10px; }
.pipeline-card {
    flex: 0 0 188px; scroll-snap-align: start;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-md); padding: var(--space-4);
    position: relative; transition: border-color .15s, transform .15s;
}
.pipeline-card:hover { border-color: var(--primary); transform: translateY(-2px); }
.pipeline-card:not(:last-child)::after {
    content: ''; position: absolute; top: 44px; right: -14px; width: 12px; height: 2px;
    background: var(--border); z-index: 1;
}
.pipeline-icon {
    width: 34px; height: 34px; border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    background: rgba(var(--primary-rgb),.1); color: var(--primary); font-size: .92rem;
    margin-bottom: var(--space-3);
}
.pipeline-label { font-size: var(--fs-sm); font-weight: var(--fw-semibold); color: var(--text); }
.pipeline-count { font-size: var(--fs-h2); font-weight: var(--fw-bold); color: var(--text); line-height: 1; margin-top: var(--space-1); }
.pipeline-count-lbl { font-size: var(--fs-2xs); color: var(--text3); text-transform: uppercase; letter-spacing: .04em; }
.pipeline-progress-track { height: 5px; border-radius: 10px; background: var(--surface2); overflow: hidden; margin-top: var(--space-3); }
.pipeline-progress-fill { height: 100%; border-radius: 10px; background: var(--primary); transition: width .4s ease; }
.pipeline-progress-pct { font-size: var(--fs-2xs); color: var(--text3); margin-top: var(--space-1); }
.pipeline-delayed {
    display: inline-flex; align-items: center; gap: 3px;
    font-size: var(--fs-2xs); font-weight: var(--fw-semibold); color: var(--c-red);
    background: var(--c-red-bg); padding: 1px 7px; border-radius: 20px; margin-top: var(--space-2);
}

/* ── My Tasks ─────────────────────────────────────────────────── */
.mytask-row { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--border); }
.mytask-row:last-child { border-bottom: none; }
.mytask-priority-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

/* ── Dashboard dark mode overrides ─────────────────────────────── */
[data-theme="dark"] .dash-kpi { background: #111827; border-color: #1f2d40; box-shadow: 0 4px 20px rgba(0,0,0,.4), inset 0 1px 0 rgba(255,255,255,.04); }
[data-theme="dark"] .dash-kpi:hover { background: #1a2235 !important; border-color: #2a3d55 !important; box-shadow: 0 8px 28px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.06) !important; transform: translateY(-3px); }
[data-theme="dark"] .dash-kpi-icon i { filter: drop-shadow(0 0 8px currentColor); }
[data-theme="dark"] .dash-widget { background: #111827; border-color: #1f2d40; box-shadow: 0 4px 20px rgba(0,0,0,.4), inset 0 1px 0 rgba(255,255,255,.04); }
[data-theme="dark"] .dash-widget .wh { border-bottom-color: #1f2d40; }
[data-theme="dark"] .dash-widget .border-bottom { border-bottom-color: #1a2a38 !important; }
[data-theme="dark"] .chart-card { background: #111827; border-color: #1f2d40; box-shadow: 0 4px 20px rgba(0,0,0,.4); }
[data-theme="dark"] .chart-title { color: var(--text3); letter-spacing: .08em; }
[data-theme="dark"] .pipeline-card { background: #111827; border-color: #1f2d40; }
[data-theme="dark"] .pipeline-card:hover { border-color: #3B82F6; }
[data-theme="dark"] .mytask-row { border-bottom-color: #1a2a38; }

/* ── Mobile ───────────────────────────────────────────────────── */
@media (max-width: 767.98px) {
    .dash-kpi-val { font-size: 1.35rem; }
    .pipeline-card { flex: 0 0 160px; padding: var(--space-3); }
}
</style>
@endpush

@section('content')
{{-- ── Header ─────────────────────────────────────────────────── --}}
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0">Executive Dashboard</h4>
        <small style="color:var(--text3)">{{ now()->format('l, d F Y') }} &nbsp;·&nbsp; {{ now()->format('h:i A') }}</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('clients.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Client</a>
        <a href="{{ route('import.index') }}" class="btn btn-sm" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)"><i class="bi bi-upload me-1"></i>Import</a>
    </div>
</div>

{{-- ── 1. Top KPI Row ──────────────────────────────────────────── --}}
@php
$activeCount = ($statusCounts['Running'] ?? 0) + ($statusCounts['Warning'] ?? 0);
$topCards = [
    ['label'=>'Total Clients',   'value'=>$total,                     'icon'=>'bi-people-fill',       'bg'=>'rgba(var(--primary-rgb),.1)', 'ic'=>'var(--primary)', 'href'=>route('clients.index')],
    ['label'=>'Active Clients',  'value'=>$activeCount,                'icon'=>'bi-play-circle-fill',  'bg'=>'var(--c-green-bg)', 'ic'=>'var(--c-green)', 'href'=>route('clients.index').'?status=Running'],
    ['label'=>'Delayed Clients', 'value'=>$delayedCount,                'icon'=>'bi-alarm-fill',        'bg'=>'var(--c-red-bg)',   'ic'=>'var(--c-red)',   'href'=>route('clients.index')],
    ['label'=>'Completed',       'value'=>$statusCounts['Completed'] ?? 0, 'icon'=>'bi-check-circle-fill', 'bg'=>'var(--c-blue-bg)', 'ic'=>'var(--c-blue)', 'href'=>route('clients.index').'?status=Completed'],
];
@endphp
<div class="row g-3 mb-4">
    @foreach($topCards as $c)
    <div class="col-6 col-md-3">
        <a href="{{ $c['href'] }}" class="dash-kpi">
            <div class="d-flex align-items-center gap-3">
                <div class="dash-kpi-icon" style="background:{{ $c['bg'] }}"><i class="bi {{ $c['icon'] }}" style="color:{{ $c['ic'] }}"></i></div>
                <div class="flex-fill">
                    <div class="dash-kpi-val">{{ number_format($c['value']) }}</div>
                    <div class="dash-kpi-lbl">{{ $c['label'] }}</div>
                </div>
            </div>
        </a>
    </div>
    @endforeach
</div>

{{-- ── 2. Workflow Pipeline ────────────────────────────────────── --}}
<div class="dash-widget mb-4">
    <div class="wh d-flex align-items-center justify-content-between">
        <h6><i class="bi bi-diagram-3-fill me-2" style="color:var(--primary)"></i>Workflow Pipeline</h6>
        <span style="font-size:var(--fs-2xs);color:var(--text3)">{{ $activeCount }} active client{{ $activeCount != 1 ? 's' : '' }}</span>
    </div>
    <div class="p-3">
        @php
        $segmentIcons = [
            'Deal' => 'bi-briefcase-fill', 'Meeting' => 'bi-calendar-event-fill', 'Documents' => 'bi-file-earmark-text-fill',
            'Design' => 'bi-palette-fill', 'Website' => 'bi-globe', 'Products' => 'bi-box-seam-fill',
            'Marketing' => 'bi-megaphone-fill', 'Support' => 'bi-headset',
        ];
        @endphp
        <div class="pipeline-track">
            @foreach($pipeline as $seg)
            <div class="pipeline-card">
                <div class="pipeline-icon"><i class="bi {{ $segmentIcons[$seg['label']] ?? 'bi-circle' }}"></i></div>
                <div class="pipeline-label">{{ $seg['label'] }}</div>
                <div class="pipeline-count">{{ $seg['active'] }}</div>
                <div class="pipeline-count-lbl">in progress</div>
                <div class="pipeline-progress-track"><div class="pipeline-progress-fill" style="width:{{ $seg['progress'] }}%"></div></div>
                <div class="pipeline-progress-pct">{{ $seg['progress'] }}% of clients cleared</div>
                @if($seg['delayed'] > 0)
                <div class="pipeline-delayed"><i class="bi bi-exclamation-triangle-fill"></i>{{ $seg['delayed'] }} delayed</div>
                @endif
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ── 3. My Tasks / 4. Recent Activity / 5. Upcoming Meetings ─── --}}
<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="dash-widget h-100">
            <div class="wh d-flex align-items-center justify-content-between">
                <h6><i class="bi bi-list-check me-2" style="color:var(--primary)"></i>My Tasks</h6>
                <a href="{{ route('tasks.index') }}" class="btn btn-sm px-2 py-1" style="font-size:.69rem;background:rgba(var(--primary-rgb),.08);color:var(--primary);border:none">View All</a>
            </div>
            <div style="max-height:320px;overflow-y:auto">
                @php $priorityColor = ['Low'=>'var(--c-slate)','Medium'=>'var(--c-blue)','High'=>'var(--c-yellow)','Urgent'=>'var(--c-red)']; @endphp
                @forelse($myTasks as $t)
                <div class="mytask-row">
                    <span class="mytask-priority-dot" style="background:{{ $priorityColor[$t->priority] ?? 'var(--text3)' }}"></span>
                    <div class="flex-fill min-w-0">
                        <div class="fw-semibold text-truncate" style="font-size:.79rem;color:var(--text)">{{ $t->title }}</div>
                        <div class="text-truncate" style="font-size:.68rem;color:var(--text3)">{{ $t->client?->client_name ?? '—' }} @if($t->due_date) · Due {{ $t->due_date->format('d M') }} @endif</div>
                    </div>
                    @if($t->is_overdue)
                    <span class="spill spill-rejected" style="font-size:.6rem">Overdue</span>
                    @endif
                </div>
                @empty
                <div class="empty-state py-4"><i class="bi bi-check2-circle" style="font-size:1.6rem;color:var(--text3)"></i><p style="font-size:.78rem">No open tasks assigned to you</p></div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="dash-widget h-100">
            <div class="wh"><h6><i class="bi bi-activity me-2" style="color:var(--primary)"></i>Recent Activity</h6></div>
            <div class="p-3" style="max-height:320px;overflow-y:auto">
                @forelse($recentActivities->take(8) as $log)
                <div class="d-flex gap-2 mb-3">
                    <div class="act-dot">{{ strtoupper(substr($log->user?->name ?? 'S', 0, 1)) }}</div>
                    <div class="min-w-0">
                        <div style="font-size:.77rem;color:var(--text)">
                            <strong>{{ $log->user?->name ?? 'System' }}</strong>
                            <span style="color:var(--text2)"> {{ strtolower($log->action) }}</span>
                            @if($log->client)
                                — <a href="{{ route('clients.show', $log->client) }}" style="color:var(--primary);text-decoration:none;font-weight:600">{{ $log->client->client_name }}</a>
                            @endif
                        </div>
                        <div style="font-size:.68rem;color:var(--text3)">{{ $log->created_at->diffForHumans() }}</div>
                    </div>
                </div>
                @empty
                <div class="empty-state py-3"><i class="bi bi-activity"></i><p>No activity yet</p></div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="dash-widget h-100">
            <div class="wh d-flex align-items-center justify-content-between">
                <h6><i class="bi bi-calendar-event me-2" style="color:var(--primary)"></i>Upcoming Meetings</h6>
                <a href="{{ route('meetings.all') }}" class="btn btn-sm px-2 py-1" style="font-size:.69rem;background:rgba(var(--primary-rgb),.08);color:var(--primary);border:none">View All</a>
            </div>
            <div style="max-height:320px;overflow-y:auto">
                @forelse($upcomingMeetings as $m)
                <div class="d-flex align-items-start gap-2 py-2 px-3 border-bottom" style="border-color:var(--border)">
                    <div class="text-center flex-shrink-0" style="width:36px">
                        <div style="font-size:.95rem;font-weight:700;color:var(--primary);line-height:1">{{ $m->scheduled_at->format('d') }}</div>
                        <div style="font-size:.6rem;color:var(--text3);text-transform:uppercase">{{ $m->scheduled_at->format('M') }}</div>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <a href="{{ route('clients.show', $m->client) }}" class="d-block fw-semibold text-truncate" style="font-size:.78rem;color:var(--text);text-decoration:none">{{ $m->title }}</a>
                        <div class="text-truncate" style="font-size:.68rem;color:var(--text3)"><i class="bi {{ $m->type_icon }} me-1"></i>{{ $m->client->client_name ?? '—' }}</div>
                    </div>
                    <div class="flex-shrink-0" style="font-size:.66rem;color:var(--text3);white-space:nowrap">{{ $m->scheduled_at->format('h:i A') }}</div>
                </div>
                @empty
                <div class="empty-state py-3"><i class="bi bi-calendar-x"></i><p>No upcoming meetings</p></div>
                @endforelse
            </div>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════
     Secondary analytics — same data as before, now below the fold
     ══════════════════════════════════════════════════════════════ --}}
<div class="section-title mb-3 mt-2">Operations & Analytics</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="dash-kpi">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span style="font-size:.72rem;font-weight:500;color:var(--text3)">Today's Updates</span>
                <i class="bi bi-arrow-repeat" style="color:var(--primary)"></i>
            </div>
            <div class="dash-kpi-val" style="font-size:1.3rem;color:var(--text2)">{{ number_format($todayUpdates) }}</div>
            <div class="dash-kpi-lbl">Product updates logged today</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="dash-kpi">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span style="font-size:.72rem;font-weight:500;color:var(--text3)">Today's Payments</span>
                <i class="bi bi-cash-stack c-green"></i>
            </div>
            <div class="dash-kpi-val c-green" style="font-size:1.3rem">৳{{ number_format($todayPayments, 0) }}</div>
            <div class="dash-kpi-lbl">{{ $todayPaymentCount }} payment{{ $todayPaymentCount != 1 ? 's' : '' }} received</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="dash-kpi">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span style="font-size:.72rem;font-weight:500;color:var(--text3)">Pending Payments</span>
                <i class="bi bi-credit-card c-yellow"></i>
            </div>
            <div class="dash-kpi-val c-yellow" style="font-size:1.3rem">{{ number_format($pendingPayments) }}</div>
            <div class="dash-kpi-lbl">৳{{ number_format($pendingPaymentAmount, 0) }} outstanding</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <a href="{{ route('clients.index') }}?no_update=1" class="dash-kpi" style="text-decoration:none">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span style="font-size:.72rem;font-weight:500;color:var(--text3)">No Update (30d)</span>
                <i class="bi bi-person-exclamation c-rose"></i>
            </div>
            <div class="dash-kpi-val c-rose" style="font-size:1.3rem">{{ number_format($clientsWithoutUpdate) }}</div>
            <div class="dash-kpi-lbl">Active clients need attention</div>
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="chart-card p-3">
            <div class="chart-title mb-3">Monthly Onboarding — {{ date('Y') }}</div>
            <div style="height:180px"><canvas id="monthlyChart"></canvas></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="chart-card p-3">
            <div class="chart-title mb-3">Payment Collections — {{ date('Y') }}</div>
            <div style="height:180px"><canvas id="paymentChart"></canvas></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-7">
        <div class="chart-card p-3">
            <div class="chart-title mb-3">Workflow Stage Completion (detailed)</div>
            <div style="height:180px"><canvas id="workflowChart"></canvas></div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="chart-card p-3 d-flex flex-column">
            <div class="chart-title mb-3">Category Distribution</div>
            <div class="flex-fill d-flex align-items-center justify-content-center" style="height:180px">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="dash-widget h-100">
            <div class="wh d-flex align-items-center justify-content-between">
                <h6><i class="bi bi-people me-2" style="color:var(--primary)"></i>Recent Clients</h6>
                <a href="{{ route('clients.index') }}" class="btn btn-sm px-2 py-1" style="font-size:.69rem;background:rgba(var(--primary-rgb),.08);color:var(--primary);border:none">View All</a>
            </div>
            <div style="overflow-x:auto">
                <table class="table align-middle mb-0" style="font-size:.78rem">
                    <tbody>
                        @forelse($recent as $c)
                        <tr>
                            <td class="ps-3 py-2">
                                <a href="{{ route('clients.show', $c) }}" class="fw-semibold d-block lh-1" style="color:var(--text);text-decoration:none">{{ $c->client_name }}</a>
                                <span style="font-size:.72rem;color:var(--text3)">{{ $c->dfid_number }}</span>
                            </td>
                            <td class="text-center">
                                @php $spCls = ['Running'=>'spill-running','Warning'=>'spill-warning','Completed'=>'spill-completed','Hold'=>'spill-hold','Cancelled'=>'spill-cancelled'][$c->client_status] ?? 'spill-hold'; @endphp
                                <span class="spill {{ $spCls }}">{{ $c->client_status }}</span>
                            </td>
                            <td class="pe-3 text-end" style="font-size:.72rem;color:var(--text3)">{{ $c->joining_date?->format('d M y') ?? '—' }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="3" class="empty-state"><i class="bi bi-people" style="font-size:1.6rem;color:var(--text3)"></i><p>No clients yet</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="row g-3 h-100">
            <div class="col-12">
                <div class="dash-widget">
                    <div class="row g-0 text-center">
                        <div class="col-4 p-3 border-end" style="border-color:var(--border)">
                            <div class="fw-bold c-green" style="font-size:1.1rem">৳{{ number_format($thisMonthPayments, 0) }}</div>
                            <div style="font-size:.68rem;color:var(--text3);margin-top:2px">This Month</div>
                        </div>
                        <div class="col-4 p-3 border-end" style="border-color:var(--border)">
                            <div class="fw-bold" style="font-size:1.1rem;color:var(--text2)">৳{{ number_format($lastMonthPayments, 0) }}</div>
                            <div style="font-size:.68rem;color:var(--text3);margin-top:2px">Last Month</div>
                        </div>
                        <div class="col-4 p-3">
                            @php $growthClass = $paymentGrowth >= 0 ? 'c-green' : 'c-red'; $growthIcon = $paymentGrowth >= 0 ? 'bi-arrow-up' : 'bi-arrow-down'; @endphp
                            <div class="fw-bold d-flex align-items-center justify-content-center gap-1 {{ $growthClass }}" style="font-size:1.1rem">
                                <i class="bi {{ $growthIcon }}"></i>{{ abs($paymentGrowth) }}%
                            </div>
                            <div style="font-size:.68rem;color:var(--text3);margin-top:2px">Growth</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="dash-widget h-100">
                    <div class="wh"><h6><i class="bi bi-award me-2 c-yellow"></i>Top Employees</h6></div>
                    <div class="p-3">
                        @forelse($topEmployees as $emp)
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="emp-avatar">{{ strtoupper(substr($emp->name, 0, 1)) }}</div>
                                <span style="font-size:.79rem;color:var(--text)">{{ $emp->name }}</span>
                            </div>
                            <span class="spill spill-running" style="font-size:.65rem">{{ $emp->client_count }}</span>
                        </div>
                        @empty
                        <div class="empty-state py-3"><p>No data available</p></div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="dash-widget h-100">
                    <div class="wh"><h6><i class="bi bi-lightning-charge me-2" style="color:var(--primary)"></i>Quick Actions</h6></div>
                    <div class="p-3 d-grid gap-2">
                        <a href="{{ route('clients.create') }}" class="btn btn-sm text-start" style="background:rgba(var(--primary-rgb),.08);color:var(--primary);border:1px solid rgba(var(--primary-rgb),.15)"><i class="bi bi-person-plus me-2"></i>Add New Client</a>
                        <a href="{{ route('meetings.book') }}" class="btn btn-sm text-start" style="background:var(--secondary-bg);color:var(--secondary);border:1px solid var(--secondary-bg)"><i class="bi bi-calendar-plus me-2"></i>Book Meeting</a>
                        <a href="{{ route('import.index') }}" class="btn btn-sm text-start c-green" style="background:var(--c-green-bg);border:1px solid var(--c-green-bg)"><i class="bi bi-upload me-2"></i>Import Excel</a>
                        <a href="{{ route('clients.index') }}?status=Warning" class="btn btn-sm text-start c-yellow" style="background:var(--c-yellow-bg);border:1px solid var(--c-yellow-bg)"><i class="bi bi-exclamation-triangle me-2"></i>View Warning Clients</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="dash-widget">
            <div class="wh d-flex align-items-center justify-content-between">
                <h6><i class="bi bi-upload me-2 c-green"></i>Recent Imports</h6>
                <a href="{{ route('import.index') }}" class="btn btn-sm px-2 py-1 c-green" style="font-size:.69rem;background:var(--c-green-bg);border:none">Import</a>
            </div>
            <div style="overflow-x:auto">
                <table class="table align-middle mb-0" style="font-size:.77rem">
                    <tbody>
                        @forelse($recentImports as $imp)
                        <tr>
                            <td class="ps-3 py-2">
                                <div class="fw-semibold lh-1" style="color:var(--text)">{{ Str::limit($imp->file_name, 22) }}</div>
                                <span style="font-size:.7rem;color:var(--text3)">{{ $imp->user?->name ?? '—' }} · {{ $imp->created_at->diffForHumans() }}</span>
                            </td>
                            <td class="text-center">
                                @if($imp->success_rows)
                                <span class="spill spill-running" style="font-size:.64rem">{{ $imp->success_rows }} new</span>
                                @endif
                                @if($imp->updated_rows)
                                <span class="spill spill-completed" style="font-size:.64rem">{{ $imp->updated_rows }} upd</span>
                                @endif
                            </td>
                            <td class="pe-3">
                                @php $spMap = ['completed'=>'spill-running','failed'=>'spill-cancelled','processing'=>'spill-warning','pending'=>'spill-hold']; @endphp
                                <span class="spill {{ $spMap[$imp->status] ?? 'spill-hold' }}">{{ ucfirst($imp->status) }}</span>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="3" class="empty-state py-3"><i class="bi bi-upload" style="font-size:1.4rem;color:var(--text3)"></i><p>No imports yet</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="dash-widget h-100">
            <div class="wh"><h6><i class="bi bi-calendar-check me-2" style="color:var(--primary)"></i>Meetings Snapshot</h6></div>
            <div class="row g-0 text-center">
                <div class="col-4 p-3 border-end" style="border-color:var(--border)">
                    <div class="fw-bold" style="font-size:1.1rem;color:var(--text)">{{ $scheduledMeetingsCount }}</div>
                    <div style="font-size:.68rem;color:var(--text3);margin-top:2px">Scheduled</div>
                </div>
                <div class="col-4 p-3 border-end" style="border-color:var(--border)">
                    <div class="fw-bold c-green" style="font-size:1.1rem">{{ $todayMeetingsCount }}</div>
                    <div style="font-size:.68rem;color:var(--text3);margin-top:2px">Today</div>
                </div>
                <div class="col-4 p-3">
                    <div class="fw-bold c-blue" style="font-size:1.1rem">{{ \App\Models\ClientMeeting::where('status','Completed')->count() }}</div>
                    <div style="font-size:.68rem;color:var(--text3);margin-top:2px">Completed (all time)</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
var ct = chartTheme();
var primaryRgb = getComputedStyle(document.documentElement).getPropertyValue('--primary-rgb').trim() || '37,99,235';
var isDark = document.documentElement.getAttribute('data-theme') === 'dark';

Chart.defaults.color       = ct.textColor;
Chart.defaults.borderColor = ct.gridColor;
Chart.defaults.font        = { family: 'Inter, sans-serif', size: 11 };

var monthCtx = document.getElementById('monthlyChart').getContext('2d');
var monthlyChart = new Chart(monthCtx, {
    type: 'bar',
    data: {
        labels: @json($monthlyData['labels']),
        datasets: [{
            label: 'New Clients',
            data: @json($monthlyData['data']),
            backgroundColor: isDark
                ? function(ctx) { var g = monthCtx.createLinearGradient(0,0,0,200); g.addColorStop(0,'rgba(59,130,246,.85)'); g.addColorStop(1,'rgba(59,130,246,.3)'); return g; }
                : 'rgba(' + primaryRgb + ',.75)',
            borderRadius: 6,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c){ return ' ' + c.parsed.y + ' clients'; } } } },
        scales: {
            x: { grid: { color: ct.gridColor, drawTicks: false }, ticks: { color: ct.textColor, padding: 6 }, border: { display: false } },
            y: { beginAtZero: true, grid: { color: ct.gridColor }, ticks: { color: ct.textColor, stepSize: 1, padding: 8 }, border: { display: false } }
        }
    }
});

var payCtx = document.getElementById('paymentChart').getContext('2d');
var payGrad = payCtx.createLinearGradient(0, 0, 0, 180);
payGrad.addColorStop(0, isDark ? 'rgba(34,197,94,.3)' : 'rgba(5,150,105,.18)');
payGrad.addColorStop(1, 'rgba(0,0,0,0)');
var paymentChart = new Chart(payCtx, {
    type: 'line',
    data: {
        labels: @json($monthlyPayData['labels']),
        datasets: [{
            label: 'Collection (৳)',
            data: @json($monthlyPayData['data']),
            borderColor: isDark ? '#22C55E' : '#059669',
            borderWidth: 2.5,
            backgroundColor: payGrad,
            fill: true, tension: .45,
            pointRadius: 4,
            pointBackgroundColor: isDark ? '#22C55E' : '#059669',
            pointBorderColor: isDark ? '#111827' : '#fff',
            pointBorderWidth: 2,
            pointHoverRadius: 6,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: ct.gridColor, drawTicks: false }, ticks: { color: ct.textColor, padding: 6 }, border: { display: false } },
            y: { beginAtZero: true, grid: { color: ct.gridColor }, ticks: { color: ct.textColor, padding: 8 }, border: { display: false } }
        }
    }
});

var wfColors = isDark
    ? ['#3B82F6','#F43F5E','#06B6D4','#F59E0B','#A78BFA','#22C55E']
    : ['#2563eb','#e11d48','#0891b2','#d97706','#7c3aed','#059669'];
var workflowChart = new Chart(document.getElementById('workflowChart'), {
    type: 'bar',
    data: {
        labels: @json($workflowData['labels']),
        datasets: [{
            label: 'Completed',
            data: @json($workflowData['data']),
            backgroundColor: wfColors,
            borderRadius: 6,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        indexAxis: 'y',
        scales: {
            x: { beginAtZero: true, grid: { color: ct.gridColor, drawTicks: false }, ticks: { color: ct.textColor, stepSize: 1, padding: 8 }, border: { display: false } },
            y: { grid: { display: false }, ticks: { color: ct.textColor, font: { size: 11 }, padding: 6 }, border: { display: false } }
        }
    }
});

var catColors = isDark
    ? ['#3B82F6','#F43F5E','#06B6D4','#F59E0B','#A78BFA','#22C55E','#EF4444','#60A5FA','#FB923C','#94A3B8']
    : ['#2563eb','#e11d48','#0891b2','#d97706','#7c3aed','#059669','#dc2626','#3b82f6','#ea580c','#64748b'];
var categoryChart = new Chart(document.getElementById('categoryChart'), {
    type: 'doughnut',
    data: {
        labels: @json($categoryData['labels']),
        datasets: [{
            data: @json($categoryData['data']),
            backgroundColor: catColors,
            borderWidth: isDark ? 2 : 2,
            borderColor: isDark ? '#111827' : '#fff',
            hoverBorderColor: isDark ? '#1a2235' : '#f8fafc',
            hoverOffset: 4,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { font: { size: 10, family: 'Inter, sans-serif' }, boxWidth: 10, padding: 10, color: ct.textColor }
            }
        }
    }
});

window._charts = [monthlyChart, paymentChart, workflowChart, categoryChart];
</script>
@endpush
