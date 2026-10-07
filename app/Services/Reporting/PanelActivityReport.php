<?php

namespace App\Services\Reporting;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PerformancePointEvent;
use App\Models\SmmClientConversation;
use App\Models\User;
use App\Support\ReportingPeriod;
use Illuminate\Support\Collection;

/**
 * Builds the Daily / Monthly / Yearly activity sections for each workflow
 * panel and for Manager Oversight. It only chooses which shared metrics each
 * screen shows (see WorkflowActivityReport for what each one counts), so no
 * screen can invent its own version of a business event.
 *
 * Current queues are not built here. They stay on their own, unfiltered
 * endpoints, so a historical period never hides current actionable work.
 */
final class PanelActivityReport
{
    /** Raw Content and Designer: work this maker produced in the period. */
    private const AUTHOR_METRICS = [
        'submitted' => WorkflowActivityReport::SUBMISSIONS,
        'first_submitted' => WorkflowActivityReport::FIRST_SUBMISSIONS,
        'resubmitted' => WorkflowActivityReport::RESUBMISSIONS,
        'revisions_received' => WorkflowActivityReport::REVISIONS_RECEIVED,
        'completed' => WorkflowActivityReport::COMPLETED,
    ];

    /** Marketing's pre-publish and post-publish activity, the same columns on Marketing and Manager. */
    private const MARKETING_METRICS = [
        'received' => WorkflowActivityReport::SUBMISSIONS,
        'handed_over' => WorkflowActivityReport::HANDED_OVER,
        'returned_for_final_check' => WorkflowActivityReport::PUBLISHED,
        'completed' => WorkflowActivityReport::COMPLETED,
        'revision_requested' => WorkflowActivityReport::MARKETING_REVISIONS,
    ];

    /** SMM spans every category, since it receives approved work from all three makers. */
    private const SMM_METRICS = [
        'received' => WorkflowActivityReport::HANDED_OVER,
        'collected' => WorkflowActivityReport::COLLECTED,
        'published' => WorkflowActivityReport::PUBLISHED,
        'revision_requested' => WorkflowActivityReport::SMM_REVISIONS,
    ];

    public function __construct(private readonly WorkflowActivityReport $report) {}

    /**
     * Raw Content panel. The panel's queue covers raw_content AND
     * advertising_content, so each category gets its own row and a combined
     * Content row is labelled as such. Nothing mixes them silently.
     *
     * @return array{categories: array<string, array<string, int>>, combined: array<string, int>}
     */
    public function rawContent(ReportingPeriod $period): array
    {
        $categories = [ContentItem::CATEGORY_RAW_CONTENT, ContentItem::CATEGORY_ADVERTISING_CONTENT];
        $rows = $this->perCategory(self::AUTHOR_METRICS, $period, $categories);

        return [
            'categories' => $rows,
            'combined' => $this->sumRows($rows, self::AUTHOR_METRICS),
        ];
    }

    /**
     * Designer panel. Poster work only.
     *
     * @return array{categories: array<string, array<string, int>>}
     */
    public function designer(ReportingPeriod $period): array
    {
        return ['categories' => $this->perCategory(self::AUTHOR_METRICS, $period, [ContentItem::CATEGORY_POSTER])];
    }

    /**
     * SMM panel. Totals across every category.
     *
     * @return array<string, int>
     */
    public function smm(ReportingPeriod $period): array
    {
        return $this->totals(self::SMM_METRICS, $period);
    }

    /**
     * SMM client conversations for a period. Submitted, approved and rejected
     * are timestamped by the event (submitted_at, reviewed_at). Potential Client
     * points are the ledger awards by awarded_at. Pending review is current work,
     * so it is never period-filtered.
     *
     * @return array{submitted: int, approved: int, rejected: int, pending_review: int, potential_client_points: int}
     */
    public function smmConversations(ReportingPeriod $period, ?User $smm = null): array
    {
        [$since, $until] = $period->bounds();

        $owned = fn () => SmmClientConversation::query()
            ->when($smm !== null, fn ($q) => $q->where('submitted_by', $smm->id));

        $points = PerformancePointEvent::query()
            ->where('event_type', PerformancePointEvent::EVENT_POTENTIAL_CLIENT)
            ->when($smm !== null, fn ($q) => $q->where('user_id', $smm->id))
            ->where('awarded_at', '>=', $since)
            ->where('awarded_at', '<', $until)
            ->sum('points');

        return [
            'submitted' => $owned()->where('submitted_at', '>=', $since)->where('submitted_at', '<', $until)->count(),
            'approved' => $owned()->where('review_status', SmmClientConversation::STATUS_APPROVED)
                ->where('reviewed_at', '>=', $since)->where('reviewed_at', '<', $until)->count(),
            'rejected' => $owned()->where('review_status', SmmClientConversation::STATUS_REJECTED)
                ->where('reviewed_at', '>=', $since)->where('reviewed_at', '<', $until)->count(),
            'pending_review' => $owned()->where('review_status', SmmClientConversation::STATUS_PENDING)->count(),
            'potential_client_points' => (int) $points,
        ];
    }

