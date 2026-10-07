<?php

namespace App\Services\Workflow;

use App\Models\ContentItem;
use App\Models\ContentItemRevision;
use App\Models\ContentItemStageOwner;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who is responsible for each workflow stage of an exact version.
 *
 * Roles and panels can hold several users, so a stage is never owned by a role.
 * It is owned by one user, in one of two ways:
 *
 *   CLAIM   The stage is unassigned, so an eligible user takes it. The first
 *           successful claim wins. Two claims at once cannot both succeed.
 *   ASSIGN  The sender of the work names a specific eligible user. That user owns
 *           the stage at once and needs no claim.
 *
 * Ownership is enforced server-side by requireOwner(), which every
 * ownership-sensitive action calls before it does anything. Reassignment is a
 * Manager or Super Admin override. It releases the current owner and records the
 * change. Nothing is overwritten.
 *
 * Eligibility for a stage is "holds the destination role AND the stage's
 * permission", both checked against the user's real account. See RULES.
 */
final class StageOwnershipService
{
    /**
     * Destination role and permission for each stage. The maker stage depends on
     * the category, so it is resolved in rule().
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const RULES = [
        ContentItemStageOwner::STAGE_PRE_PUBLISH => ['Marketing', 'manage publishing-review'],
        ContentItemStageOwner::STAGE_PUBLISH => ['Social Media Manager', 'manage smm-collection'],
        ContentItemStageOwner::STAGE_FINAL_REVIEW => ['Marketing', 'manage publishing-review'],
    ];

    /** Roles allowed to reassign a stage that someone else owns. */
    private const REASSIGN_ROLES = ['Manager', 'Super Admin'];

    public function __construct(private readonly ActivityLogService $activityLog) {}

    // ── Stage references ──────────────────────────────────────────────────────

    public static function pre_publish(int $submissionId): string
    {
        return ContentItemStageOwner::STAGE_PRE_PUBLISH.':'.$submissionId;
    }

    public static function publish(int $submissionId): string
    {
        return ContentItemStageOwner::STAGE_PUBLISH.':'.$submissionId;
    }

    public static function finalReview(int $publicationId): string
    {
        return ContentItemStageOwner::STAGE_FINAL_REVIEW.':'.$publicationId;
    }

    public static function revision(int $revisionId): string
    {
        return 'revision:'.$revisionId;
    }

    /**
     * The maker's stage. While an item is in its first cycle, the maker is its
     * creator. Once a revision has been requested, the maker is the owner of
     * that revision.
     *
     * @return array{0: string, 1: array<string, int>} [stage_ref, links]
     */
    public function makerStage(ContentItem $item): array
    {
        if ($item->status === ContentItem::STATUS_NEEDS_REVISION) {
            $revision = ContentItemRevision::where('content_item_id', $item->id)->latest('id')->first();
            if ($revision) {
                return ['revision:'.$revision->id, ['revision_id' => $revision->id]];
            }
        }

        return ['item:'.$item->id, []];
    }

    // ── Eligibility ───────────────────────────────────────────────────────────

    public function eligibleFor(string $stage, User $user, ?string $category = null): bool
    {
        [$role, $permission] = $this->rule($stage, $category);

        return (bool) $user->is_active && $user->hasRole($role) && $user->can($permission);
    }

    /** @return array{0: string, 1: string} */
    private function rule(string $stage, ?string $category): array
    {
        if ($stage === 'maker') {
            return $category === ContentItem::CATEGORY_POSTER
                ? ['Design', 'manage designer-content']
                : ['Content', 'manage raw-content'];
        }

        return self::RULES[$stage] ?? throw new \InvalidArgumentException("Unknown workflow stage [{$stage}].");
    }

    // ── Reads ─────────────────────────────────────────────────────────────────

    public function activeOwner(string $stageRef): ?ContentItemStageOwner
    {
        return ContentItemStageOwner::with('user:id,name')->where('active_ref', $stageRef)->first();
    }

