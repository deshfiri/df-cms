<?php

namespace App\Services\Performance;

use App\Models\KpiWeightConfig;
use App\Models\Payment;
use App\Models\PerformanceSetting;
use App\Models\SalesTarget;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use Carbon\Carbon;

class PerformanceCalculationService
{
    private const ACTIVE_STATUSES = ['Pending', 'In Progress', 'On Hold'];

    // Request-lifetime memo of the singletons that are otherwise re-queried once
    // per employee — the scoreboard and snapshot command reuse one service
    // instance across the whole user loop, so this collapses N lookups into 1.
    private ?PerformanceSetting $settingsCache = null;
    private ?\Illuminate\Support\Collection $weightConfigsCache = null;

    private function settings(): PerformanceSetting
    {
        return $this->settingsCache ??= PerformanceSetting::current();
    }

    private function weightConfigs(): \Illuminate\Support\Collection
    {
        return $this->weightConfigsCache ??= KpiWeightConfig::all();
    }

    public function periodBounds(string $period): array
    {
        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();

        return [$start->copy()->startOfDay(), $start->copy()->endOfMonth()->endOfDay()];
    }

    public function previousPeriod(string $period): string
    {
        return Carbon::createFromFormat('Y-m', $period)->subMonth()->format('Y-m');
    }

    public function taskCompletion(User $user, string $period): array
    {
        [$start, $end] = $this->periodBounds($period);
        $settings = $this->settings();

        $tasks = Task::where('assigned_to', $user->id)
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->get(['id', 'status', 'due_date']);

        $total     = $tasks->count();
        $completed = $tasks->where('status', 'Completed')->count();
        $cancelled = $tasks->where('status', 'Cancelled')->count();
        $pending   = $tasks->where('status', 'Pending')->count();
        $onHold    = $tasks->where('status', 'On Hold')->count();
        $inProgress = $tasks->where('status', 'In Progress')->count();
        $overdue   = $tasks->filter(fn (Task $t) => $t->due_date && $t->due_date->isPast() && !in_array($t->status, ['Completed', 'Cancelled'], true))->count();

        $denominator = $settings->count_cancelled_against_kpi ? $total : ($total - $cancelled);
        $completionPct = $denominator > 0 ? round($completed / $denominator * 100, 2) : null;

        return [
            'total' => $total, 'completed' => $completed, 'pending' => $pending,
            'in_progress' => $inProgress, 'on_hold' => $onHold, 'overdue' => $overdue, 'cancelled' => $cancelled,
            'completion_pct' => $completionPct,
        ];
    }

    public function onTimeCompletion(User $user, string $period): array
    {
        [$start, $end] = $this->periodBounds($period);

        $completed = Task::where('assigned_to', $user->id)
            ->where('status', 'Completed')
            ->whereNotNull('completion_date')
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->get(['id', 'due_date', 'completion_date']);

        $before = $onTime = $after = 0;
        $delays = [];

        foreach ($completed as $task) {
            $diff = $task->due_date->diffInDays($task->completion_date, false);
            if ($diff < 0) {
                $before++;
            } elseif ($diff === 0) {
                $onTime++;
            } else {
                $after++;
                $delays[] = $diff;
            }
        }

        $totalCompleted = $completed->count();
        $onTimeRate = $totalCompleted > 0 ? round(($before + $onTime) / $totalCompleted * 100, 2) : null;
        $avgDelay = count($delays) > 0 ? round(array_sum($delays) / count($delays), 2) : null;

        return [
            'total_completed' => $totalCompleted,
            'before_deadline' => $before, 'on_deadline' => $onTime, 'after_deadline' => $after,
            'avg_delay_days' => $avgDelay, 'on_time_rate' => $onTimeRate,
        ];
    }

    public function deadlineExtensionHistory(Task $task): array
    {
        $events = [];

        foreach (TaskActivity::where('task_id', $task->id)->where('action', 'Updated')->get() as $activity) {
            $old = json_decode((string) $activity->old_value, true);
            $new = json_decode((string) $activity->new_value, true);

            if (is_array($old) && is_array($new) && ($old['due_date'] ?? null) !== ($new['due_date'] ?? null)) {
                $events[] = [
                    'changed_at'   => $activity->created_at,
                    'changed_by'   => $activity->user_id,
                    'previous_due' => $old['due_date'] ?? null,
                    'new_due'      => $new['due_date'] ?? null,
                ];
            }
        }

        return $events;
    }

    public function revisionRate(User $user, string $period): array
    {
        [$start, $end] = $this->periodBounds($period);

        $tasks = Task::where('assigned_to', $user->id)
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->where(function ($q) {
                $q->where('status', 'Completed')->orWhereHas('revisions');
            })
            ->withCount('revisions')
            ->with(['revisions' => fn ($q) => $q->where('reason_category', 'Employee Mistake')])
            ->get();

        $totalSubmitted = $tasks->count();
        $requiringRevision = $tasks->where('revisions_count', '>', 0)->count();
        $employeeMistakeCount = $tasks->filter(fn (Task $t) => $t->revisions->isNotEmpty())->count();
        $totalRevisionRequests = (int) $tasks->sum('revisions_count');

        return [
            'total_submitted' => $totalSubmitted,
            'approved_first_submission' => $totalSubmitted - $requiringRevision,
            'requiring_revision' => $requiringRevision,
            'total_revision_requests' => $totalRevisionRequests,
            'avg_revisions_per_task' => $totalSubmitted > 0 ? round($totalRevisionRequests / $totalSubmitted, 2) : null,
            'revision_rate_all' => $totalSubmitted > 0 ? round($requiringRevision / $totalSubmitted * 100, 2) : null,
            'revision_rate_kpi' => $totalSubmitted > 0 ? round($employeeMistakeCount / $totalSubmitted * 100, 2) : null,
        ];
    }

