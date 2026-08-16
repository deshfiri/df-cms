<?php

namespace App\Services;

use App\Events\CallRinging;
use App\Events\CallSignal;
use App\Events\CallStatusChanged;
use App\Exceptions\CallException;
use App\Models\Call;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Call lifecycle and signalling relay.
 *
 * The server owns call *state*; it never touches call *media*. Audio flows
 * browser-to-browser over WebRTC (via TURN when NAT requires a relay) and
 * never passes through Laravel, Reverb, the database or the queue. SDP and ICE
 * blobs are forwarded verbatim to the other participant and immediately
 * forgotten.
 */
class CallService
{
    /** Start a call, or discover that the callee is already calling us. */
    public function start(User $caller, User $callee): array
    {
        if ($caller->id === $callee->id) {
            throw new CallException('You cannot call yourself.');
        }

        if (!$callee->is_active) {
            throw new CallException('That user account is not active.');
        }

        // The decision happens under a lock; the consequences are applied after
        // it commits. Creating the "busy" audit row inside the transaction and
        // then throwing would roll that row straight back, and broadcasting
        // inside it can ring a phone for a call that never commits.
        $outcome = DB::transaction(function () use ($caller, $callee) {
            // Lock every live call involving either party before deciding.
            // Without the lock two simultaneous attempts both read "nobody is
            // busy" and both succeed, leaving one person in two calls.
            $active = Call::query()
                ->whereIn('status', Call::ACTIVE_STATUSES)
                ->where(function ($q) use ($caller, $callee) {
                    $q->whereIn('caller_id', [$caller->id, $callee->id])
                        ->orWhereIn('callee_id', [$caller->id, $callee->id]);
                })
                ->lockForUpdate()
                ->get();

            // Glare: they rang us while we were ringing them. Rather than both
            // sides failing, the later attempt is converted into "answer the
            // call you already have" — deterministic, and it matches what a
            // user means when they both tap call at once.
            $reverse = $active->first(fn (Call $c) => $c->isRinging()
                && $c->caller_id === $callee->id
                && $c->callee_id === $caller->id);

            if ($reverse) {
                return ['type' => 'glare', 'call' => $reverse];
            }

            if ($active->contains(fn (Call $c) => $c->hasParticipant($caller->id))) {
                return ['type' => 'caller_busy', 'call' => null];
            }

            if ($active->contains(fn (Call $c) => $c->hasParticipant($callee->id))) {
                return ['type' => 'callee_busy', 'call' => null];
            }

            return ['type' => 'created', 'call' => $this->create($caller, $callee, Call::STATUS_RINGING)];
        });

        if ($outcome['type'] === 'glare') {
            Log::info('call.glare', ['call' => $outcome['call']->uuid, 'caller' => $caller->id, 'callee' => $callee->id]);

            return ['call' => $outcome['call'], 'glare' => true];
        }

        if ($outcome['type'] === 'caller_busy') {
            throw new CallException('You are already on a call.', 409);
        }

        if ($outcome['type'] === 'callee_busy') {
            // Recorded so "busy" shows up in history rather than vanishing.
            $busy = $this->create($caller, $callee, Call::STATUS_BUSY);
            $busy->forceFill(['ended_at' => now(), 'failure_reason' => 'callee_busy'])->save();

            Log::info('call.busy', ['call' => $busy->uuid, 'caller' => $caller->id, 'callee' => $callee->id]);

            throw new CallException('That user is already on another call.', 409);
        }

        $call = $outcome['call'];
        $call->setRelation('caller', $caller);
        broadcast(new CallRinging($call));

        Log::info('call.ringing', ['call' => $call->uuid, 'caller' => $caller->id, 'callee' => $callee->id]);

        return ['call' => $call, 'glare' => false];
    }

    /** Callee picks up. Idempotent — a double-tap must not re-broadcast. */
    public function accept(Call $call, User $user): Call
    {
        if ($call->callee_id !== $user->id) {
            throw new CallException('Only the person being called can accept.', 403);
        }

        return DB::transaction(function () use ($call, $user) {
            $call = Call::whereKey($call->id)->lockForUpdate()->firstOrFail();

            if ($call->status === Call::STATUS_ACCEPTED) {
                return $call;
            }

            if (!$call->isRinging()) {
                throw new CallException('This call is no longer ringing.', 409);
            }

            $call->update(['status' => Call::STATUS_ACCEPTED, 'answered_at' => now()]);

            broadcast(new CallStatusChanged($call, $call->caller_id));
            Log::info('call.accepted', ['call' => $call->uuid, 'by' => $user->id]);

            return $call;
        });
    }

    /** Callee declines a ringing call. */
    public function reject(Call $call, User $user): Call
    {
        if ($call->callee_id !== $user->id) {
            throw new CallException('Only the person being called can reject.', 403);
        }

        return $this->terminate($call, $user, Call::STATUS_REJECTED);
    }

    /**
     * Hang up. The resulting status depends on where the call had got to:
     * a caller abandoning a ringing call cancelled it, anything after pickup
     * simply ended.
     */
    public function end(Call $call, User $user, ?string $reason = null): Call
    {
        if (!$call->hasParticipant($user->id)) {
            throw new CallException('You are not part of this call.', 403);
        }

        $status = match (true) {
            $call->status === Call::STATUS_ACCEPTED => Call::STATUS_ENDED,
            $call->isRinging() && $call->caller_id === $user->id => Call::STATUS_CANCELLED,
            $call->isRinging() => Call::STATUS_REJECTED,
            default => Call::STATUS_ENDED,
        };

        return $this->terminate($call, $user, $status, $reason);
    }

