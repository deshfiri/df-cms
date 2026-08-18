<?php

namespace App\Http\Controllers;

use App\Exceptions\CallException;
use App\Events\CallSignal;
use App\Models\Call;
use App\Models\User;
use App\Services\CallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Signalling endpoints for 1:1 audio calls.
 *
 * Every action re-derives the acting user from the session — a caller_id in
 * the request body is never trusted — and the service checks participation
 * before relaying anything.
 */
class CallController extends Controller
{
    public function __construct(
        private readonly CallService $calls,
    ) {}

    /** ICE servers for the browser, with a freshly minted TURN credential. */
    public function ice(Request $request): JsonResponse
    {
        return response()->json($this->calls->iceServers($request->user()));
    }

    public function start(Request $request, User $user): JsonResponse
    {
        try {
            $result = $this->calls->start($request->user(), $user);
        } catch (CallException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'success' => true,
            'glare'   => $result['glare'],
            'call'    => $this->resource($result['call']),
        ]);
    }

    public function accept(Request $request, Call $call): JsonResponse
    {
        return $this->run(fn () => $this->calls->accept($call, $request->user()));
    }

    public function reject(Request $request, Call $call): JsonResponse
    {
        return $this->run(fn () => $this->calls->reject($call, $request->user()));
    }

    public function end(Request $request, Call $call): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:100']]);

        return $this->run(fn () => $this->calls->end($call, $request->user(), $data['reason'] ?? null));
    }

    /**
     * Relay one SDP offer/answer or ICE candidate.
     *
     * Deliberately one endpoint rather than three: candidates arrive in bursts
     * and share identical authorization. The payload is passed through opaque —
     * the server has no business parsing SDP.
     */
    public function signal(Request $request, Call $call): JsonResponse
    {
        $data = $request->validate([
            'type'    => ['required', Rule::in([CallSignal::TYPE_OFFER, CallSignal::TYPE_ANSWER, CallSignal::TYPE_ICE])],
            'payload' => ['required', 'array'],
        ]);

        try {
            $this->calls->relaySignal($call, $request->user(), $data['type'], $data['payload']);
        } catch (CallException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->status);
        }

        return response()->json(['success' => true]);
    }

    /** Recent calls for the signed-in user, in both directions. */
    public function history(Request $request): JsonResponse
    {
        $me = $request->user()->id;

        $calls = Call::query()
            ->where(fn ($q) => $q->where('caller_id', $me)->orWhere('callee_id', $me))
            ->with(['caller:id,name', 'callee:id,name'])
            ->latest('started_at')
            ->limit(50)
            ->get()
            ->map(function (Call $call) use ($me) {
                $outgoing = $call->caller_id === $me;

                return $this->resource($call) + [
                    'direction'      => $outgoing ? 'outgoing' : 'incoming',
                    'other_user_id'  => $call->otherParticipantId($me),
                    'other_name'     => $outgoing
                        ? ($call->callee->name ?? '—')
                        : ($call->caller->name ?? '—'),
                    'outcome'        => $call->outcomeFor($me),
                    'missed'         => $call->wasMissedBy($me),
                    // Answered calls are the only ones a duration means anything for.
                    'duration'       => $call->answered_at ? $call->formattedDuration() : null,
                    'started_at'     => $call->started_at?->diffForHumans(),
                    'started_exact'  => $call->started_at?->format('d M Y, h:i A'),
                ];
            });

        return response()->json([
            'calls'         => $calls,
            'missed_unseen' => Call::query()->missedUnseenFor($me)->count(),
        ]);
    }

    /**
     * Acknowledge missed calls — called when the user opens their call log.
     * Only ever stamps rows where this user was the one who was rung.
     */
    public function markHistorySeen(Request $request): JsonResponse
    {
        Call::query()
            ->missedUnseenFor($request->user()->id)
            ->update(['callee_seen_at' => now()]);

        return response()->json(['success' => true, 'missed_unseen' => 0]);
    }

    private function run(callable $action): JsonResponse
    {
        try {
            $call = $action();
        } catch (CallException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->status);
        }

        return response()->json(['success' => true, 'call' => $this->resource($call)]);
    }

    private function resource(Call $call): array
    {
        return [
            'uuid'             => $call->uuid,
            'status'           => $call->status,
            'caller_id'        => $call->caller_id,
            'callee_id'        => $call->callee_id,
            'conversation_id'  => $call->conversation_id,
            'duration_seconds' => $call->duration_seconds,
        ];
    }
}