    public function salesAchievement(User $user, string $period): ?array
    {
        $target = SalesTarget::where('user_id', $user->id)->where('period', $period)->first();
        if (!$target) {
            return null;
        }

        [$start, $end] = $this->periodBounds($period);

        $achieved = (float) Payment::where('status', 'Paid')
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('client', fn ($q) => $q->where('assigned_to', $user->id))
            ->sum('amount');

        $targetAmount = (float) $target->target_amount;
        $pct = $targetAmount > 0 ? round($achieved / $targetAmount * 100, 2) : null;
        $remaining = max($targetAmount - $achieved, 0);

        $now = now();
        $daysRemaining = $now->lessThan($end) ? $now->diffInDays($end) + 1 : 0;
        $dailyRequired = $daysRemaining > 0 ? round($remaining / $daysRemaining, 2) : null;

        return [
            'target_amount' => $targetAmount, 'achieved' => $achieved, 'pct' => $pct,
            'remaining' => $remaining, 'days_remaining' => $daysRemaining, 'daily_required' => $dailyRequired,
        ];
    }

    public function clientSatisfaction(User $user, string $period): ?array
    {
        [$start, $end] = $this->periodBounds($period);

        $ratings = $user->satisfactionRatings()->included()
            ->whereBetween('created_at', [$start, $end])
            ->get();

        if ($ratings->isEmpty()) {
            return null;
        }

        $avg = $ratings->avg('rating');

        return [
            'count' => $ratings->count(),
            'avg_rating' => round($avg, 2),
            'score' => round($avg * 20, 2),
            'positive' => $ratings->where('rating', '>=', 4)->count(),
            'complaints' => $ratings->where('rating', '<=', 2)->count(),
        ];
    }

    public function resolveWeights(User $user): KpiWeightConfig
    {
        // Resolved in-memory from the memoized set (employee → department →
        // global precedence) so we don't run up to 3 queries per employee.
        $configs = $this->weightConfigs();

        $employeeConfig = $configs->first(fn (KpiWeightConfig $c) => $c->scope_type === KpiWeightConfig::SCOPE_EMPLOYEE && $c->scope_value === (string) $user->id);
        if ($employeeConfig) {
            return $employeeConfig;
        }

        $roleNames = $user->getRoleNames();
        if ($roleNames->isNotEmpty()) {
            $departmentConfig = $configs->first(fn (KpiWeightConfig $c) => $c->scope_type === KpiWeightConfig::SCOPE_DEPARTMENT && $roleNames->contains($c->scope_value));
            if ($departmentConfig) {
                return $departmentConfig;
            }
        }

        return $configs->first(fn (KpiWeightConfig $c) => $c->scope_type === KpiWeightConfig::SCOPE_GLOBAL)
            ?? new KpiWeightConfig([
                'scope_type' => KpiWeightConfig::SCOPE_GLOBAL,
                'task_completion_weight' => 25, 'on_time_weight' => 25, 'revision_weight' => 20,
                'sales_weight' => 15, 'satisfaction_weight' => 15,
            ]);
    }

    public function finalScore(User $user, string $period): array
    {
        $taskCompletion = $this->taskCompletion($user, $period);
        $onTime         = $this->onTimeCompletion($user, $period);
        $revision       = $this->revisionRate($user, $period);
        $sales          = $this->salesAchievement($user, $period);
        $satisfaction   = $this->clientSatisfaction($user, $period);

        $weightConfig = $this->resolveWeights($user);
        $weights = $weightConfig->toWeightsArray();

        $scores = [
            'task_completion' => $taskCompletion['completion_pct'],
            'on_time'         => $onTime['on_time_rate'],
            'revision'        => $revision['revision_rate_kpi'] !== null ? round(100 - $revision['revision_rate_kpi'], 2) : null,
            'sales'           => $sales !== null ? min($sales['pct'] ?? 0, 100) : null,
            'satisfaction'    => $satisfaction['score'] ?? null,
        ];

        $applicable = array_filter($scores, fn ($v) => $v !== null);

        if (empty($applicable)) {
            return [
                'final_score' => null, 'performance_level' => null,
                'scores' => $scores, 'weights_used' => [], 'strongest' => null, 'weakest' => null,
                'components' => compact('taskCompletion', 'onTime', 'revision', 'sales', 'satisfaction'),
            ];
        }

        $weightSum = 0;
        foreach (array_keys($applicable) as $key) {
            $weightSum += $weights[$key];
        }

        $weightedTotal = 0;
        $weightsUsed = [];
        foreach ($applicable as $key => $score) {
            $normalizedWeight = $weightSum > 0 ? $weights[$key] / $weightSum * 100 : 0;
            $weightsUsed[$key] = round($normalizedWeight, 2);
            $weightedTotal += $score * $normalizedWeight / 100;
        }

        $finalScore = round($weightedTotal, 2);

        arsort($applicable);
        $strongest = array_key_first($applicable);
        $weakest = array_key_last($applicable);

        return [
            'final_score' => $finalScore,
            'performance_level' => $this->performanceLevel($finalScore),
            'scores' => $scores,
            'weights_used' => $weightsUsed,
            'strongest' => $strongest,
            'weakest' => $weakest,
            'components' => compact('taskCompletion', 'onTime', 'revision', 'sales', 'satisfaction'),
        ];
    }

    public function performanceLevel(float $score): string
    {
        return match (true) {
            $score >= 90 => 'Excellent',
            $score >= 80 => 'Very Good',
            $score >= 70 => 'Good',
            $score >= 60 => 'Needs Improvement',
            default      => 'Poor',
        };
    }
}
