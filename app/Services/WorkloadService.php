<?php

namespace App\Services;

use App\Models\EmployeeCapacity;
use App\Models\PerformanceSetting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Computes live employee workload from active tasks + EmployeeCapacity, using
 * the priority weights and utilization thresholds in PerformanceSetting. Drives
 * the workload dashboard and the opt-in auto-assign / strict-limit rules that
 * TaskService applies when the matching PerformanceSetting flags are enabled.
 */
class WorkloadService
{
    private const ACTIVE_STATUSES = ['Pending', 'In Progress', 'On Hold'];

    /** Load profile for one employee (single-user query path). */
    public function load(User $user): array
    {
        $tasks = Task::where('assigned_to', $user->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->get(['id', 'priority']);

        return $this->buildLoad($user, $tasks, PerformanceSetting::current());
    }

    /**
     * Load profiles for every active employee. Active tasks are fetched in a
     * single grouped query (not one per user), and the settings singleton is
     * read once for the whole set.
     */
    public function overview(?string $department = null): Collection
    {
        $query = User::query()->with(['roles', 'capacity'])->where('is_active', true)->orderBy('name');
        if ($department) {
            $query->role($department);
        }
        $users = $query->get();

        $tasksByUser = $this->activeTasksFor($users);
        $settings    = PerformanceSetting::current();

        return $users->map(fn (User $u) => $this->buildLoad($u, $tasksByUser->get($u->id) ?? collect(), $settings)
            + ['department' => $u->getRoleNames()->first()]);
    }

    public function isOverloaded(User $user): bool
    {
        return $this->load($user)['status'] === 'Overloaded';
    }

    /**
     * Least-loaded assignable employee among the candidates — only those with a
     * configured capacity are considered, ranked by utilization then raw points.
     * Their active tasks are loaded in one grouped query. Returns null when no
     * candidate has capacity set.
     */
    public function suggestAssignee(Collection $candidates): ?User
    {
        $eligible = $candidates->filter(fn (User $u) => $u->capacity !== null)->values();
        if ($eligible->isEmpty()) {
            return null;
        }

        $tasksByUser = $this->activeTasksFor($eligible);
        $settings    = PerformanceSetting::current();

        return $eligible
            ->map(fn (User $u) => ['user' => $u, 'load' => $this->buildLoad($u, $tasksByUser->get($u->id) ?? collect(), $settings)])
            ->filter(fn (array $r) => $r['load']['utilization'] !== null)
            ->sort(fn (array $a, array $b) => [$a['load']['utilization'], $a['load']['points']] <=> [$b['load']['utilization'], $b['load']['points']])
            ->values()
            ->first()['user'] ?? null;
    }

    /** Active tasks for a set of users, keyed by assigned_to — one query. */
    private function activeTasksFor(Collection $users): Collection
    {
        return Task::whereIn('assigned_to', $users->pluck('id'))
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->get(['id', 'assigned_to', 'priority'])
            ->groupBy('assigned_to');
    }

    /** Pure computation — no queries. */
    private function buildLoad(User $user, Collection $activeTasks, PerformanceSetting $settings): array
    {
        $count    = $activeTasks->count();
        $points   = (int) $activeTasks->sum(fn (Task $t) => $settings->priorityWeight($t->priority));
        $capacity = $user->capacity;
        $util     = $this->utilization($count, $points, $capacity);
        $status   = $util !== null ? $settings->workloadStatus($util) : 'Unknown';

        return [
            'user'         => $user,
            'active_tasks' => $count,
            'points'       => $points,
            'max_tasks'    => $capacity?->max_active_tasks,
            'max_points'   => $capacity?->max_workload_points,
            'utilization'  => $util,
            'status'       => $status,
            'has_capacity' => (bool) $capacity,
        ];
    }

    private function utilization(int $count, int $points, ?EmployeeCapacity $capacity): ?float
    {
        if (!$capacity) {
            return null;
        }
        if ($capacity->max_workload_points) {
            return round($points / $capacity->max_workload_points * 100, 1);
        }
        if ($capacity->max_active_tasks) {
            return round($count / $capacity->max_active_tasks * 100, 1);
        }

        return null;
    }
}
