<?php

namespace App\Console\Commands;

use App\Models\MonthlyPerformanceSnapshot;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GeneratePerformanceSnapshots extends Command
{
    protected $signature = 'performance:snapshot
                            {period? : Month to snapshot as YYYY-MM (defaults to the current month)}
                            {--previous : Snapshot the previous month instead — used by the monthly schedule}';

    protected $description = 'Persist each active employee\'s KPI score for a month into monthly_performance_snapshots, with company & department ranks.';

    public function handle(PerformanceCalculationService $performance): int
    {
        $period = $this->resolvePeriod();
        $this->info("Snapshotting performance for {$period}…");

        $users = User::with('roles')->where('is_active', true)->orderBy('name')->get();

        // Compute every scorable employee first, so ranks can be assigned across the set.
        $scored = [];
        foreach ($users as $user) {
            $score = $performance->finalScore($user, $period);
            if ($score['final_score'] === null) {
                continue; // no tasks / sales target / ratings this month — nothing to rank
            }
            $scored[] = [
                'user'       => $user,
                'score'      => $score,
                'department' => $user->getRoleNames()->first(),
            ];
        }

        if (empty($scored)) {
            $this->warn('No employees had scorable activity for this period — nothing written.');

            return self::SUCCESS;
        }

        // Company rank: highest final score first.
        usort($scored, fn ($a, $b) => $b['score']['final_score'] <=> $a['score']['final_score']);
        foreach ($scored as $i => &$row) {
            $row['rank_company'] = $i + 1;
        }
        unset($row);

        // Department rank: position within the employee's own team.
        $deptCounters = [];
        foreach ($scored as &$row) {
            $dept = $row['department'] ?? '—';
            $deptCounters[$dept] = ($deptCounters[$dept] ?? 0) + 1;
            $row['rank_department'] = $deptCounters[$dept];
        }
        unset($row);

        $now = now();
        foreach ($scored as $row) {
            $score = $row['score'];

            MonthlyPerformanceSnapshot::updateOrCreate(
                ['user_id' => $row['user']->id, 'period' => $period],
                [
                    'task_completion_score' => $score['scores']['task_completion'],
                    'on_time_score'         => $score['scores']['on_time'],
                    'revision_score'        => $score['scores']['revision'],
                    'sales_score'           => $score['scores']['sales'],
                    'satisfaction_score'    => $score['scores']['satisfaction'],
                    'weights_used'          => $score['weights_used'],
                    'component_details'     => $score['components'],
                    'final_score'           => $score['final_score'],
                    'performance_level'     => $score['performance_level'],
                    'rank_company'          => $row['rank_company'],
                    'rank_department'       => $row['rank_department'],
                    'generated_at'          => $now,
                ]
            );
        }

        $this->info('Wrote ' . count($scored) . ' snapshot(s) for ' . $period . '.');

        return self::SUCCESS;
    }

    private function resolvePeriod(): string
    {
        if ($arg = $this->argument('period')) {
            if (!preg_match('/^\d{4}-\d{2}$/', $arg)) {
                $this->error("Invalid period '{$arg}'. Expected YYYY-MM.");
                exit(self::FAILURE);
            }

            return $arg;
        }

        $base = Carbon::now()->startOfMonth();

        return ($this->option('previous') ? $base->subMonth() : $base)->format('Y-m');
    }
}
