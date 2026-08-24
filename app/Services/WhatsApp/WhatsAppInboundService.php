<?php

namespace App\Services\WhatsApp;

use App\Events\WhatsApp\WhatsAppConversationUpdated;
use App\Events\WhatsApp\WhatsAppMessageReceived;
use App\Jobs\WhatsApp\DownloadWhatsAppMedia;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Notifications\WhatsApp\WhatsAppMessageArrived;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a Meta webhook payload into rows.
 *
 * Every identifier in the payload is treated as a claim to be resolved against
 * our own data, never as an instruction. In particular the phone_number_id is
 * looked up among our accounts; a delivery naming a number we do not hold is
 * recorded and dropped, never attached to whichever brand happens to be first.
 */
class WhatsAppInboundService
{
    public function __construct(
        private readonly WhatsAppConversationService $conversations,
    ) {
    }

    /**
     * @param  array<string,mixed>  $payload  The whole webhook body.
     * @return array{type:string, handled:int, reason:?string}
     */
    public function process(array $payload): array
    {
        $handled = 0;
        $types   = [];

        foreach (data_get($payload, 'entry', []) as $entry) {
            foreach (data_get($entry, 'changes', []) as $change) {
                $value         = data_get($change, 'value', []);
                $phoneNumberId = data_get($value, 'metadata.phone_number_id');

                $account = $this->resolveAccount($phoneNumberId);

                if (!$account) {
                    // Spec §19: never assign an unknown number to a random brand.
                    Log::warning('whatsapp.unknown_phone_number_id', [
                        'phone_number_id' => $phoneNumberId,
                    ]);

                    return ['type' => 'unknown', 'handled' => 0, 'reason' => 'Unrecognised phone_number_id.'];
                }

                $account->forceFill(['last_webhook_at' => now()])->saveQuietly();

                foreach (data_get($value, 'messages', []) as $message) {
                    $this->storeIncoming($account, $message, data_get($value, 'contacts', []));
                    $handled++;
                    $types[] = 'message';
                }

                foreach (data_get($value, 'statuses', []) as $status) {
                    $this->applyStatus($status);
                    $handled++;
                    $types[] = 'status';
                }
            }
        }

        return [
            'type'    => $types ? (in_array('message', $types, true) ? 'message' : 'status') : 'unknown',
            'handled' => $handled,
            'reason'  => $handled === 0 ? 'No messages or statuses in payload.' : null,
        ];
    }

