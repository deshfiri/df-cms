<?php

namespace App\Services\Reporting;

use App\Models\ContentItem;
use App\Models\User;
use App\Support\ReportingPeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single definition of every historical workflow event count used by the
 * Marketing dashboard, the Raw Content / Designer / SMM panels and Manager
 * Oversight. A metric is defined here once, so two screens can never disagree
 * about the same business event.
 *
 * Every metric counts real events and is timestamped by the event itself:
 *
 *   submissions          content_item_submissions.created_at   (every version)
 *   first_submissions    as above, only an item's first version
 *   resubmissions        as above, only a version after an earlier one
 *   handed_over          content_item_submission_approvals.approved_at
 *   collected            content_item_collections.collected_at
 *   published            published_contents.published_at
 *   completed            published_contents.reviewed_at, current version only
 *   revisions_received   content_item_revisions.created_at, any stage
 *   marketing_revisions  revisions while `available`, requested by Marketing
 *   smm_revisions        revisions while `collected`, requested by SMM
 *
 * Each call is one grouped query, so the query count does not grow with the
 * number of brands or categories. Soft-deleted items are never counted, and
 * every period is the half-open [since, until) window from ReportingPeriod.
 */
final class WorkflowActivityReport
{
    public const SUBMISSIONS = 'submissions';

    public const FIRST_SUBMISSIONS = 'first_submissions';

    public const RESUBMISSIONS = 'resubmissions';

    public const HANDED_OVER = 'handed_over';

    public const COLLECTED = 'collected';

    public const PUBLISHED = 'published';

    public const COMPLETED = 'completed';

    public const REVISIONS_RECEIVED = 'revisions_received';

    public const MARKETING_REVISIONS = 'marketing_revisions';

    public const SMM_REVISIONS = 'smm_revisions';

    public const BY_BRAND = 'content_items.brand_id';

    public const BY_CATEGORY = 'content_items.category';

    /**
     * @param  array<int, string>|null  $categories  Restrict to these content categories; null means every category.
     * @return Collection<int|string, int> group value (brand_id or category) => count, only for groups with a match
     */
    public function count(string $metric, ReportingPeriod $period, string $groupBy, ?array $categories = null): Collection
    {
        return $this->query($metric, $period)
            ->when($categories !== null, fn (Builder $q) => $q->whereIn('content_items.category', $categories))
            ->selectRaw($groupBy.' as group_key, count(*) as total')
            ->groupBy($groupBy)
            ->pluck('total', 'group_key');
    }

    private function query(string $metric, ReportingPeriod $period): Builder
    {
        [$since, $until] = $period->bounds();

        $query = match ($metric) {
            self::SUBMISSIONS => $this->live(DB::table('content_item_submissions as s'), 's.content_item_id'),
            self::FIRST_SUBMISSIONS => $this->live(DB::table('content_item_submissions as s'), 's.content_item_id')
                ->whereNotExists(fn (Builder $q) => $this->earlierVersion($q)),
            self::RESUBMISSIONS => $this->live(DB::table('content_item_submissions as s'), 's.content_item_id')
                ->whereExists(fn (Builder $q) => $this->earlierVersion($q)),
            self::HANDED_OVER => $this->live(DB::table('content_item_submission_approvals as a'), 'a.content_item_id'),
            self::COLLECTED => $this->live(DB::table('content_item_collections as c'), 'c.content_item_id'),
            self::PUBLISHED => $this->live(DB::table('published_contents as p'), 'p.content_item_id'),
            // A review of a superseded version never completes the current cycle
            // (ContentPublishedAndReviewed is not sent for it), so it is excluded.
            self::COMPLETED => $this->live(DB::table('published_contents as p'), 'p.content_item_id')
                ->join('content_item_submissions as v', 'v.id', '=', 'p.submission_id')
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')
                    ->from('content_item_submissions as newer')
                    ->whereColumn('newer.content_item_id', 'v.content_item_id')
                    ->whereColumn('newer.id', '>', 'v.id')),
            self::REVISIONS_RECEIVED => $this->live(DB::table('content_item_revisions as r'), 'r.content_item_id'),
            self::MARKETING_REVISIONS => $this->live(DB::table('content_item_revisions as r'), 'r.content_item_id')
                ->where('r.previous_status', ContentItem::STATUS_AVAILABLE)
                ->whereIn('r.requested_by', $this->roleUserIds('Marketing')),
            self::SMM_REVISIONS => $this->live(DB::table('content_item_revisions as r'), 'r.content_item_id')
                ->where('r.previous_status', ContentItem::STATUS_COLLECTED)
                ->whereIn('r.requested_by', $this->roleUserIds('Social Media Manager')),
            default => throw new \InvalidArgumentException("Unknown workflow metric [{$metric}]."),
        };

        return $this->betweenPeriod($query, $this->timestampColumn($metric), $since, $until);
    }

    /** The event's own timestamp, never content_items.created_at. */
    private function timestampColumn(string $metric): string
    {
        return match ($metric) {
            self::SUBMISSIONS, self::FIRST_SUBMISSIONS, self::RESUBMISSIONS => 's.created_at',
            self::HANDED_OVER => 'a.approved_at',
            self::COLLECTED => 'c.collected_at',
            self::PUBLISHED => 'p.published_at',
            self::COMPLETED => 'p.reviewed_at',
            self::REVISIONS_RECEIVED, self::MARKETING_REVISIONS, self::SMM_REVISIONS => 'r.created_at',
        };
    }

    /** Joins the item and drops soft-deleted items, so a deleted item never inflates any metric. */
    private function live(Builder $query, string $itemIdColumn): Builder
    {
        return $query->join('content_items', 'content_items.id', '=', $itemIdColumn)
            ->whereNull('content_items.deleted_at');
    }

    /** "An earlier version of the same item exists" — the test that tells a resubmission from a first submission. */
    private function earlierVersion(Builder $query): Builder
    {
        return $query->selectRaw('1')
            ->from('content_item_submissions as earlier')
            ->whereColumn('earlier.content_item_id', 's.content_item_id')
            ->whereColumn('earlier.id', '<', 's.id');
    }

    /** Half-open [since, until). An event at exactly the next period's first second belongs to that next period. */
    private function betweenPeriod(Builder $query, string $column, CarbonInterface $since, CarbonInterface $until): Builder
    {
        return $query->where($column, '>=', $since)->where($column, '<', $until);
    }

    /**
     * Ids of users holding a role by name. A role that does not exist simply
     * has no users, rather than throwing RoleDoesNotExist.
     *
     * @return array<int, int>
     */
    private function roleUserIds(string $role): array
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', $role))->pluck('id')->all();
    }
}