    /**
     * Forward one SDP/ICE payload to the other participant.
     *
     * Everything here is a guard: only a participant may send, only into a live
     * call, and only ever to the other side. Signalling for a finished call is
     * dropped rather than relayed, which is what stops a stale tab from
     * injecting candidates into a peer connection that has already moved on.
     */
    public function relaySignal(Call $call, User $user, string $type, array $payload): void
    {
        if (!$call->hasParticipant($user->id)) {
            throw new CallException('You are not part of this call.', 403);
        }

        if (!$call->isActive()) {
            throw new CallException('This call has already finished.', 409);
        }

        broadcast(new CallSignal($call, $call->otherParticipantId($user->id), $type, $payload));
    }

    /**
     * ICE configuration for the browser.
     *
     * With coturn's shared-secret mode the credential is minted here and valid
     * for minutes, so the browser never holds a reusable secret. The static
     * username/password path is a documented fallback only.
     */
    public function iceServers(User $user): array
    {
        $servers = [];

        if ($stun = config('webrtc.stun_url')) {
            $servers[] = ['urls' => $stun];
        }

        $turnUrls = config('webrtc.turn_urls') ?: array_filter([config('webrtc.turn_url')]);

        if ($turnUrls) {
            if ($secret = config('webrtc.turn_secret')) {
                // coturn REST API format: username is "<expiry>:<identifier>",
                // credential is base64(HMAC-SHA1(secret, username)).
                $expiry   = time() + max(60, (int) config('webrtc.turn_ttl'));
                $username = $expiry . ':' . $user->id;

                $servers[] = [
                    'urls'       => array_values($turnUrls),
                    'username'   => $username,
                    'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)),
                ];
            } elseif (config('webrtc.turn_username')) {
                $servers[] = [
                    'urls'       => array_values($turnUrls),
                    'username'   => config('webrtc.turn_username'),
                    'credential' => config('webrtc.turn_credential'),
                ];
            }
        }

        return [
            'iceServers'         => $servers,
            'iceTransportPolicy' => config('webrtc.force_relay') ? 'relay' : 'all',
            'ringTimeout'        => (int) config('webrtc.ring_timeout'),
            // Without a relay, calls only work when the two peers can reach each
            // other directly. The client uses this to explain a failure instead
            // of just saying "call failed".
            'hasTurn'            => (bool) $turnUrls,
        ];
    }

    /**
     * Backend authority for abandoned calls. A browser timer cannot be trusted
     * to mark a call missed — the tab may be closed, asleep or offline — so a
     * scheduled sweep is what actually settles these rows.
     *
     * @return array{missed:int, stale:int}
     */
    public function reconcileStaleCalls(): array
    {
        $ringTimeout = max(5, (int) config('webrtc.ring_timeout'));
        $maxDuration = max(60, (int) config('webrtc.max_duration'));

        $missed = 0;
        Call::where('status', Call::STATUS_RINGING)
            ->where('started_at', '<=', now()->subSeconds($ringTimeout))
            ->get()
            ->each(function (Call $call) use (&$missed) {
                $this->terminate($call, null, Call::STATUS_MISSED, 'ring_timeout');
                $missed++;
            });

        $stale = 0;
        Call::where('status', Call::STATUS_ACCEPTED)
            ->where('answered_at', '<=', now()->subSeconds($maxDuration))
            ->get()
            ->each(function (Call $call) use (&$stale) {
                $this->terminate($call, null, Call::STATUS_ENDED, 'max_duration');
                $stale++;
            });

        return ['missed' => $missed, 'stale' => $stale];
    }

    /**
     * Settle a call once and tell whoever wasn't responsible.
     *
     * Re-reads under a lock so two hang-ups racing each other (both parties
     * tapping end, or a tab-close beacon arriving alongside a click) produce
     * one transition and one broadcast, not two.
     */
    private function terminate(Call $call, ?User $actor, string $status, ?string $reason = null): Call
    {
        return DB::transaction(function () use ($call, $actor, $status, $reason) {
            $call = Call::whereKey($call->id)->lockForUpdate()->firstOrFail();

            if (!$call->isActive()) {
                return $call;
            }

            $endedAt = now();
            // Direction matters: Carbon 3 returns a signed difference, so this
            // must read answered -> ended or every duration lands on zero.
            $duration = $call->answered_at
                ? max(0, (int) $call->answered_at->diffInSeconds($endedAt))
                : null;

            $call->update([
                'status'           => $status,
                'ended_at'         => $endedAt,
                'ended_by'         => $actor?->id,
                'duration_seconds' => $duration,
                'failure_reason'   => $reason,
            ]);

            // Tell the other side. When the sweeper settles a call nobody is
            // holding, both participants need to hear about it.
            $recipients = $actor
                ? [$call->otherParticipantId($actor->id)]
                : [$call->caller_id, $call->callee_id];

            foreach ($recipients as $recipientId) {
                broadcast(new CallStatusChanged($call, $recipientId));
            }

            Log::info('call.' . $status, [
                'call'     => $call->uuid,
                'by'       => $actor?->id,
                'duration' => $duration,
                'reason'   => $reason,
            ]);

            return $call;
        });
    }

    private function create(User $caller, User $callee, string $status): Call
    {
        return Call::create([
            'uuid'            => (string) Str::uuid(),
            'conversation_id' => Conversation::between($caller->id, $callee->id)->id,
            'caller_id'       => $caller->id,
            'callee_id'       => $callee->id,
            'status'          => $status,
            'started_at'      => now(),
        ]);
    }
}
