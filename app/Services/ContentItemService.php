<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\ContentItemRevision;
use App\Models\ContentItemSubmission;
use App\Models\User;
use App\Services\Storage\UploadStaging;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
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

        return DB::transaction(function () use ($item, $data, $actor) {
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