    /**
     * Enforces ownership before an action. An owned stage must belong to the
     * actor. An unowned stage must be claimed first. An item's first-cycle maker
     * stage is owned by its creator, and that is derived from created_by rather
     * than stored, so existing items keep working without a backfill.
     *
     * @param  array<string, int>  $links  Unused for the check; kept so callers pass the same context they use to claim.
     */
    public function requireOwner(string $stage, string $stageRef, ContentItem $item, User $actor, array $links = []): void
    {
        $owner = $this->activeOwner($stageRef);

        if ($owner !== null) {
            if ((int) $owner->user_id !== (int) $actor->id) {
                throw ValidationException::withMessages(['stage' => 'This work belongs to '.$owner->user->name.'. Only the owner can act on it.']);
            }

            return;
        }

        if (str_starts_with($stageRef, 'item:')) {
            if ((int) $item->created_by === (int) $actor->id) {
                return;
            }

            throw ValidationException::withMessages(['stage' => 'This work belongs to its creator.']);
        }

        throw ValidationException::withMessages(['stage' => 'Claim this work before acting on it.']);
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    /** Claim an unowned stage. Two simultaneous claims produce exactly one owner. */
    public function claim(string $stage, string $stageRef, ContentItem $item, User $actor, array $links = []): ContentItemStageOwner
    {
        if (! $this->eligibleFor($stage, $actor, $item->category)) {
            throw ValidationException::withMessages(['stage' => 'You are not eligible to claim this stage.']);
        }

        // Claiming a stage you already own is a no-op, so a double-click or a retry
        // returns the same owner. Someone else's claim is still refused, below.
        $existing = $this->activeOwner($stageRef);
        if ($existing && (int) $existing->user_id === (int) $actor->id) {
            return $existing;
        }

        return $this->take($stage, $stageRef, $item, $actor, ContentItemStageOwner::SOURCE_CLAIMED, null, $links, 'Claimed');
    }

    /**
     * The sender of work names the destination user. That user owns the stage at
     * once and needs no claim. The caller has already checked the sender may send.
     */
    public function assign(string $stage, string $stageRef, ContentItem $item, User $target, User $sender, array $links = []): ContentItemStageOwner
    {
        if (! $this->eligibleFor($stage, $target, $item->category)) {
            throw ValidationException::withMessages(['assigned_to' => 'That user is not eligible for this stage.']);
        }

        return $this->take($stage, $stageRef, $item, $target, ContentItemStageOwner::SOURCE_ASSIGNED, $sender, $links, 'Assigned');
    }

    /**
     * Manager or Super Admin override. Releases the current owner, if any, and
     * records the change. The released row stays, so history is preserved.
     */
    public function reassign(string $stage, string $stageRef, ContentItem $item, User $target, User $manager, string $reason, array $links = []): ContentItemStageOwner
    {
        if (! $manager->hasAnyRole(self::REASSIGN_ROLES)) {
            throw new AuthorizationException('Only a Manager or Super Admin can reassign work.');
        }

        if (! $this->eligibleFor($stage, $target, $item->category)) {
            throw ValidationException::withMessages(['assigned_to' => 'That user is not eligible for this stage.']);
        }

        $this->refuseIfOnHold($item);

        return DB::transaction(function () use ($stage, $stageRef, $item, $target, $manager, $reason, $links) {
            ContentItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            $current = $this->activeOwner($stageRef);
            $previousUserId = $current?->user_id;

            if ($current) {
                $current->update([
                    'active_ref' => null,
                    'released_at' => now(),
                    'released_by' => $manager->id,
                    'release_reason' => mb_substr($reason, 0, 255),
                ]);
            }

            $owner = $this->create($stage, $stageRef, $item, $target, ContentItemStageOwner::SOURCE_REASSIGNED, $manager, $links);

            $this->activityLog->log('Stage Ownership', 'Reassigned', $item->brand->client_id, ['user_id' => $previousUserId], [
                'content_item_id' => $item->id, 'stage' => $stage, 'stage_ref' => $stageRef,
                'user_id' => $target->id, 'reason' => $reason,
            ]);

            return $owner;
        });
    }

    /**
     * Used where an action takes the stage as part of itself. SMM's collect is the
     * SMM publish claim: an unowned stage is claimed by the collector, and an
     * owned one must already belong to the collector.
     */
    public function ensureOwnerOrClaim(string $stage, string $stageRef, ContentItem $item, User $actor, array $links = []): void
    {
        if ($this->activeOwner($stageRef) !== null) {
            $this->requireOwner($stage, $stageRef, $item, $actor, $links);

            return;
        }

        $this->claim($stage, $stageRef, $item, $actor, $links);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function take(string $stage, string $stageRef, ContentItem $item, User $owner, string $source, ?User $assignedBy, array $links, string $auditAction): ContentItemStageOwner
    {
        $this->refuseIfOnHold($item);

        return DB::transaction(function () use ($stage, $stageRef, $item, $owner, $source, $assignedBy, $links, $auditAction) {
            // Serialises every ownership change on this item. The unique active_ref
            // is the backstop if this lock is ever bypassed.
            ContentItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($this->activeOwner($stageRef) !== null || (str_starts_with($stageRef, 'item:') && $item->created_by)) {
                throw ValidationException::withMessages(['stage' => 'Someone already owns this stage.']);
            }

            $owner = $this->create($stage, $stageRef, $item, $owner, $source, $assignedBy, $links);

            $this->activityLog->log('Stage Ownership', $auditAction, $item->brand->client_id, null, [
                'content_item_id' => $item->id, 'stage' => $stage, 'stage_ref' => $stageRef,
                'user_id' => $owner->user_id, 'source' => $source, 'assigned_by' => $assignedBy?->id,
            ]);

            return $owner;
        });
    }

    private function create(string $stage, string $stageRef, ContentItem $item, User $owner, string $source, ?User $assignedBy, array $links): ContentItemStageOwner
    {
        try {
            return ContentItemStageOwner::create([
                'content_item_id' => $item->id,
                'stage' => $stage,
                'stage_ref' => $stageRef,
                'active_ref' => $stageRef,
                'submission_id' => $links['submission_id'] ?? null,
                'publication_id' => $links['publication_id'] ?? null,
                'revision_id' => $links['revision_id'] ?? null,
                'user_id' => $owner->id,
                'source' => $source,
                'assigned_by' => $assignedBy?->id,
                'acquired_at' => now(),
            ]);
        } catch (QueryException $e) {
            // The unique active_ref refused a second live owner: a concurrent
            // claim or assignment won the race.
            throw ValidationException::withMessages(['stage' => 'Someone already owns this stage.']);
        }
    }

    private function refuseIfOnHold(ContentItem $item): void
    {
        if ($item->checklist?->isOnHold()) {
            throw ValidationException::withMessages([
                'checklist' => $item->checklist->on_hold_reason ?: 'This checklist is on hold — new work is paused until a Manager clears it.',
            ]);
        }
    }
}
