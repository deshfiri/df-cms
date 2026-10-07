<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\ContentItemRevision;
use App\Models\ContentItemStageOwner;
use App\Models\ContentItemSubmission;
use App\Models\PublishedContent;
use App\Models\User;
use App\Services\Workflow\StageOwnershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Claim buttons and the Manager reassignment. Every method checks that the
 * brand, item and version belong together, then hands the decision to
 * StageOwnershipService, which owns eligibility, atomicity and audit.
 */
class StageOwnershipController extends Controller
{
    public function __construct(private readonly StageOwnershipService $ownership) {}

    /** Claim the Marketing pre-publish check of one exact version. */
    public function claimPrePublish(Request $request, Brand $brand, ContentItem $contentItem, ContentItemSubmission $submission): JsonResponse
    {
        abort_if($contentItem->brand_id !== $brand->id, 404);
        abort_if($submission->content_item_id !== $contentItem->id, 404);
        abort_unless($request->user()->can('manage publishing-review'), 403);

        $owner = $this->ownership->claim(
            ContentItemStageOwner::STAGE_PRE_PUBLISH,
            StageOwnershipService::pre_publish($submission->id),
            $contentItem, $request->user(), ['submission_id' => $submission->id],
        );

        return $this->owned($owner);
    }

    /** Claim the revision an item is waiting on, so the claimer can fix and resubmit it. */
    public function claimRevision(Request $request, Brand $brand, ContentItem $contentItem): JsonResponse
    {
        abort_if($contentItem->brand_id !== $brand->id, 404);

        [$ref, $links] = $this->ownership->makerStage($contentItem);
        if (! str_starts_with($ref, 'revision:')) {
            throw ValidationException::withMessages(['stage' => 'Nothing is waiting for a fix on this item.']);
        }

        $owner = $this->ownership->claim(ContentItemStageOwner::STAGE_MAKER, $ref, $contentItem, $request->user(), $links);

        return $this->owned($owner);
    }

    /** Claim the Marketing final review of one publication. */
    public function claimReview(Request $request, Brand $brand, PublishedContent $publishedContent): JsonResponse
    {
        abort_if($publishedContent->brand_id !== $brand->id, 404);
        abort_unless($request->user()->can('manage publishing-review'), 403);

        $owner = $this->ownership->claim(
            ContentItemStageOwner::STAGE_FINAL_REVIEW,
            StageOwnershipService::finalReview($publishedContent->id),
            $publishedContent->item, $request->user(), ['publication_id' => $publishedContent->id],
        );

        return $this->owned($owner);
    }

    /**
     * Manager or Super Admin override. The Manager names the stage, the new
     * owner, and why. The service releases the current owner and records the
     * change. The stage is identified by an id that must belong to this item.
     */
    public function reassign(Request $request, Brand $brand, ContentItem $contentItem): JsonResponse
    {
        abort_if($contentItem->brand_id !== $brand->id, 404);

        $data = $request->validate([
            'stage' => ['required', Rule::in([
                ContentItemStageOwner::STAGE_MAKER,
                ContentItemStageOwner::STAGE_PRE_PUBLISH,
                ContentItemStageOwner::STAGE_PUBLISH,
                ContentItemStageOwner::STAGE_FINAL_REVIEW,
            ])],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'submission_id' => ['nullable', 'integer'],
            'publication_id' => ['nullable', 'integer'],
            'revision_id' => ['nullable', 'integer'],
        ]);

        [$ref, $links] = $this->stageReference($contentItem, $data);
        $target = User::findOrFail($data['user_id']);

        $owner = $this->ownership->reassign($data['stage'], $ref, $contentItem, $target, $request->user(), $data['reason'], $links);

        return $this->owned($owner);
    }

    /**
     * Resolves the stage reference from ids that must belong to this item, so a
     * request can never name another item's version or publication.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private function stageReference(ContentItem $item, array $data): array
    {
        return match ($data['stage']) {
            ContentItemStageOwner::STAGE_PRE_PUBLISH, ContentItemStageOwner::STAGE_PUBLISH => (function () use ($item, $data) {
                $submission = ContentItemSubmission::where('content_item_id', $item->id)->findOrFail($data['submission_id'] ?? 0);

                return [
                    $data['stage'] === ContentItemStageOwner::STAGE_PRE_PUBLISH
                        ? StageOwnershipService::pre_publish($submission->id)
                        : StageOwnershipService::publish($submission->id),
                    ['submission_id' => $submission->id],
                ];
            })(),
            ContentItemStageOwner::STAGE_FINAL_REVIEW => (function () use ($item, $data) {
                $published = PublishedContent::where('content_item_id', $item->id)->findOrFail($data['publication_id'] ?? 0);

                return [StageOwnershipService::finalReview($published->id), ['publication_id' => $published->id]];
            })(),
            ContentItemStageOwner::STAGE_MAKER => (function () use ($item, $data) {
                $revision = ContentItemRevision::where('content_item_id', $item->id)->findOrFail($data['revision_id'] ?? 0);

                return [StageOwnershipService::revision($revision->id), ['revision_id' => $revision->id]];
            })(),
        };
    }

    private function owned(ContentItemStageOwner $owner): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'stage' => $owner->stage,
                'stage_ref' => $owner->stage_ref,
                'user_id' => $owner->user_id,
                'user' => $owner->user?->name,
                'source' => $owner->source,
                'assigned_by' => $owner->assigned_by,
                'acquired_at' => $owner->acquired_at?->toIso8601String(),
            ],
        ]);
    }
}
