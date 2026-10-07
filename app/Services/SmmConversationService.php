<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\PerformancePointEvent;
use App\Models\Product;
use App\Models\SmmClientConversation;
use App\Models\User;
use App\Notifications\ConversationReadyForReview;
use App\Notifications\ConversationReviewed;
use App\Services\Concerns\NotifiesStaff;
use App\Services\Performance\PerformancePointService;
use App\Services\Storage\UploadStaging;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SMM's client conversation records and Marketing's verification of them.
 *
 * Rules, all enforced here and never only in the UI:
 *  - The product must belong to the brand it is logged against.
 *  - The record belongs to the SMM user who submitted it. Nobody else can
 *    review it as their own, change it, or be credited for it.
 *  - Approving awards one Potential Client point to that submitter, and the award
 *    commits with the approval. Rejecting awards nothing.
 *  - A decision is final. Repeating the same decision is a no-op, and a different
 *    one is refused, so a retry can never award twice or flip the verdict.
 *  - The brand's checklist hold does not block these records. The hold stops
 *    content workflow mutations, and a client conversation is outside that flow.
 */
final class SmmConversationService
{
    use NotifiesStaff;

    /** Days within which the same reference for the same brand is treated as a likely duplicate. */
    private const DUPLICATE_WINDOW_DAYS = 7;

    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly UploadStaging $uploads,
        private readonly PerformancePointService $points,
    ) {}

    /**
     * @param  array{product_id?: ?int, reference: string, note?: ?string, idempotency_key: string, confirm_duplicate?: bool}  $data
     */
    public function submit(Brand $brand, array $data, UploadedFile $evidence, User $smm): SmmClientConversation
    {
        // A repeated submission with the same key returns the record it already made.
        $existing = SmmClientConversation::where('submitted_by', $smm->id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();
        if ($existing) {
            return $existing;
        }

        $productId = $this->validatedProductId($brand, $data['product_id'] ?? null);

        if (! ($data['confirm_duplicate'] ?? false) && $this->looksDuplicated($brand, $productId, $data['reference'], $smm)) {
            throw ValidationException::withMessages([
                'duplicate' => 'You logged this same reference for this brand in the last '.self::DUPLICATE_WINDOW_DAYS.' days. Confirm to log it again.',
            ]);
        }

        // The type comes from the file's real bytes, never from its name or the
        // client's claimed type. A renamed SVG or a fake PNG is refused here.
        $realMime = (new \finfo(FILEINFO_MIME_TYPE))->file($evidence->getRealPath());
        $extension = match ($realMime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => throw ValidationException::withMessages(['evidence' => 'Attach a real PNG, JPG or WebP screenshot.']),
        };

        [$path, $disk] = $this->uploads->store($evidence, 'smm-conversations/'.$brand->id, Str::uuid().'.'.$extension);
        if (! $path) {
            throw ValidationException::withMessages(['evidence' => 'The screenshot could not be stored. Please try again.']);
        }

        $conversation = DB::transaction(function () use ($brand, $productId, $data, $smm, $evidence, $path, $disk, $realMime) {
            $conversation = SmmClientConversation::create([
                'brand_id' => $brand->id,
                'product_id' => $productId,
                'submitted_by' => $smm->id,
                'reference' => trim($data['reference']),
                'note' => isset($data['note']) ? trim((string) $data['note']) : null,
                'evidence_disk' => $disk,
                'evidence_path' => $path,
                'evidence_mime' => $realMime,
                'evidence_size' => (int) $evidence->getSize(),
                'idempotency_key' => $data['idempotency_key'],
                'review_status' => SmmClientConversation::STATUS_PENDING,
                'submitted_at' => now(),
            ]);

            $this->activityLog->log('SMM Conversation', 'Submitted', $brand->client_id, null, [
                'conversation_id' => $conversation->id, 'brand_id' => $brand->id, 'product_id' => $productId,
            ]);

            return $conversation;
        });

        // After commit, so a rolled-back record never tells Marketing anything.
        $this->notifySafely(fn () => $this->notifyStaff(
            ['Marketing'],
            new ConversationReadyForReview($conversation->load('brand:id,name'), $smm),
            permission: 'manage publishing-review',
            except: $smm,
        ));

        return $conversation;
    }

    /**
     * Marketing's verdict on one conversation. Approve awards the submitter's
     * Potential Client point in the same transaction. Reject awards nothing.
     */
    public function review(SmmClientConversation $conversation, string $decision, User $reviewer, ?string $note = null): SmmClientConversation
    {
        if (! in_array($decision, [SmmClientConversation::STATUS_APPROVED, SmmClientConversation::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose approve or reject.']);
        }

        if ((int) $conversation->submitted_by === (int) $reviewer->id) {
            throw ValidationException::withMessages(['decision' => 'You cannot review a conversation you submitted.']);
        }

        $changed = DB::transaction(function () use ($conversation, $decision, $reviewer, $note) {
            $locked = SmmClientConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                if ($locked->review_status === $decision) {
                    return [$locked, false];
                }

                throw ValidationException::withMessages(['decision' => 'This conversation has already been '.$locked->review_status.'.']);
            }

            $locked->update([
                'review_status' => $decision,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note !== null ? mb_substr(trim($note), 0, 500) : null,
            ]);

            if ($decision === SmmClientConversation::STATUS_APPROVED) {
                $this->points->award(
                    PerformancePointEvent::EVENT_POTENTIAL_CLIENT,
                    PerformancePointEvent::SOURCE_CONVERSATION, $locked->id,
                    $locked->submitter, $locked->brand_id, $reviewer,
                    'Potential Client',
                );
            }

            $this->activityLog->log('SMM Conversation', 'Reviewed', $locked->brand->client_id, null, [
                'conversation_id' => $locked->id, 'decision' => $decision,
            ]);

            return [$locked, true];
        });

        [$reviewed, $justDecided] = $changed;

        if ($justDecided) {
            // Only the submitter hears the verdict, and only once.
            $this->notifySafely(fn () => $reviewed->submitter->notify(
                new ConversationReviewed($reviewed->load('brand:id,name'), $reviewer),
            ));
        }

        return $reviewed;
    }

    /** A product must belong to the brand. Checked here, never trusted from the form. */
    private function validatedProductId(Brand $brand, ?int $productId): ?int
    {
        if ($productId === null) {
            return null;
        }

        $belongs = Product::where('id', $productId)->where('brand_id', $brand->id)->exists();
        if (! $belongs) {
            throw ValidationException::withMessages(['product_id' => 'That product does not belong to this brand.']);
        }

        return $productId;
    }

    private function looksDuplicated(Brand $brand, ?int $productId, string $reference, User $smm): bool
    {
        return SmmClientConversation::where('brand_id', $brand->id)
            ->where('submitted_by', $smm->id)
            ->where('reference', trim($reference))
            ->where('product_id', $productId)
            ->where('submitted_at', '>=', now()->subDays(self::DUPLICATE_WINDOW_DAYS))
            ->exists();
    }

    private function notifySafely(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
