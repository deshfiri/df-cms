<?php

namespace App\Jobs\WhatsApp;

use App\Events\WhatsApp\WhatsAppConversationUpdated;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\MetaWhatsAppClient;
use App\Services\WhatsApp\WhatsAppApiException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hands one already-persisted outgoing message to Meta.
 *
 * The message row exists before this runs, in `pending`. That ordering is what
 * makes the agent's UI honest: the message appears immediately and then earns
 * its ticks, and a queue outage shows a stuck message rather than losing one.
 *
 * The account, the number and the recipient are all read from the conversation
 * here — the job takes only a message id, so there is no parameter through which
 * a caller could redirect a send to a different brand's number.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** Widening gaps, because the usual retryable cause is a rate limit. */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly int $messageId,
    ) {
    }

    public function handle(MetaWhatsAppClient $client): void
    {
        $message = WhatsAppMessage::with('conversation.account', 'conversation.contact')->find($this->messageId);

        if (!$message || $message->status !== WhatsAppMessage::STATUS_PENDING) {
            return;   // deleted, or already sent by an earlier attempt
        }

        $conversation = $message->conversation;
        $account      = $conversation?->account;
        $contact      = $conversation?->contact;

        if (!$account || !$contact) {
            $this->fail($message, 'internal', 'This conversation is no longer complete.');

            return;
        }

        if (!$account->isUsable()) {
            $this->fail($message, 'account_unusable', $account->unusableReason() ?? 'This number cannot send.');

            return;
        }

        try {
            $wamid = $this->dispatchToMeta($client, $message, $account, $contact->wa_id);
        } catch (WhatsAppApiException $e) {
            // A permanent error will fail identically forever; retrying only
            // delays the agent finding out.
            if (!$e->isRetryable() || $this->attempts() >= $this->tries) {
                $this->fail($message, (string) $e->errorCode(), $e->userMessage());

                return;
            }

            $this->release($this->backoff[$this->attempts() - 1] ?? 300);

            return;
        } catch (Throwable $e) {
            report($e);
            $this->fail($message, 'unexpected', 'The message could not be sent. Please try again.');

            return;
        }

        $message->forceFill([
            'wamid'   => $wamid,
            'status'  => WhatsAppMessage::STATUS_SENT,
            'sent_at' => now(),
        ])->save();

        Log::info('whatsapp.message_sent', [
            'message_id'      => $message->id,
            'conversation_id' => $conversation->id,
            'type'            => $message->type,
        ]);

        $this->announce($message);
    }

    /** Route to the right client method for this message's type. */
    private function dispatchToMeta(
        MetaWhatsAppClient $client,
        WhatsAppMessage $message,
        $account,
        string $to,
    ): string {
        if ($message->type === 'template') {
            return $client->sendTemplate(
                $account,
                $to,
                (string) $message->template_name,
                (string) data_get($message->metadata, 'language', 'en_US'),
                (array) data_get($message->metadata, 'components', []),
            );
        }

        if ($message->hasMedia() || filled($message->media_id)) {
            return $client->sendMedia(
                $account,
                $to,
                $message->type,
                (string) $message->media_id,
                $message->body,
                $message->media_name,
            );
        }

        return $client->sendText($account, $to, (string) $message->body, $message->context_wamid);
    }

    private function fail(WhatsAppMessage $message, ?string $code, string $reason): void
    {
        $message->forceFill([
            'status'        => WhatsAppMessage::STATUS_FAILED,
            'error_code'    => $code,
            'error_message' => $reason,
            'failed_at'     => now(),
        ])->save();

        Log::warning('whatsapp.message_send_failed', [
            'message_id' => $message->id,
            'code'       => $code,
        ]);

        $this->announce($message);
    }

    private function announce(WhatsAppMessage $message): void
    {
        try {
            broadcast(new WhatsAppConversationUpdated($message->conversation, $message->fresh()));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
