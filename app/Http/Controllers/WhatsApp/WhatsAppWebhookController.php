<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Jobs\WhatsApp\ProcessWhatsAppWebhookEvent;
use App\Models\WhatsAppWebhookEvent;
use App\Services\WhatsApp\WhatsAppSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Meta's webhook endpoint. The only unauthenticated route in the module.
 *
 * Its job is to be fast and to be idempotent, and nothing else. It records the
 * delivery and queues the work; every decision about what the payload means
 * happens in a job, off the request. Meta retries anything that does not answer
 * quickly, so parsing inline would turn a slow database into a storm of
 * duplicate deliveries.
 *
 * Authenticity is established by VerifyWhatsAppWebhookSignature on the route.
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppSettings $settings,
    ) {
    }

    /**
     * Subscription handshake.
     *
     * Meta calls this once when the webhook is registered and expects the
     * challenge echoed back verbatim, as plain text.
     */
    public function verify(Request $request): Response
    {
        $token = $this->settings->verifyToken();

        $modeOk = $request->query('hub_mode') === 'subscribe';
        $tokenOk = filled($token)
            && is_string($request->query('hub_verify_token'))
            && hash_equals($token, (string) $request->query('hub_verify_token'));

        if (!$modeOk || !$tokenOk) {
            Log::warning('whatsapp.webhook_verify_failed', ['mode' => $request->query('hub_mode')]);

            return response('Verification failed.', 403);
        }

        Log::info('whatsapp.webhook_verified');

        return response((string) $request->query('hub_challenge'), 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Receive a delivery.
     *
     * Always answers 200. Meta treats anything else as a failure and retries,
     * which for a payload we have already stored would be pure noise — the
     * queued job owns the outcome from here.
     */
    public function receive(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $hash = WhatsAppWebhookEvent::hashFor($raw);

        try {
            $event = WhatsAppWebhookEvent::create([
                'signature_hash' => $hash,
                'phone_number_id' => $this->phoneNumberIdFrom($request),
                'payload' => $request->all(),
                'status' => WhatsAppWebhookEvent::STATUS_RECEIVED,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A retry of something already accepted. The unique index is the
            // idempotency guarantee — it holds even with concurrent workers,
            // which an application-level "have I seen this?" check would not.
            Log::info('whatsapp.webhook_duplicate', ['hash' => substr($hash, 0, 12)]);

            return response()->json(['status' => 'duplicate']);
        }

        ProcessWhatsAppWebhookEvent::dispatch($event->id);

        return response()->json(['status' => 'queued']);
    }

    /**
     * Pull the phone number id out for indexing only.
     *
     * Never trusted for routing — the job re-reads it from the stored payload
     * and resolves it against our own accounts.
     */
    private function phoneNumberIdFrom(Request $request): ?string
    {
        $value = data_get($request->all(), 'entry.0.changes.0.value.metadata.phone_number_id');

        return is_string($value) ? $value : null;
    }
}
