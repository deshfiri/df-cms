@extends('layouts.app')
@section('title', 'Performance Configuration')

@php
    $g = $global; // global KPI weight config or null
    // Mirrors PerformanceCalculationService::resolveWeights()'s fallback
    // exactly, so an admin who has never saved a profile sees the same
    // numbers the scoring engine is already using, and the total reads
    // 100 the moment the page opens.
    $gw = [
        'task_completion_weight' => $g->task_completion_weight ?? 18,
        'on_time_weight'         => $g->on_time_weight ?? 18,
        'revision_weight'        => $g->revision_weight ?? 12,
        'sales_weight'           => $g->sales_weight ?? 11,
        'satisfaction_weight'    => $g->satisfaction_weight ?? 11,
        'client_care_weight'     => $g->client_care_weight ?? 11,
        'daily_target_weight'    => $g->daily_target_weight ?? 9,
        'task_volume_weight'     => $g->task_volume_weight ?? 10,
    ];
@endphp

@push('styles')
<style>
    .cfg-tabs .nav-link { color: var(--text2); font-size: .85rem; font-weight: 600; border: 0; border-bottom: 2px solid transparent; }
    .cfg-tabs .nav-link.active { color: var(--primary); background: transparent; border-bottom-color: var(--primary); }
    .wt-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .75rem; }
    .wt-field label { font-size: .72rem; color: var(--text2); font-weight: 600; }
    .wt-sum { font-size: .8rem; font-weight: 700; }
    .wt-sum.ok { color: var(--primary); }
    .wt-sum.bad { color: #dc3545; }
    .cfg-help { font-size: .72rem; color: var(--text3); }
    .cfg-section-title { font-size: .78rem; font-weight: 700; color: var(--text2); text-transform: uppercase; letter-spacing: .03em; margin-bottom: .5rem; }
    #capTable td, #targetTable td { vertical-align: middle; }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-sliders me-2"></i>Performance Configuration</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Sales targets, KPI weights, scoring settings &amp; employee capacity</div>
    </div>
    <a href="{{ route('performance.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-graph-up-arrow me-1"></i>Back to Scoreboard
    </a>
</div>

<ul class="nav nav-tabs cfg-tabs mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-targets" data-tab="targets" type="button"><i class="bi bi-bullseye me-1"></i>Sales Targets</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-weights" data-tab="weights" type="button"><i class="bi bi-percent me-1"></i>KPI Weights</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-settings" data-tab="settings" type="button"><i class="bi bi-gear me-1"></i>Settings</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-capacity" data-tab="capacity" type="button"><i class="bi bi-person-workspace me-1"></i>Capacity</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-daily-targets" data-tab="daily-targets" type="button"><i class="bi bi-check2-circle me-1"></i>Daily Targets</button></li>
</ul>

<div class="tab-content">
    {{-- ── Sales Targets ─────────────────────────────────────────────── --}}
    <div class="tab-pane fade show active" id="tab-targets">
        <div class="card section-card">
            <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="fw-bold mb-0">Sales Targets</h6>
                <div class="d-flex gap-2">
                    <select id="targetPeriodFilter" class="form-select form-select-sm" style="min-width:150px">
                        <option value="">All periods</option>
                        @foreach ($periods as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <button class="btn btn-sm btn-primary" id="btnAddTarget"><i class="bi bi-plus-lg me-1"></i>Set Target</button>
                </div>
            </div>
            <div class="card-body p-0">
                <table id="targetTable" class="table table-hover mb-0" style="width:100%;font-size:.85rem">
                    <thead>
                        <tr><th class="ps-3">Employee</th><th>Period</th><th>Target</th><th>Last updated</th><th class="pe-3">Actions</th></tr>
                    </thead>
                </table>
            </div>
        </div>
        <div class="cfg-help mt-2"><i class="bi bi-info-circle me-1"></i>Sales achievement is the paid-payment total for clients assigned to the employee in the period. With no target set, that KPI is excluded from their score.</div>
    </div>

    {{-- ── KPI Weights ───────────────────────────────────────────────── --}}
    <div class="tab-pane fade" id="tab-weights">
        <div class="card section-card mb-3">
            <div class="card-header py-3"><h6 class="fw-bold mb-0">Global default weights</h6></div>
            <div class="card-body">
                <p class="cfg-help mb-3">Applied to every employee unless a department or employee override exists. Only the KPIs an employee actually has data for are scored — remaining weights are re-normalised automatically. Weights must total 100.</p>
                <form id="globalWeightForm">
                    <input type="hidden" name="scope_type" value="global">
                    <div class="wt-grid mb-3">
                        @foreach (['task_completion_weight' => 'Task Completion', 'on_time_weight' => 'On-Time', 'revision_weight' => 'Quality', 'sales_weight' => 'Sales', 'satisfaction_weight' => 'Satisfaction', 'client_care_weight' => 'Client Care', 'daily_target_weight' => 'Daily Target', 'task_volume_weight' => 'Task Volume'] as $field => $label)
                            <div class="wt-field">
                                <label>{{ $label }}</label>
                                <input type="number" min="0" max="100" name="{{ $field }}" value="{{ $gw[$field] }}" class="form-control form-control-sm wt-input">
                            </div>
                        @endforeach
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="wt-sum">Total: <span class="wt-sum-val">100</span></span>
                        <button type="submit" class="btn btn-sm btn-primary">Save global weights</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card section-card">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">Overrides</h6>
                <button class="btn btn-sm btn-primary" id="btnAddOverride"><i class="bi bi-plus-lg me-1"></i>Add Override</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:.85rem">
                        <thead>
                            <tr><th class="ps-3">Scope</th><th>Task</th><th>On-time</th><th>Quality</th><th>Sales</th><th>Satisfaction</th><th>Client care</th><th>Daily target</th><th>Task volume</th><th class="pe-3">Actions</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($overrides as $ov)
                                @php $c = $ov['config']; @endphp
                                <tr>
                                    <td class="ps-3">
                                        <span class="spill {{ $c->scope_type === 'employee' ? 'spill-running' : 'spill-hold' }}">{{ ucfirst($c->scope_type) }}</span>
                                        <span class="ms-1">{{ $ov['label'] }}</span>
                                    </td>
                                    <td>{{ $c->task_completion_weight }}</td>
                                    <td>{{ $c->on_time_weight }}</td>
                                    <td>{{ $c->revision_weight }}</td>
                                    <td>{{ $c->sales_weight }}</td>
                                    <td>{{ $c->satisfaction_weight }}</td>
                                    <td>{{ $c->client_care_weight }}</td>
                                    <td>{{ $c->daily_target_weight }}</td>
                                    <td>{{ $c->task_volume_weight }}</td>
                                    <td class="pe-3">
                                        <button class="btn btn-sm btn-outline-secondary btn-ov-edit"
                                            data-scope-type="{{ $c->scope_type }}" data-scope-value="{{ $c->scope_value }}"
                                            data-task="{{ $c->task_completion_weight }}" data-ontime="{{ $c->on_time_weight }}"
                                            data-revision="{{ $c->revision_weight }}" data-sales="{{ $c->sales_weight }}"
                                            data-satisfaction="{{ $c->satisfaction_weight }}" data-clientcare="{{ $c->client_care_weight }}"
                                            data-dailytarget="{{ $c->daily_target_weight }}" data-taskvolume="{{ $c->task_volume_weight }}"><i class="bi bi-pencil"></i></button>
                                        <button class="btn btn-sm btn-outline-danger btn-ov-delete" data-id="{{ $c->id }}"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="text-center py-4" style="color:var(--text3)">No overrides — everyone uses the global weights.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Settings ──────────────────────────────────────────────────── --}}
    <div class="tab-pane fade" id="tab-settings">
        <div class="card section-card">
            <div class="card-header py-3"><h6 class="fw-bold mb-0">Scoring &amp; workload settings</h6></div>
            <div class="card-body">
                <form method="POST" action="{{ route('performance.config.settings') }}">
                    @csrf
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="cfg-section-title">Task priority weights (workload points)</div>
                            <div class="row g-2">
                                @foreach (['task_weight_low' => 'Low', 'task_weight_medium' => 'Medium', 'task_weight_high' => 'High', 'task_weight_critical' => 'Urgent'] as $field => $label)
                                    <div class="col-3">
                                        <label class="wt-field"><span style="font-size:.72rem;color:var(--text2);font-weight:600">{{ $label }}</span></label>
                                        <input type="number" min="0" max="255" name="{{ $field }}" value="{{ $settings->$field }}" class="form-control form-control-sm">
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="cfg-section-title">Workload thresholds (%)</div>
                            <div class="row g-2">
                                @foreach (['overload_threshold_pct' => 'Overloaded ≥', 'busy_threshold_pct' => 'Busy ≥', 'available_threshold_pct' => 'Normal ≥'] as $field => $label)
                                    <div class="col-4">
                                        <label style="font-size:.72rem;color:var(--text2);font-weight:600">{{ $label }}</label>
                                        <input type="number" min="0" max="1000" name="{{ $field }}" value="{{ $settings->$field }}" class="form-control form-control-sm">
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="cfg-section-title">Alert thresholds</div>
                            <div class="row g-2">
                                <div class="col-4">
                                    <label style="font-size:.72rem;color:var(--text2);font-weight:600">Revision rate %</label>
                                    <input type="number" min="0" max="100" name="revision_rate_alert_pct" value="{{ $settings->revision_rate_alert_pct }}" class="form-control form-control-sm">
                                </div>
                                <div class="col-4">
                                    <label style="font-size:.72rem;color:var(--text2);font-weight:600">KPI drop pts</label>
                                    <input type="number" min="0" max="100" name="kpi_drop_alert_points" value="{{ $settings->kpi_drop_alert_points }}" class="form-control form-control-sm">
                                </div>
                                <div class="col-4">
                                    <label style="font-size:.72rem;color:var(--text2);font-weight:600">Overdue count</label>
                                    <input type="number" min="0" max="1000" name="overdue_alert_count" value="{{ $settings->overdue_alert_count }}" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="cfg-section-title">Client care</div>
                            <div class="row g-2">
                                <div class="col-5">
                                    <label for="client_care_target_points" style="font-size:.72rem;color:var(--text2);font-weight:600">Monthly target (points)</label>
                                    <input type="number" min="1" max="10000" id="client_care_target_points" name="client_care_target_points" value="{{ $settings->client_care_target_points ?? 20 }}" class="form-control form-control-sm">
                                </div>
                                <div class="col-7 cfg-help" style="padding-top:1.3rem">
                                    {{ \App\Services\Performance\PerformanceCalculationService::CLIENT_ADDED_POINTS }} points per client added by hand, 1 per day of real work on one of your clients
                                    (at most {{ \App\Services\Performance\PerformanceCalculationService::UPKEEP_DAYS_CAP_PER_CLIENT }} days per client a month).
                                    Reaching the target is 100% activity; the score also counts how many of your active clients were looked after.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="cfg-section-title">Toggles</div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="count_cancelled_against_kpi" name="count_cancelled_against_kpi" value="1" @checked($settings->count_cancelled_against_kpi)>
                                <label class="form-check-label" style="font-size:.82rem" for="count_cancelled_against_kpi">Count cancelled tasks against completion KPI</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="strict_workload_limit" name="strict_workload_limit" value="1" @checked($settings->strict_workload_limit)>
                                <label class="form-check-label" style="font-size:.82rem" for="strict_workload_limit">Enforce strict workload limit</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="auto_assign_enabled" name="auto_assign_enabled" value="1" @checked($settings->auto_assign_enabled)>
                                <label class="form-check-label" style="font-size:.82rem" for="auto_assign_enabled">Enable auto-assignment</label>
                            </div>
                        </div>
                    </div>
                    <div class="text-end mt-3">
                        <button type="submit" class="btn btn-sm btn-primary">Save settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ── Capacity ──────────────────────────────────────────────────── --}}
    <div class="tab-pane fade" id="tab-capacity">
        <div class="card section-card">
            <div class="card-header py-3"><h6 class="fw-bold mb-0">Employee capacity</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="capTable" class="table table-hover mb-0" style="font-size:.85rem">
                        <thead>
                            <tr><th class="ps-3">Employee</th><th>Hours/day</th><th>Days/week</th><th>Weekly hours</th><th>Max active tasks</th><th>Max points</th><th class="pe-3">Actions</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($users as $u)
                                @php $cap = $capacities->get($u->id); @endphp
                                <tr>
                                    <td class="ps-3">{{ $u->name }}</td>
                                    <td>{{ $cap ? rtrim(rtrim(number_format($cap->working_hours_per_day, 2), '0'), '.') : '—' }}</td>
                                    <td>{{ $cap->working_days_per_week ?? '—' }}</td>
                                    <td>{{ $cap ? rtrim(rtrim(number_format($cap->weekly_hours, 2), '0'), '.') : '—' }}</td>
                                    <td>{{ $cap && $cap->max_active_tasks !== null ? $cap->max_active_tasks : '—' }}</td>
                                    <td>{{ $cap && $cap->max_workload_points !== null ? $cap->max_workload_points : '—' }}</td>
                                    <td class="pe-3">
                                        <button class="btn btn-sm btn-outline-secondary btn-cap-edit"
                                            data-user="{{ $u->id }}" data-name="{{ e($u->name) }}"
                                            data-hours="{{ $cap->working_hours_per_day ?? 8 }}" data-days="{{ $cap->working_days_per_week ?? 5 }}"
                                            data-tasks="{{ $cap->max_active_tasks ?? '' }}" data-points="{{ $cap->max_workload_points ?? '' }}">
                                            <i class="bi bi-pencil"></i> Set
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Daily Targets ─────────────────────────────────────────────── --}}
    <div class="tab-pane fade" id="tab-daily-targets">
        <div class="card section-card">
            <div class="card-header py-3"><h6 class="fw-bold mb-0">Daily targets</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="dtTable" class="table table-hover mb-0" style="font-size:.85rem">
                        <thead>
                            <tr><th class="ps-3">Employee</th><th>Targets</th><th class="pe-3">Actions</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($users as $u)
                                @php $mine = $dailyTargets->get($u->id, collect()); @endphp
                                <tr>
                                    <td class="ps-3">{{ $u->name }}</td>
                                    <td>
                                        @forelse ($mine as $dt)
                                            <span class="pay-cat me-1">{{ \App\Models\DailyTarget::$scopeLabels[$dt->scope] ?? $dt->scope }}: {{ $dt->target_quantity }}/day</span>
                                        @empty
                                            <span style="color:var(--text3)">—</span>
                                        @endforelse
                                    </td>
                                    <td class="pe-3">
                                        <button class="btn btn-sm btn-outline-secondary btn-dt-edit"
                                            data-user="{{ $u->id }}" data-name="{{ e($u->name) }}"
                                            data-targets="{{ $mine->pluck('target_quantity', 'scope')->toJson() }}">
                                            <i class="bi bi-pencil"></i> Set
                                        </button>
                                        @if ($mine->isNotEmpty())
                                            <button class="btn btn-sm btn-outline-danger btn-dt-delete" data-user="{{ $u->id }}"><i class="bi bi-trash"></i></button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="cfg-help mt-2">
            <i class="bi bi-info-circle me-1"></i>Optional, and set per scope — check as many as apply and give each its own daily quantity.
            If an employee simply doesn't have that much work due yet, that scope is forgiven and counts as 100% — it only costs them once there was enough work and they didn't get through it.
        </div>
    </div>
