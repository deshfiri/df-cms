<?php

namespace App\Jobs\WhatsApp;

use App\Models\WhatsAppWebhookEvent;
use App\Services\WhatsApp\WhatsAppInboundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns one stored webhook delivery into messages and status updates.
 *
 * Runs off the request so the endpoint can answer Meta immediately; Meta retries
 * anything slow, and a retry storm is far worse than a few seconds' delay in the
 * inbox.
 *
 * Takes the event id rather than the model so a retry always re-reads current
 * state instead of resurrecting a stale serialised copy.
 */
class ProcessWhatsAppWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly int $eventId,
    ) {
    }

    public function handle(WhatsAppInboundService $inbound): void
    {
        $event = WhatsAppWebhookEvent::find($this->eventId);

        if (!$event) {
            return;
        }

        // Already dealt with — a queue retry after a successful run must not
        // replay the payload.
        if ($event->status === WhatsAppWebhookEvent::STATUS_PROCESSED) {
            return;
        }

        try {
            $result = $inbound->process($event->payload ?? []);
        } catch (Throwable $e) {
            $event->markFailed($e->getMessage());

            Log::error('whatsapp.webhook_processing_failed', [
                'event_id' => $event->id,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($result['handled'] === 0) {
            $event->markIgnored($result['reason'] ?? 'Nothing to process.');

            return;
        }

        $event->markProcessed($result['type']);

        Log::info('whatsapp.webhook_processed', [
            'event_id' => $event->id,
            'type'     => $result['type'],
            'handled'  => $result['handled'],
        ]);
    }
}
