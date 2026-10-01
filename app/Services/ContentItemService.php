<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\ContentItemCollection;
use App\Models\ContentItemRevision;
use App\Models\ContentItemSubmission;
use App\Models\PublishedContent;
use App\Models\User;
use App\Notifications\ChecklistRevisionRequested;
use App\Services\Storage\UploadStaging;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create / submit / request-revision for a checklist's content items —
 * mirrors TaskService's own submit/requestRevision shape (see
 * app/Services/TaskService.php), and every write here checks the brand's
 * checklist isn't on hold first (see the SRS integration plan's Fix H).
 */
class ContentItemService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly UploadStaging $uploads,
    ) {}

    public function create(Brand $brand, array $data, User $actor): ContentItem
    {
        $checklist = $this->openChecklistFor($brand);

        return DB::transaction(function () use ($checklist, $brand, $data, $actor) {
            $item = ContentItem::create([
                'checklist_id' => $checklist->id,
                'brand_id'     => $brand->id,
                'product_id'   => $data['product_id'] ?? null,
                'category'     => $data['category'],
                'title'        => $data['title'],
                'status'       => ContentItem::STATUS_PENDING,
                'created_by'   => $actor->id,
            ]);

            $this->activityLog->log('Content Item', 'Created', $brand->client_id, null, [
                'brand'    => $brand->name, 'category' => $item->category, 'title' => $item->title,
            ]);

            return $item;
        });
    }

    /**
     * A fresh submission or a resubmission after revision — always a new
     * row, never an update to a prior one (see Fix C). Moves the item to
     * `available` regardless of what state it was in before.
     */
    public function submit(ContentItem $item, array $data, User $actor): ContentItemSubmission
    {
        $this->refuseIfOnHold($item);

        if (empty($data['link_url']) && empty($data['file'])) {
            throw ValidationException::withMessages(['file' => 'Attach a file or paste a link.']);
        }

        $path = null;
        $disk = null;
        if (!empty($data['file']) && $data['file'] instanceof UploadedFile) {
            $storedName = Str::uuid() . '.' . strtolower($data['file']->getClientOriginalExtension());
            [$path, $disk] = $this->uploads->store($data['file'], 'content-items/' . $item->id, $storedName);

            if (!$path) {
                throw ValidationException::withMessages([
                    'file' => 'The file could not be stored. Please try again, or ask an admin to check Settings → Storage & CDN.',
                ]);
            }
        }

        return DB::transaction(function () use ($item, $data, $actor, $path, $disk) {
            $submission = ContentItemSubmission::create([
                'content_item_id' => $item->id,
                'file_path'       => $path,
                'disk'            => $disk,
                'link_url'        => $data['link_url'] ?? null,
                'submitted_by'    => $actor->id,
            ]);

            $item->update(['status' => ContentItem::STATUS_AVAILABLE]);

            $this->activityLog->log('Content Item', 'Submitted', $item->brand->client_id, null, [
                'content_item_id' => $item->id, 'submission_id' => $submission->id,
            ]);

            return $submission;
        });
    }

    /**
     * Sends the item back for rework. A new content_item_revisions row
     * captures what the status was; nothing under the current submission —
     * its collection row, its publish row, its review — is ever touched
     * (see Fix C). The item itself lands on `needs_revision` so Content/
     * Design see exactly what still needs their attention; a resubmission
     * moves it to `available` again, same as any fresh submission.
     */
    public function requestRevision(ContentItem $item, array $data, User $actor): ContentItemRevision
    {
        $this->refuseIfOnHold($item);
        $submitter = $item->latestSubmission()?->submittedBy;

        $revision = DB::transaction(function () use ($item, $data, $actor) {
            $revision = ContentItemRevision::create([
                'content_item_id' => $item->id,
                'requested_by'    => $actor->id,
                'note'            => $data['note'] ?? null,
                'previous_status' => $item->status,
            ]);

            $item->update(['status' => ContentItem::STATUS_NEEDS_REVISION]);

            $this->activityLog->log('Content Item', 'Revision Requested', $item->brand->client_id, ['status' => $revision->previous_status], [
                'content_item_id' => $item->id, 'note' => $revision->note,
            ]);

            return $revision;
        });

        // Asking for yourself back isn't news; only notify someone else.
        if ($submitter && (int) $submitter->id !== (int) $actor->id) {
            $submitter->notify(new ChecklistRevisionRequested($item, $actor, $revision->note));
        }

        return $revision;
    }

    /**
     * SMM explicitly claims one specific submission as theirs to publish —
     * see Fix B. Only the item's current, latest submission can be
     * collected; an older one belongs to a superseded round.
     */
    public function collect(ContentItem $item, User $actor): ContentItemCollection
    {
        $this->refuseIfOnHold($item);

        return DB::transaction(function () use ($item, $actor) {
            // Locked so two concurrent "collect" clicks on the same item
            // can't both succeed — the loser re-reads a status that's
            // already moved on past STATUS_AVAILABLE once it gets the lock.
            $item = ContentItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $submission = $item->latestSubmission();

            if (!$submission || $item->status !== ContentItem::STATUS_AVAILABLE) {
                throw ValidationException::withMessages([
                    'item' => 'This item is not available to collect right now.',
                ]);
            }

            $collection = ContentItemCollection::create([
                'content_item_id' => $item->id,
                'submission_id'   => $submission->id,
                'collected_by'    => $actor->id,
                'collected_at'    => now(),
            ]);

            $item->update(['status' => ContentItem::STATUS_COLLECTED]);

            $this->activityLog->log('Content Item', 'Collected', $item->brand->client_id, null, [
                'content_item_id' => $item->id, 'submission_id' => $submission->id,
            ]);

            return $collection;
        });
    }

    /**
     * Publishes one specific, already-collected submission. Re-validates
     * every precondition inside the same transaction that writes the row —
     * see Fix G — so a stale browser tab can never publish a submission a
     * later revision has already superseded, even if it was collected
     * before that revision came in.
     *
     * $brand is the caller's own expected brand (e.g. the {brand} route
     * parameter) — checked independently here rather than trusted from the
     * controller, so the service holds this guarantee on its own even if
     * some future caller forgets to check it first (defense in depth; the
     * controller's own abort_if stays too).
     */
    public function publish(ContentItem $item, Brand $brand, ContentItemSubmission $submission, array $data, User $actor): PublishedContent
    {
        $this->refuseIfOnHold($item);

        if ((int) $item->brand_id !== (int) $brand->id) {
            throw ValidationException::withMessages(['item' => 'That content item does not belong to this brand.']);
        }

        if ((int) $submission->content_item_id !== (int) $item->id) {
            throw ValidationException::withMessages(['submission' => 'That submission does not belong to this item.']);
        }

        return DB::transaction(function () use ($item, $submission, $data, $actor) {
            // Locked so two SMM users publishing the same item at once can't
            // both succeed against what's already a superseded state.
            $item = ContentItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($item->submissions()->where('id', '>', $submission->id)->exists()) {
                throw ValidationException::withMessages([
                    'submission' => 'This version has been replaced by a newer submission — refresh and collect the current one.',
                ]);
            }

            // The item's own status is the single, atomically-updated source
            // of truth for "is this collected submission still eligible to
            // publish." requestRevision() always moves it off `collected`
            // the instant a revision is requested, and a fresh submission
            // moves it to `available` — either way, `collected` only holds
            // while nothing has superseded it. (A direct timestamp
            // comparison between content_item_revisions.created_at and
            // content_item_collections.collected_at was tried first and
            // abandoned: Eloquent's date serialization strips sub-second
            // precision before the value reaches the database, so two
            // events in the same second become indistinguishable there —
            // status, updated in place on one row, has no such gap.)
            if ($item->status !== ContentItem::STATUS_COLLECTED) {
                throw ValidationException::withMessages([
                    'submission' => 'This item is no longer collected and ready to publish — it may have been sent back for revision, or already published.',
                ]);
            }

            $collection = ContentItemCollection::where('submission_id', $submission->id)->latest('id')->first();
            if (!$collection) {
                throw ValidationException::withMessages([
                    'submission' => 'This version has not been collected yet.',
                ]);
            }

            $published = PublishedContent::create([
                'content_item_id'   => $item->id,
                'submission_id'     => $submission->id,
                'brand_id'          => $item->brand_id,
                'facebook_post_url' => $data['facebook_post_url'],
                'published_by'      => $actor->id,
                'published_at'      => now(),
            ]);

            $item->update(['status' => ContentItem::STATUS_PUBLISHED]);

            $this->activityLog->log('Content Item', 'Published', $item->brand->client_id, null, [
                'content_item_id' => $item->id, 'submission_id' => $submission->id, 'facebook_post_url' => $published->facebook_post_url,
            ]);

            return $published;
        });
    }

    /** The brand's checklist, refusing when there isn't one yet or it's on hold. */
    private function openChecklistFor(Brand $brand)
    {
        $checklist = $brand->checklist;

        if (!$checklist) {
            throw ValidationException::withMessages([
                'brand' => "{$brand->name} doesn't have an active checklist yet — its advertising-budget payment and content-charge invoice have to be recorded first.",
            ]);
        }

        $this->refuseIfChecklistOnHold($checklist);

        return $checklist;
    }

    private function refuseIfOnHold(ContentItem $item): void
    {
        $this->refuseIfChecklistOnHold($item->checklist);
    }

    private function refuseIfChecklistOnHold($checklist): void
    {
        if ($checklist->isOnHold()) {
            throw ValidationException::withMessages([
                'checklist' => $checklist->on_hold_reason ?: 'This checklist is on hold — new work is paused until a Manager clears it.',
            ]);
        }
    }
}