    /**
     * Marketing's brand-wise historical activity. Used by the Marketing
     * dashboard and by Manager Oversight, so both always read the same numbers.
     *
     * @param  Collection<int, Brand>  $brands
     * @return array{rows: array<int, array<string, int>>, totals: array<string, int>}
     */
    public function marketingBrands(ReportingPeriod $period, Collection $brands): array
    {
        $byBrand = collect(self::MARKETING_METRICS)->map(
            fn (string $metric) => $this->report->count($metric, $period, WorkflowActivityReport::BY_BRAND)
        );

        $rows = [];
        foreach ($brands as $brand) {
            $rows[$brand->id] = collect(self::MARKETING_METRICS)
                ->keys()
                ->mapWithKeys(fn (string $key) => [$key => (int) ($byBrand[$key][$brand->id] ?? 0)])
                ->all();
        }

        return [
            'rows' => $rows,
            'totals' => $this->sumRows($rows, self::MARKETING_METRICS),
        ];
    }

    /**
     * Manager Oversight: cross-department totals, using the same panel
     * definitions above. Marketing's department row is the same
     * marketingBrands() total the Marketing dashboard shows.
     *
     * @param  Collection<int, Brand>  $brands
     * @return array{departments: array<string, array<string, int>>, brands: array{rows: array<int, array<string, int>>, totals: array<string, int>}}
     */
    public function managerOversight(ReportingPeriod $period, Collection $brands): array
    {
        $content = $this->rawContent($period)['categories'];
        $design = $this->designer($period)['categories'];
        $marketing = $this->marketingBrands($period, $brands);

        return [
            'departments' => [
                'content_raw' => $content[ContentItem::CATEGORY_RAW_CONTENT],
                'content_advertising' => $content[ContentItem::CATEGORY_ADVERTISING_CONTENT],
                'design' => $design[ContentItem::CATEGORY_POSTER],
                'smm' => $this->smm($period),
                'marketing' => $marketing['totals'],
            ],
            'brands' => $marketing,
        ];
    }

    /**
     * @param  array<string, string>  $metrics  output key => shared metric
     * @param  array<int, string>  $categories
     * @return array<string, array<string, int>> category => key => count
     */
    private function perCategory(array $metrics, ReportingPeriod $period, array $categories): array
    {
        $byCategory = collect($metrics)->map(
            fn (string $metric) => $this->report->count($metric, $period, WorkflowActivityReport::BY_CATEGORY, $categories)
        );

        $rows = [];
        foreach ($categories as $category) {
            $rows[$category] = collect($metrics)
                ->keys()
                ->mapWithKeys(fn (string $key) => [$key => (int) ($byCategory[$key][$category] ?? 0)])
                ->all();
        }

        return $rows;
    }

    /** @param  array<string, string>  $metrics  @return array<string, int> */
    private function totals(array $metrics, ReportingPeriod $period): array
    {
        return collect($metrics)
            ->map(fn (string $metric) => (int) $this->report->count($metric, $period, WorkflowActivityReport::BY_CATEGORY)->sum())
            ->all();
    }

    /**
     * Column totals across rows. The keys come from the metric definition, not
     * from the rows, so an empty brand list still reports zeros.
     *
     * @param  array<int|string, array<string, int>>  $rows
     * @param  array<string, string>  $metrics
     * @return array<string, int>
     */
    private function sumRows(array $rows, array $metrics): array
    {
        return collect(array_keys($metrics))
            ->mapWithKeys(fn (string $key) => [$key => (int) collect($rows)->sum(fn (array $row) => $row[$key] ?? 0)])
            ->all();
    }
}
