<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\BrandChecklist;
use App\Models\ContentItem;
use App\Models\PublishedContent;
use Illuminate\Support\Collection;

/**
 * Read-only projection of a Brand's real content workflow into one shared
 * "checklist" shape — never a second source of truth. Every value here is
 * derived straight from ContentItem/ContentItemSubmission/
 * ContentItemCollection/PublishedContent/ContentItemRevision, the same
 * records the Raw Content, Designer, SMM and Marketing panels already
 * write to. Nothing here is ever written back; the operational panels
 * remain the only place work happens.
 *
 * See the gap-analysis diagnosis this implements: BrandChecklist's own
 * docblock already says "not a fixed shape — see content_items".
 */
class BrandChecklistProjectionService
{
    /**
     * The full per-item, per-version breakdown for one Brand. Every
     * relationship is eager-loaded up front and PublishedContent::
     * annotateReviewStates() is called exactly once across every
     * publication involved — a handful of queries total, never one per
     * item or per submission.
     *
     * @return array{brand: Brand, checklist: BrandChecklist|null, categories: array<string, Collection>, counts: array<string, int>}
     */
    public function detail(Brand $brand): array
    {
        $brand->loadMissing([
            'checklist.items' => fn ($q) => $q->orderBy('category')->orderBy('id'),
            'checklist.items.createdBy:id,name',
            'checklist.items.submissions' => fn ($q) => $q->orderBy('id'),
            'checklist.items.submissions.submittedBy:id,name',
            'checklist.items.submissions.approval.approvedBy:id,name',
            'checklist.items.submissions.collection.collectedBy:id,name',
            'checklist.items.submissions.publishedContents.publishedBy:id,name',
            'checklist.items.submissions.publishedContents.reviewedBy:id,name',
        ]);

        $checklist = $brand->checklist;
        $items = $checklist?->items ?? new Collection;

        // One batched review-state pass across every publication this brand
        // has, across every item and version — the same centralized helper
        // Marketing's queue and SMM Published already use, so this screen
        // can never disagree with them.
        $allPublications = $items
            ->flatMap(fn (ContentItem $item) => $item->submissions)
            ->flatMap(fn ($submission) => $submission->publishedContents);
        PublishedContent::annotateReviewStates($allPublications);

        $categories = [];
        $counts = [];
        foreach (ContentItem::$categories as $category) {
            $categoryItems = $items->where('category', $category)->values();
            $categories[$category] = $categoryItems->map(fn (ContentItem $item) => $this->presentItem($item));
            $counts[$category] = $categoryItems->count();
        }

        return compact('brand', 'checklist', 'categories', 'counts');
    }

    /**
     * Per-brand item counts for the whole company at once — the Manager
     * Oversight cross-brand summary row. One query regardless of brand
     * count; the detail() method above is the source for everything beyond
     * a count.
     *
     * @return Collection<int, Collection> keyed by brand_id, each a Collection keyed by category => count
     */
    public function categoryCounts(): Collection
    {
        return ContentItem::query()
            ->selectRaw('brand_id, category, count(*) as total')
            ->groupBy('brand_id', 'category')
            ->get()
            ->groupBy('brand_id')
            ->map(fn (Collection $rows) => $rows->pluck('total', 'category'));
    }

    /**
     * One item's current state plus its full, ordered version history —
     * each version's collection/publication read strictly from rows tied
     * to THAT submission_id, never the item's latest. Display-only version
     * labels (V1, V2, ...) come from the append-only submission sequence;
     * nothing is persisted for them.
     *
     * @return array{item: ContentItem, latest: ?array, history: Collection}
     */
    private function presentItem(ContentItem $item): array
    {
        $lastIndex = $item->submissions->count() - 1;

        $history = $item->submissions->values()->map(function ($submission, int $index) use ($item, $lastIndex) {
            // A submission can in principle be (re)published more than
            // once; the most recent publish of THIS submission is what's
            // current for THIS version — never a different submission's.
            $publication = $submission->publishedContents->sortByDesc('id')->first();
            $reviewState = $publication?->review_state;

            return [
                'submission' => $submission,
                'version_label' => 'V'.($index + 1),
                'approval' => $submission->approval,
                'collection' => $submission->collection,
                'publication' => $publication,
                'review_state' => $reviewState,
                'stage_label' => $this->stageLabel($item, $submission, $index === $lastIndex, $reviewState),
            ];
        });

        return [
            'item' => $item,
            'latest' => $history->last(),
            'history' => $history,
        ];
    }

    /**
     * The precise, submission-scoped lifecycle label the Shared Checklist
     * shows — distinct from content_items.status (one coarse value for the
     * whole item) and distinct from "Reviewed" meaning two different things
     * at two different stages. Reads only facts tied to THIS exact
     * submission (its own approval/collection/publication rows), except for
     * "Revision Requested", which is necessarily item-level (a revision
     * request moves the item, not a specific submission) and so only ever
     * applies to the item's current latest version — an older version's
     * history entry keeps whatever state it last reached.
     */
    private function stageLabel(ContentItem $item, $submission, bool $isLatest, ?string $reviewState): string
    {
        if ($isLatest && $item->status === ContentItem::STATUS_NEEDS_REVISION) {
            return 'Revision Requested';
        }

        if ($submission->publishedContents->isNotEmpty()) {
            return match ($reviewState) {
                'reviewed' => 'Reviewed / Complete',
                'revision_requested' => 'Revision Requested',
                default => 'Awaiting Final Marketing Review',
            };
        }

        if ($submission->collection) {
            return 'Collected';
        }

        if ($submission->approval) {
            return 'Marketing Approved — Ready for SMM';
        }

        return 'Pending Marketing Check';
    }
}
