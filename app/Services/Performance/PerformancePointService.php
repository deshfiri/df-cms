<?php

namespace App\Services\Performance;

use App\Models\PerformancePointEvent;
use App\Models\User;
use App\Support\ReportingPeriod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place Performance Points are awarded and read.
 *
 * Awards are idempotent per (event, source). A repeated or raced award returns
 * the row that already exists and never creates a second one. Totals are
 * grouped aggregates over the ledger, and they feed the existing score through
 * PerformanceCalculationService::finalScore(), so there is no second score to
 * keep in sync.
 */
final class PerformancePointService
{
    /** Points for an event, from config/performance.php. Unknown events are a programming error. */
    public function pointsFor(string $event): int
    {
        if (! array_key_exists($event, config('performance.points', []))) {
            throw new \InvalidArgumentException("Unknown performance point event [{$event}].");
        }

        return (int) config("performance.points.{$event}");
    }

    /** Score added to a final score for each point (config/performance.php). */
    public function scorePerPoint(): float
    {
        return (float) config('performance.score_per_point', 0);
    }

    /**
     * Awards one event to one exact user. Call it only after the business
     * transition has committed, so an award never refers to an action that
     * was rolled back.
     */
    public function award(
        string $event,
        string $sourceType,
        int $sourceId,
        User $recipient,
        ?int $brandId,
        ?User $awardedBy = null,
        ?string $reason = null,
    ): PerformancePointEvent {
        $existing = $this->find($event, $sourceType, $sourceId);
        if ($existing) {
            return $existing;
        }

        try {
            return PerformancePointEvent::create([
                'user_id' => $recipient->id,
                'event_type' => $event,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'brand_id' => $brandId,
                'points' => $this->pointsFor($event),
                'awarded_by' => $awardedBy?->id,
                'awarded_at' => now(),
                'reason' => $reason,
            ]);
        } catch (QueryException $e) {
            // A concurrent request won the unique (event, source) race. Return its row.
            return $this->find($event, $sourceType, $sourceId) ?? throw $e;
        }
    }

    public function find(string $event, string $sourceType, int $sourceId): ?PerformancePointEvent
    {
        return PerformancePointEvent::where('event_type', $event)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    /**
     * Points per user per event for one period, in a single grouped query.
     * Used once per scoreboard run, so the score calculation never runs a query
     * per employee.
     *
     * @param  Collection<int, int>|array<int, int>  $userIds
     * @return array<int, array<string, int>> user_id => event => points
     */
    public function totalsForUsers($userIds, ReportingPeriod $period): array
    {
        [$since, $until] = $period->bounds();

        $rows = PerformancePointEvent::query()
            ->whereIn('user_id', collect($userIds)->all())
            ->where('awarded_at', '>=', $since)
            ->where('awarded_at', '<', $until)
            ->groupBy('user_id', 'event_type')
            ->selectRaw('user_id, event_type, sum(points) as total')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row->user_id][$row->event_type] = (int) $row->total;
        }

        return $totals;
    }

    /**
     * One user's points for a period, with a score contribution. Unlike the
     * scoreboard path, this is not prefetched, so it issues one grouped query.
     *
     * @return array{points: int, score: float, by_event: array<string, int>}
     */
    public function summaryForUser(User $user, ReportingPeriod $period): array
    {
        $byEvent = $this->totalsForUsers([$user->id], $period)[$user->id] ?? [];

        return $this->summarize($byEvent);
    }

    /**
     * @param  array<string, int>  $byEvent  event => points
     * @return array{points: int, score: float, by_event: array<string, int>}
     */
    public function summarize(array $byEvent): array
    {
        $points = array_sum($byEvent);

        return [
            'points' => (int) $points,
            'score' => round($points * $this->scorePerPoint(), 2),
            'by_event' => $byEvent,
        ];
    }

    /**
     * Event-level breakdown for the Performance view: how many times each event
     * was awarded, and the points, for the selected period. Counts and sums come
     * from the ledger, never from reconstructing workflow status.
     *
     * @return array<string, array{count: int, points: int}>
     */
    /**
     * $brandId narrows the ledger to one brand's events, using the brand
     * already recorded on each event at award time (see award()) — a
     * filtering/breakdown concern only. It never changes which events exist
     * or how many points they're worth, so the official final score (which
     * sums every event, brand or no brand) is completely unaffected.
     */
    public function breakdownForUser(User $user, ReportingPeriod $period, ?int $brandId = null): array
    {
        [$since, $until] = $period->bounds();

        $rows = DB::table('performance_point_events')
            ->where('user_id', $user->id)
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->where('awarded_at', '>=', $since)
            ->where('awarded_at', '<', $until)
            ->groupBy('event_type')
            ->selectRaw('event_type, count(*) as awards, sum(points) as points')
            ->get();

        $breakdown = [];
        foreach ($rows as $row) {
            $breakdown[$row->event_type] = ['count' => (int) $row->awards, 'points' => (int) $row->points];
        }

        return $breakdown;
    }
}