    // ── Incoming messages ────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $message
     * @param  array<int,array<string,mixed>>  $contacts  The `contacts` block, for the profile name.
     */
    private function storeIncoming(WhatsAppAccount $account, array $message, array $contacts): void
    {
        $wamid = data_get($message, 'id');
        $waId  = data_get($message, 'from');

        if (!$wamid || !$waId) {
            return;
        }

        $contact      = $this->resolveContact($waId, $contacts);
        $conversation = $this->conversations->findOrCreate($account, $contact);

        $type    = $this->normaliseType(data_get($message, 'type', 'unknown'));
        $sentAt  = $this->timestamp(data_get($message, 'timestamp'));

        try {
            $stored = DB::transaction(function () use ($conversation, $message, $wamid, $type, $sentAt) {
                $row = WhatsAppMessage::create([
                    'whatsapp_conversation_id' => $conversation->id,
                    'wamid'                    => $wamid,
                    'direction'                => WhatsAppMessage::DIRECTION_IN,
                    'type'                     => $type,
                    'body'                     => $this->bodyFor($type, $message),
                    'context_wamid'            => data_get($message, 'context.id'),
                    'media_id'                 => $this->mediaIdFor($type, $message),
                    'media_name'               => data_get($message, $type . '.filename'),
                    'media_mime'               => data_get($message, $type . '.mime_type'),
                    'status'                   => WhatsAppMessage::STATUS_RECEIVED,
                    'metadata'                 => $this->metadataFor($type, $message),
                    'sent_at'                  => $sentAt,
                ]);

                $this->conversations->recordIncoming($conversation, $row);

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            // The same message arriving twice. The unique index on wamid is what
            // makes duplicate webhook deliveries harmless (spec §42).
            Log::info('whatsapp.duplicate_message', ['wamid' => $wamid]);

            return;
        }

        // Media is fetched off the request: Meta's URLs expire within minutes,
        // but a slow download must not hold up the rest of the payload.
        if ($stored->media_id) {
            DownloadWhatsAppMedia::dispatch($stored->id);
        }

        $this->announce($conversation->fresh(['contact', 'brand', 'assignee']), $stored);
    }

    /**
     * Tell the people who need to know.
     *
     * Realtime first (the inbox may be open), then a bell notification for the
     * assigned agent. Both are best effort: a broadcast failure must never undo
     * a message that is already stored.
     */
    private function announce(WhatsAppConversation $conversation, WhatsAppMessage $message): void
    {
        try {
            broadcast(new WhatsAppMessageReceived($conversation, $message));
            broadcast(new WhatsAppConversationUpdated($conversation));
        } catch (Throwable $e) {
            report($e);
        }

        if ($conversation->assignee) {
            try {
                $conversation->assignee->notify(new WhatsAppMessageArrived($conversation, $message));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    // ── Status updates ───────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $status
     */
    private function applyStatus(array $status): void
    {
        $wamid = data_get($status, 'id');
        $state = data_get($status, 'status');

        if (!$wamid || !$state) {
            return;
        }

        // Matched on the provider id, never on text or timing (spec §23).
        $message = WhatsAppMessage::where('wamid', $wamid)->first();

        if (!$message) {
            return;
        }

        $state = strtolower($state);

        if (!$message->advancesTo($state)) {
            // Meta does not guarantee ordering; a late 'sent' must not undo 'read'.
            return;
        }

        $timestamp = $this->timestamp(data_get($status, 'timestamp')) ?? now();

        $attributes = ['status' => $state];

        match ($state) {
            WhatsAppMessage::STATUS_SENT      => $attributes['sent_at'] = $timestamp,
            WhatsAppMessage::STATUS_DELIVERED => $attributes['delivered_at'] = $timestamp,
            WhatsAppMessage::STATUS_READ      => $attributes['read_at'] = $timestamp,
            WhatsAppMessage::STATUS_FAILED    => $attributes = $attributes + [
                'failed_at'     => $timestamp,
                'error_code'    => (string) data_get($status, 'errors.0.code'),
                'error_message' => data_get($status, 'errors.0.title') ?? data_get($status, 'errors.0.message'),
            ],
            default => null,
        };

        $message->forceFill($attributes)->save();

        try {
            broadcast(new WhatsAppConversationUpdated($message->conversation, $message->fresh()));
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ── Resolution ───────────────────────────────────────────────────────

    /** Our account for this number, or nothing. Disabled accounts still receive. */
    private function resolveAccount(?string $phoneNumberId): ?WhatsAppAccount
    {
        if (!filled($phoneNumberId)) {
            return null;
        }

        return WhatsAppAccount::where('phone_number_id', $phoneNumberId)->first();
    }

    /**
     * @param  array<int,array<string,mixed>>  $contacts
     */
    private function resolveContact(string $waId, array $contacts): WhatsAppContact
    {
        $profileName = null;

        foreach ($contacts as $candidate) {
            if (data_get($candidate, 'wa_id') === $waId) {
                $profileName = data_get($candidate, 'profile.name');
                break;
            }
        }

        $contact = WhatsAppContact::firstOrCreate(
            ['wa_id' => $waId],
            ['phone' => WhatsAppContact::normalisePhone($waId), 'profile_name' => $profileName],
        );

        // Their profile name may change; ours, if set, is never overwritten by it.
        if ($profileName && $contact->profile_name !== $profileName) {
            $contact->forceFill(['profile_name' => $profileName])->save();
        }

        return $contact;
    }

    // ── Payload shaping ──────────────────────────────────────────────────

    private function normaliseType(string $type): string
    {
        return in_array($type, WhatsAppMessage::TYPES, true) ? $type : 'unknown';
    }

    /** @param array<string,mixed> $message */
    private function bodyFor(string $type, array $message): ?string
    {
        return match ($type) {
            'text'     => data_get($message, 'text.body'),
            'image', 'video', 'document' => data_get($message, $type . '.caption'),
            'location' => trim((string) data_get($message, 'location.name') . ' ' . (string) data_get($message, 'location.address')) ?: null,
            'reaction' => data_get($message, 'reaction.emoji'),
            'button'   => data_get($message, 'button.text'),
            'interactive' => data_get($message, 'interactive.button_reply.title')
                ?? data_get($message, 'interactive.list_reply.title'),
            default    => null,
        };
    }

    /** @param array<string,mixed> $message */
    private function mediaIdFor(string $type, array $message): ?string
    {
        return in_array($type, ['image', 'video', 'audio', 'document', 'sticker'], true)
            ? data_get($message, $type . '.id')
            : null;
    }

    /**
     * Keep the parts of the envelope that have no column, and nothing else.
     *
     * Storing the whole raw payload per message would duplicate the webhook
     * event table and bloat the hottest table in the module.
     *
     * @param  array<string,mixed>  $message
     * @return array<string,mixed>|null
     */
    private function metadataFor(string $type, array $message): ?array
    {
        $metadata = match ($type) {
            'location'    => array_filter([
                'latitude'  => data_get($message, 'location.latitude'),
                'longitude' => data_get($message, 'location.longitude'),
            ], fn ($v) => $v !== null),
            'reaction'    => array_filter(['reacted_to' => data_get($message, 'reaction.message_id')]),
            'interactive' => (array) data_get($message, 'interactive', []),
            'contacts'    => ['contacts' => data_get($message, 'contacts', [])],
            'unknown'     => ['raw_type' => data_get($message, 'type'), 'errors' => data_get($message, 'errors')],
            default       => [],
        };

        return $metadata ?: null;
    }

    private function timestamp(mixed $value): ?Carbon
    {
        return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : null;
    }

    /** Trimmed for the conversation list column. */
    public static function preview(WhatsAppMessage $message): string
    {
        return Str::limit($message->previewLine(), 200);
    }
}
