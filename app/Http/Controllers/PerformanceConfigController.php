<?php

namespace App\Http\Controllers;

use App\Models\EmployeeCapacity;
use App\Models\KpiWeightConfig;
use App\Models\PerformanceSetting;
use App\Models\SalesTarget;
use App\Models\User;
use App\Services\PerformanceConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PerformanceConfigController extends Controller
{
    /** Functional teams (Spatie roles double as departments — see DatabaseSeeder). */
    private const DEPARTMENTS = ['Sales', 'Document', 'Design', 'Website', 'Product', 'Marketing', 'Support', 'Accounts', 'Content'];

    private const WEIGHT_FIELDS = ['task_completion_weight', 'on_time_weight', 'revision_weight', 'sales_weight', 'satisfaction_weight'];

    public function __construct(
        private readonly PerformanceConfigService $service,
    ) {}

    public function index(Request $request)
    {
        abort_unless(Auth::user()->can('manage performance'), 403);

        if ($request->ajax() && $request->has('draw')) {
            return $this->targetsData($request);
        }

        $users = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        $global = KpiWeightConfig::where('scope_type', KpiWeightConfig::SCOPE_GLOBAL)->first();

        $overrides = KpiWeightConfig::whereIn('scope_type', [KpiWeightConfig::SCOPE_DEPARTMENT, KpiWeightConfig::SCOPE_EMPLOYEE])
            ->get()
            ->map(function (KpiWeightConfig $c) use ($users) {
                $label = $c->scope_type === KpiWeightConfig::SCOPE_EMPLOYEE
                    ? ($users->firstWhere('id', (int) $c->scope_value)->name ?? "User #{$c->scope_value}")
                    : $c->scope_value;

                return ['config' => $c, 'label' => $label];
            });

        $capacities = EmployeeCapacity::whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');

        return view('performance.config', [
            'users'       => $users,
            'periods'     => $this->periodOptions(),
            'departments' => self::DEPARTMENTS,
            'settings'    => PerformanceSetting::current(),
            'global'      => $global,
            'overrides'   => $overrides,
            'capacities'  => $capacities,
        ]);
    }

    private function targetsData(Request $request): JsonResponse
    {
        $query = SalesTarget::with(['user:id,name', 'updatedBy:id,name']);
        if ($request->filled('period')) {
            $query->where('period', $request->input('period'));
        }

        return \Yajra\DataTables\Facades\DataTables::of($query)
            ->addColumn('user_name', fn (SalesTarget $t) => e($t->user->name ?? '—'))
            ->addColumn('amount', fn (SalesTarget $t) => number_format((float) $t->target_amount, 2))
            ->addColumn('updated', fn (SalesTarget $t) => e($t->updatedBy->name ?? '—') . '<br><span style="font-size:.7rem;color:var(--text3)">' . $t->updated_at->format('d M Y') . '</span>')
            ->addColumn('actions', fn (SalesTarget $t) => '<button class="btn btn-sm btn-outline-secondary btn-target-edit" data-id="' . $t->id . '" data-user="' . $t->user_id . '" data-period="' . e($t->period) . '" data-amount="' . e($t->target_amount) . '"><i class="bi bi-pencil"></i></button> <button class="btn btn-sm btn-outline-danger btn-target-delete" data-id="' . $t->id . '"><i class="bi bi-trash"></i></button>')
            ->rawColumns(['updated', 'actions'])
            ->make(true);
    }

    public function storeTarget(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->can('manage performance'), 403);

        $data = $request->validate([
            'user_id'       => ['required', 'exists:users,id'],
            'period'        => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'target_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        $this->service->upsertTarget($data);

        return response()->json(['success' => true]);
    }

    public function destroyTarget(SalesTarget $target): JsonResponse
    {
        abort_unless(Auth::user()->can('manage performance'), 403);

        $this->service->deleteTarget($target);

        return response()->json(['success' => true]);
    }

    public function storeWeight(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->can('manage performance'), 403);

        $data = $request->validate([
            'scope_type'  => ['required', Rule::in([KpiWeightConfig::SCOPE_GLOBAL, KpiWeightConfig::SCOPE_DEPARTMENT, KpiWeightConfig::SCOPE_EMPLOYEE])],
            'scope_value' => [
                'nullable',
                Rule::requiredIf(fn () => $request->input('scope_type') !== KpiWeightConfig::SCOPE_GLOBAL),
                $request->input('scope_type') === KpiWeightConfig::SCOPE_DEPARTMENT ? Rule::in(self::DEPARTMENTS) : 'string',
                $request->input('scope_type') === KpiWeightConfig::SCOPE_EMPLOYEE ? 'exists:users,id' : 'nullable',
            ],
            'task_completion_weight' => ['required', 'integer', 'min:0', 'max:100'],
            'on_time_weight'         => ['required', 'integer', 'min:0', 'max:100'],
            'revision_weight'        => ['required', 'integer', 'min:0', 'max:100'],
            'sales_weight'           => ['required', 'integer', 'min:0', 'max:100'],
            'satisfaction_weight'    => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $sum = array_sum(array_map(fn ($f) => (int) $data[$f], self::WEIGHT_FIELDS));
        if ($sum !== 100) {
            return response()->json(['success' => false, 'message' => "Weights must total 100 (currently {$sum})."], 422);
        }

        $this->service->upsertWeight($data);

        return response()->json(['success' => true]);
    }

    public function destroyWeight(KpiWeightConfig $weight): JsonResponse
    {
        abort_unless(Auth::user()->can('manage performance'), 403);

        if ($weight->scope_type === KpiWeightConfig::SCOPE_GLOBAL) {
            return response()->json(['success' => false, 'message' => 'The global weight profile cannot be deleted.'], 422);
        }

        $this->service->deleteWeight($weight);

        return response()->json(['success' => true]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()->can('manage performance'), 403);

        $data = $request->validate([
            'task_weight_low'             => ['required', 'integer', 'min:0', 'max:255'],
            'task_weight_medium'          => ['required', 'integer', 'min:0', 'max:255'],
            'task_weight_high'            => ['required', 'integer', 'min:0', 'max:255'],
            'task_weight_critical'        => ['required', 'integer', 'min:0', 'max:255'],
            'overload_threshold_pct'      => ['required', 'integer', 'min:0', 'max:1000'],
            'busy_threshold_pct'          => ['required', 'integer', 'min:0', 'max:1000'],
            'available_threshold_pct'     => ['required', 'integer', 'min:0', 'max:1000'],
            'revision_rate_alert_pct'     => ['required', 'integer', 'min:0', 'max:100'],
            'kpi_drop_alert_points'       => ['required', 'integer', 'min:0', 'max:100'],
            'overdue_alert_count'         => ['required', 'integer', 'min:0', 'max:1000'],
            'strict_workload_limit'       => ['boolean'],
            'auto_assign_enabled'         => ['boolean'],
            'count_cancelled_against_kpi' => ['boolean'],
        ]);

        foreach (['strict_workload_limit', 'auto_assign_enabled', 'count_cancelled_against_kpi'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        $this->service->updateSettings($data);

        return redirect()->route('performance.config', ['tab' => 'settings'])->with('success', 'Performance settings updated.');
    }

    public function updateCapacity(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->can('manage performance'), 403);

        $data = $request->validate([
            'user_id'               => ['required', 'exists:users,id'],
            'working_hours_per_day' => ['required', 'numeric', 'min:0', 'max:24'],
            'working_days_per_week' => ['required', 'integer', 'min:1', 'max:7'],
            'max_active_tasks'      => ['nullable', 'integer', 'min:0', 'max:100000'],
            'max_workload_points'   => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $this->service->upsertCapacity($data);

        return response()->json(['success' => true]);
    }

    /** Last 12 months as ['2026-07' => 'Jul 2026'], newest first. */
    private function periodOptions(): array
    {
        $options = [];
        $cursor  = now()->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $options[$cursor->format('Y-m')] = $cursor->format('M Y');
            $cursor->subMonth();
        }

        return $options;
    }
}
