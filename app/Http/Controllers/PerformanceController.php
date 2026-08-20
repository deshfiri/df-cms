<?php

namespace App\Http\Controllers;

use App\Models\MonthlyPerformanceSnapshot;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use App\Services\WorkloadService;
use App\Support\PerformanceBoardCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class PerformanceController extends Controller
{
    /** Functional teams (Spatie roles double as departments — see DatabaseSeeder). */
    private const DEPARTMENTS = ['Sales', 'Document', 'Design', 'Website', 'Product', 'Marketing', 'Support', 'Accounts', 'Content'];

    /**
     * Backstop lifetime (seconds) for a cached scoreboard entry. Freshness is
     * handled by version-keyed invalidation (PerformanceBoardCache — bumped when
     * score-affecting models change); the TTL only stops orphaned versions from
     * lingering in the cache store and refreshes anything not hooked (e.g. a
     * client reassignment affecting sales attribution).
     */
    private const BOARD_TTL = 600;

    public function __construct(
        private readonly PerformanceCalculationService $performance,
    ) {}

    /**
     * Company scoreboard — every active employee's live KPI score for the period.
     * Each row costs several queries (finalScore fans out over tasks, payments and
     * ratings), so the computed set is cached per period + department for BOARD_TTL
     * seconds. For an exact, always-fresh view of a closed month use the History
     * page, which reads persisted snapshots.
     */
    public function index(Request $request)
    {
        abort_unless(Auth::user()->can('view performance'), 403);

        $period     = $this->resolvePeriod($request);
        $department = $request->input('department');
        if ($department && !in_array($department, self::DEPARTMENTS, true)) {
            $department = null;
        }

        $rows = Cache::remember(PerformanceBoardCache::key($period, $department), self::BOARD_TTL, function () use ($period, $department) {
            $query = User::query()->with('roles')->where('is_active', true)->orderBy('name');
            if ($department) {
                $query->role($department);
            }

            $users = $query->get(['id', 'name']);

            // One cohort load instead of six queries per employee.
            $this->performance->prefetch($users, $period);

            $rows = $users
                ->map(function (User $user) use ($period) {
                    $score = $this->performance->finalScore($user, $period);

                    return [
                        'id'                => $user->id,
                        'name'              => $user->name,
                        'department'        => $user->getRoleNames()->first(),
                        'final_score'       => $score['final_score'],
                        'performance_level' => $score['performance_level'],
                        'scores'            => $score['scores'],
                    ];
                })
                ->sortByDesc(fn ($r) => $r['final_score'] ?? -1)
                ->values();

            // Rank only employees that have a computable score; leave the rest unranked.
            $rank = 0;

            return $rows->map(function (array $row) use (&$rank) {
                $row['rank'] = $row['final_score'] !== null ? ++$rank : null;

                return $row;
            });
        });

        $scored = $rows->whereNotNull('final_score');

        return view('performance.index', [
            'rows'        => $rows,
            'period'      => $period,
            'periods'     => $this->periodOptions(),
            'departments' => self::DEPARTMENTS,
            'department'  => $department,
            'avgScore'    => $scored->isNotEmpty() ? round($scored->avg('final_score'), 1) : null,
            'scoredCount' => $scored->count(),
            'topPerformer' => $scored->first(),
        ]);
    }

    /** Single-employee scorecard with the full component breakdown + snapshot trend. */
    public function show(User $user, Request $request)
    {
        abort_unless(Auth::user()->can('view performance'), 403);

        $period = $this->resolvePeriod($request);

        // Snapshot history (persisted by performance:snapshot) drives the trend line.
        $snapshots = MonthlyPerformanceSnapshot::where('user_id', $user->id)
            ->orderBy('period')
            ->get(['period', 'final_score']);

        return view('performance.show', [
            'employee' => $user,
            'period'   => $period,
            'periods'  => $this->periodOptions(),
            'result'   => $this->performance->finalScore($user, $period),
            'trend'    => [
                'labels' => $snapshots->map(fn ($s) => \Illuminate\Support\Carbon::createFromFormat('Y-m', $s->period)->format('M Y'))->all(),
                'scores' => $snapshots->map(fn ($s) => (float) $s->final_score)->all(),
            ],
        ]);
    }

    /**
     * Historical scoreboard — reads persisted monthly snapshots (fast, no
     * recompute) and shows the ranks captured at snapshot time.
     */
    public function history(Request $request)
    {
        abort_unless(Auth::user()->can('view performance'), 403);

        $availablePeriods = MonthlyPerformanceSnapshot::query()
            ->select('period')->distinct()->orderByDesc('period')->pluck('period');

        $period = $request->input('period');
        if (!$availablePeriods->contains($period)) {
            $period = $availablePeriods->first();
        }

        $snapshots = $period
            ? MonthlyPerformanceSnapshot::with('user:id,name')
                ->where('period', $period)
                ->orderBy('rank_company')
                ->get()
            : collect();

        return view('performance.history', [
            'availablePeriods' => $availablePeriods,
            'periods'          => $this->periodOptions(),
            'period'           => $period,
            'snapshots'        => $snapshots,
            'generatedAt'      => $snapshots->first()?->generated_at,
        ]);
    }

    private function resolvePeriod(Request $request): string
    {
        $period = (string) $request->input('period', now()->format('Y-m'));

        return preg_match('/^\d{4}-\d{2}$/', $period) ? $period : now()->format('Y-m');
    }

    /** Live workload board — active-task load vs. configured capacity per employee. */
    public function workload(Request $request, WorkloadService $workload)
    {
        abort_unless(Auth::user()->can('view performance'), 403);

        $department = $request->input('department');
        $rows = $workload->overview(
            $department && in_array($department, self::DEPARTMENTS, true) ? $department : null
        );

        $utils = $rows->pluck('utilization')->filter(fn ($v) => $v !== null);
        $settings = \App\Models\PerformanceSetting::current();

        return view('performance.workload', [
            'rows'        => $rows,
            'departments' => self::DEPARTMENTS,
            'department'  => $department,
            'overloaded'  => $rows->where('status', 'Overloaded')->count(),
            'configured'  => $rows->where('has_capacity', true)->count(),
            'avgUtil'     => $utils->isNotEmpty() ? round($utils->avg(), 1) : null,
            'settings'    => $settings,
        ]);
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