</div>

{{-- ── Target modal ──────────────────────────────────────────────────── --}}
<div class="modal fade" id="targetModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-3"><h6 class="modal-title fw-bold">Sales Target</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Employee</label>
                    <select id="tgUser" class="form-select form-select-sm">
                        @foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Period</label>
                    <select id="tgPeriod" class="form-select form-select-sm">
                        @foreach ($periods as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="mb-1">
                    <label class="form-label fw-semibold small">Target amount</label>
                    <input type="number" step="0.01" min="0" id="tgAmount" class="form-control form-control-sm" placeholder="0.00">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="saveTarget" class="btn btn-sm btn-primary">Save</button>
            </div>
        </div>
    </div>
</div>

{{-- ── Override modal ────────────────────────────────────────────────── --}}
<div class="modal fade" id="overrideModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-3"><h6 class="modal-title fw-bold">Weight Override</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold small">Scope</label>
                        <select id="ovScopeType" class="form-select form-select-sm">
                            <option value="department">Department</option>
                            <option value="employee">Employee</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold small">Applies to</label>
                        <select id="ovScopeDept" class="form-select form-select-sm">
                            @foreach ($departments as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach
                        </select>
                        <select id="ovScopeUser" class="form-select form-select-sm d-none">
                            @foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="wt-grid mb-2" id="ovWeights">
                    @foreach (['task' => 'Task', 'ontime' => 'On-time', 'revision' => 'Quality', 'sales' => 'Sales', 'satisfaction' => 'Satisfaction', 'clientcare' => 'Client care', 'dailytarget' => 'Daily target', 'taskvolume' => 'Task volume'] as $k => $label)
                        <div class="wt-field">
                            <label>{{ $label }}</label>
                            <input type="number" min="0" max="100" id="ov_{{ $k }}" value="20" class="form-control form-control-sm ov-wt">
                        </div>
                    @endforeach
                </div>
                <span class="wt-sum">Total: <span id="ovSum">100</span></span>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="saveOverride" class="btn btn-sm btn-primary">Save</button>
            </div>
        </div>
    </div>
</div>

{{-- ── Capacity modal ────────────────────────────────────────────────── --}}
<div class="modal fade" id="capModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-3"><h6 class="modal-title fw-bold">Capacity — <span id="capName"></span></h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="capUser">
                <div class="row g-2">
                    <div class="col-6 mb-2"><label class="form-label fw-semibold small">Hours / day</label><input type="number" step="0.25" min="0" max="24" id="capHours" class="form-control form-control-sm"></div>
                    <div class="col-6 mb-2"><label class="form-label fw-semibold small">Days / week</label><input type="number" min="1" max="7" id="capDays" class="form-control form-control-sm"></div>
                    <div class="col-6"><label class="form-label fw-semibold small">Max active tasks</label><input type="number" min="0" id="capTasks" class="form-control form-control-sm" placeholder="none"></div>
                    <div class="col-6"><label class="form-label fw-semibold small">Max points</label><input type="number" min="0" id="capPoints" class="form-control form-control-sm" placeholder="none"></div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="saveCap" class="btn btn-sm btn-primary">Save</button>
            </div>
        </div>
    </div>
</div>

{{-- ── Daily Target modal ────────────────────────────────────────────── --}}
<div class="modal fade" id="dtModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-3"><h6 class="modal-title fw-bold">Daily Targets — <span id="dtName"></span></h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="dtUser">
                <p class="cfg-help mb-3">Check any scope this employee should have a daily quota on, and give it a quantity. Leave a scope unchecked to leave it out — unchecking one that was set and saving removes it.</p>
                @foreach (\App\Models\DailyTarget::$scopes as $scope)
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="form-check flex-grow-1 mb-0">
                            <input class="form-check-input dt-scope-check" type="checkbox" value="{{ $scope }}" id="dtScope_{{ $scope }}">
                            <label class="form-check-label" for="dtScope_{{ $scope }}">{{ \App\Models\DailyTarget::$scopeLabels[$scope] }}</label>
                        </div>
                        <input type="number" min="1" max="1000" class="form-control form-control-sm dt-scope-qty" style="width:80px" data-scope="{{ $scope }}" placeholder="qty" disabled>
                    </div>
                @endforeach
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="saveDt" class="btn btn-sm btn-primary">Save</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    // Reopen a specific tab after the settings form redirect (?tab=settings).
    const params = new URLSearchParams(window.location.search);
    if (params.get('tab')) {
        const btn = document.querySelector(`.cfg-tabs [data-tab="${params.get('tab')}"]`);
        if (btn) bootstrap.Tab.getOrCreateInstance(btn).show();
    }
    @if (session('success'))
        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: @json(session('success')), showConfirmButton: false, timer: 2500 });
    @endif

    const fail = r => Swal.fire('Error', r.responseJSON?.message || 'Something went wrong', 'error');

    // ── Sales targets ───────────────────────────────────────────────
    const targetTable = $('#targetTable').DataTable({
        serverSide: true, processing: true,
        ajax: { url: '{{ route("performance.config") }}', data: d => { d.period = $('#targetPeriodFilter').val(); } },
        columns: [
            { data: 'user_name', className: 'ps-3' },
            { data: 'period' },
            { data: 'amount' },
            { data: 'updated' },
            { data: 'actions', orderable: false, className: 'pe-3' },
        ],
        order: [[1, 'desc']], pageLength: 10, lengthChange: false,
    });
    $('#targetPeriodFilter').on('change', () => targetTable.ajax.reload());

    $('#btnAddTarget').on('click', function () {
        $('#tgAmount').val('');
        new bootstrap.Modal('#targetModal').show();
    });
    $(document).on('click', '.btn-target-edit', function () {
        $('#tgUser').val($(this).data('user'));
        $('#tgPeriod').val($(this).data('period'));
        $('#tgAmount').val($(this).data('amount'));
        new bootstrap.Modal('#targetModal').show();
    });
    $('#saveTarget').on('click', function () {
        $.post('{{ route("performance.config.targets.store") }}', {
            user_id: $('#tgUser').val(), period: $('#tgPeriod').val(), target_amount: $('#tgAmount').val(),
        }).done(() => { bootstrap.Modal.getInstance('#targetModal').hide(); targetTable.ajax.reload(); }).fail(fail);
    });
    $(document).on('click', '.btn-target-delete', function () {
        const id = $(this).data('id');
        Swal.fire({ title: 'Remove target?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545' })
            .then(r => { if (r.isConfirmed) $.ajax({ url: '/performance/config/targets/' + id, type: 'DELETE' }).done(() => targetTable.ajax.reload()).fail(fail); });
    });

    // ── Weight sum helpers ──────────────────────────────────────────
    function wireSum(inputSel, outSel, btnSel) {
        const recompute = () => {
            let sum = 0;
            $(inputSel).each(function () { sum += parseInt($(this).val() || 0, 10); });
            const $out = $(outSel);
            $out.text(sum).closest('.wt-sum').toggleClass('ok', sum === 100).toggleClass('bad', sum !== 100);
            if (btnSel) $(btnSel).prop('disabled', sum !== 100);
            return sum;
        };
        $(document).on('input', inputSel, recompute);
        return recompute;
    }
    const globalRecompute = wireSum('.wt-input', '.wt-sum-val', '#globalWeightForm button[type=submit]');
    const ovRecompute = wireSum('.ov-wt', '#ovSum', '#saveOverride');
    globalRecompute();

    $('#globalWeightForm').on('submit', function (e) {
        e.preventDefault();
        $.post('{{ route("performance.config.weights.store") }}', $(this).serialize())
            .done(() => Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Global weights saved', showConfirmButton: false, timer: 2000 }))
            .fail(fail);
    });

    // ── Weight overrides ────────────────────────────────────────────
    function toggleOvScope() {
        const isEmp = $('#ovScopeType').val() === 'employee';
        $('#ovScopeUser').toggleClass('d-none', !isEmp);
        $('#ovScopeDept').toggleClass('d-none', isEmp);
    }
    $('#ovScopeType').on('change', toggleOvScope);

    $('#btnAddOverride').on('click', function () {
        $('#ovScopeType').val('department'); toggleOvScope();
        $('.ov-wt').each(function (i) { $(this).val([18, 18, 12, 11, 11, 11, 9, 10][i]); });
        ovRecompute();
        new bootstrap.Modal('#overrideModal').show();
    });
    $(document).on('click', '.btn-ov-edit', function () {
        const t = $(this);
        $('#ovScopeType').val(t.data('scope-type')); toggleOvScope();
        if (t.data('scope-type') === 'employee') $('#ovScopeUser').val(t.data('scope-value'));
        else $('#ovScopeDept').val(t.data('scope-value'));
        $('#ov_task').val(t.data('task')); $('#ov_ontime').val(t.data('ontime'));
        $('#ov_revision').val(t.data('revision')); $('#ov_sales').val(t.data('sales'));
        $('#ov_satisfaction').val(t.data('satisfaction'));
        $('#ov_clientcare').val(t.data('clientcare') || 0);
        $('#ov_dailytarget').val(t.data('dailytarget') || 0);
        $('#ov_taskvolume').val(t.data('taskvolume') || 0);
        ovRecompute();
        new bootstrap.Modal('#overrideModal').show();
    });
    $('#saveOverride').on('click', function () {
        const scopeType = $('#ovScopeType').val();
        $.post('{{ route("performance.config.weights.store") }}', {
            scope_type: scopeType,
            scope_value: scopeType === 'employee' ? $('#ovScopeUser').val() : $('#ovScopeDept').val(),
            task_completion_weight: $('#ov_task').val(), on_time_weight: $('#ov_ontime').val(),
            revision_weight: $('#ov_revision').val(), sales_weight: $('#ov_sales').val(),
            satisfaction_weight: $('#ov_satisfaction').val(),
            client_care_weight: $('#ov_clientcare').val(),
            daily_target_weight: $('#ov_dailytarget').val(),
            task_volume_weight: $('#ov_taskvolume').val(),
        }).done(() => window.location.reload()).fail(fail);
    });
    $(document).on('click', '.btn-ov-delete', function () {
        const id = $(this).data('id');
        Swal.fire({ title: 'Delete override?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545' })
            .then(r => { if (r.isConfirmed) $.ajax({ url: '/performance/config/weights/' + id, type: 'DELETE' }).done(() => window.location.reload()).fail(fail); });
    });

    // ── Capacity ────────────────────────────────────────────────────
    $(document).on('click', '.btn-cap-edit', function () {
        const t = $(this);
        $('#capName').text(t.data('name'));
        $('#capUser').val(t.data('user'));
        $('#capHours').val(t.data('hours'));
        $('#capDays').val(t.data('days'));
        $('#capTasks').val(t.data('tasks'));
        $('#capPoints').val(t.data('points'));
        new bootstrap.Modal('#capModal').show();
    });
    $('#saveCap').on('click', function () {
        $.post('{{ route("performance.config.capacity") }}', {
            user_id: $('#capUser').val(), working_hours_per_day: $('#capHours').val(),
            working_days_per_week: $('#capDays').val(),
            max_active_tasks: $('#capTasks').val() || '', max_workload_points: $('#capPoints').val() || '',
        }).done(() => window.location.reload()).fail(fail);
    });

    // ── Daily Targets ───────────────────────────────────────────────
    $('.dt-scope-check').on('change', function () {
        const $qty = $(this).closest('.d-flex').find('.dt-scope-qty');
        $qty.prop('disabled', !this.checked);
        if (!this.checked) $qty.val('');
    });

    $(document).on('click', '.btn-dt-edit', function () {
        const t = $(this);
        const targets = JSON.parse(t.attr('data-targets') || '{}');
        $('#dtName').text(t.data('name'));
        $('#dtUser').val(t.data('user'));
        $('.dt-scope-check').each(function () {
            const scope = this.value;
            const $qty = $('.dt-scope-qty[data-scope="' + scope + '"]');
            const has = Object.prototype.hasOwnProperty.call(targets, scope);
            $(this).prop('checked', has);
            $qty.prop('disabled', !has).val(has ? targets[scope] : '');
        });
        new bootstrap.Modal('#dtModal').show();
    });

    $('#saveDt').on('click', function () {
        const scopes = [];
        const quantities = {};
        let invalid = false;
        $('.dt-scope-check:checked').each(function () {
            const scope = this.value;
            const qty = $('.dt-scope-qty[data-scope="' + scope + '"]').val();
            if (!qty || parseInt(qty, 10) < 1) { invalid = true; return; }
            scopes.push(scope);
            quantities[scope] = qty;
        });
        if (invalid || !scopes.length) {
            Swal.fire('Error', 'Give every checked scope a quantity of at least 1 (or uncheck it).', 'error');
            return;
        }
        $.post('{{ route("performance.config.daily-targets.store") }}', {
            user_id: $('#dtUser').val(), scopes: scopes, quantities: quantities,
        }).done(() => window.location.reload()).fail(fail);
    });

    $(document).on('click', '.btn-dt-delete', function () {
        const userId = $(this).data('user');
        Swal.fire({ title: 'Remove all daily targets for this employee?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545' })
            .then(r => { if (r.isConfirmed) $.ajax({ url: '/performance/config/daily-targets/' + userId, type: 'DELETE' }).done(() => window.location.reload()).fail(fail); });
    });
});
</script>
@endpush
