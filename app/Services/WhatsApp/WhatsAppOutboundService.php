<?php

namespace App\Services\WhatsApp;

use App\Jobs\WhatsApp\SendWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything an agent sends.
 *
 * The method signatures are the security design: each takes a *conversation*, and
 * derives the account, phone number, brand and recipient from it. There is no
 * parameter for a phone number id, an account id or a brand — so no request shape
 * exists in which a client could send through a number it does not own (spec §53).
 *
 * Messages are persisted before they are queued, so the thread shows the message
 * immediately and a queue outage looks like a stuck message rather than a lost one.
 */
class WhatsAppOutboundService
{
    public function __construct(
        private readonly WhatsAppConversationService $conversations,
        private readonly MetaWhatsAppClient $client,
    ) {
    }

    /**
     * A free-form text reply.
     *
     * Only permitted inside Meta's customer service window; outside it the only
     * lawful message is an approved template, and saying so plainly beats
     * letting Meta reject it with an opaque code.
     */
    public function sendText(WhatsAppConversation $conversation, User $agent, string $body, ?string $replyToWamid = null): WhatsAppMessage
    {
        $this->assertWithinServiceWindow($conversation);

        $message = $this->persist($conversation, $agent, [
            'type'          => 'text',
            'body'          => $body,
            'context_wamid' => $replyToWamid,
        ]);

        SendWhatsAppMessage::dispatch($message->id);

        return $message;
    }

    /**
     * An attachment.
     *
     * Uploaded to Meta synchronously because the media id it returns is what the
     * queued send needs; the file itself is also kept on our own storage so the
     * thread can render it after Meta's copy expires.
     */
    public function sendMedia(
        WhatsAppConversation $conversation,
        User $agent,
        UploadedFile $file,
        ?string $caption = null,
    ): WhatsAppMessage {
        $this->assertWithinServiceWindow($conversation);

        $account = $conversation->account;

        if (!$account?->isUsable()) {
            throw ValidationException::withMessages([
                'file' => $account?->unusableReason() ?? 'This number cannot send attachments right now.',
            ]);
        }

        $mediaId = $this->client->uploadMedia($account, $file);

        $message = $this->persist($conversation, $agent, [
            'type'       => $this->typeFor($file->getMimeType() ?? ''),
            'body'       => $caption,
            'media_id'   => $mediaId,
            'media_name' => $file->getClientOriginalName(),
            'media_mime' => $file->getMimeType(),
            'media_size' => $file->getSize(),
        ]);

        SendWhatsAppMessage::dispatch($message->id);

        return $message;
    }

    /**
     * A template message — the only thing that may be sent outside the window.
     *
     * The template's approval is re-checked here rather than trusted from the
     * mirrored row, and the parameter count is validated locally so a mismatch
     * surfaces as a form error instead of a Meta rejection.
     *
     * @param  array<int,string>  $parameters  Body placeholder values, in order.
     */
    public function sendTemplate(
        WhatsAppConversation $conversation,
        User $agent,
        WhatsAppTemplate $template,
        array $parameters = [],
    ): WhatsAppMessage {
        if ($template->whatsapp_account_id !== $conversation->whatsapp_account_id) {
            // A template belongs to one number; using another's would send from
            // an account this conversation has nothing to do with.
            throw ValidationException::withMessages([
                'template' => 'That template belongs to a different WhatsApp number.',
            ]);
        }

        if (!$template->isApproved()) {
            throw ValidationException::withMessages([
                'template' => 'That template is not approved by WhatsApp, so it cannot be sent.',
            ]);
        }

        $expected = $template->bodyParameterCount();

        if (count($parameters) !== $expected) {
            throw ValidationException::withMessages([
                'template' => "This template needs {$expected} value(s); " . count($parameters) . ' given.',
            ]);
        }

        $components = $parameters ? [[
            'type'       => 'body',
            'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => $value], array_values($parameters)),
        ]] : [];

        $message = $this->persist($conversation, $agent, [
            'type'          => 'template',
            'template_name' => $template->name,
            'body'          => $this->renderTemplatePreview($template, $parameters),
            'metadata'      => ['language' => $template->language, 'components' => $components],
        ]);

        SendWhatsAppMessage::dispatch($message->id);

        return $message;
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Write the outgoing row and move the conversation on.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function persist(WhatsAppConversation $conversation, User $agent, array $attributes): WhatsAppMessage
    {
        return DB::transaction(function () use ($conversation, $agent, $attributes) {
            $message = WhatsAppMessage::create($attributes + [
                'whatsapp_conversation_id' => $conversation->id,
                'direction'                => WhatsAppMessage::DIRECTION_OUT,
                'status'                   => WhatsAppMessage::STATUS_PENDING,
                'sent_by_user_id'          => $agent->id,
            ]);

            $this->conversations->recordOutgoing($conversation, $message);

            return $message;
        });
    }

    private function assertWithinServiceWindow(WhatsAppConversation $conversation): void
    {
        if ($conversation->withinServiceWindow()) {
            return;
        }

        throw ValidationException::withMessages([
            'body' => 'This customer has not messaged in over '
                . WhatsAppConversation::SERVICE_WINDOW_HOURS
                . ' hours. WhatsApp only allows an approved template message now.',
        ]);
    }

    private function typeFor(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default                          => 'document',
        };
    }

    /**
     * What the agent will see in the thread for a template they just sent.
     *
     * Meta does not echo the rendered text back, so it is composed here from the
     * template body and the values supplied.
     *
     * @param  array<int,string>  $parameters
     */
    private function renderTemplatePreview(WhatsAppTemplate $template, array $parameters): string
    {
        foreach ($template->components ?? [] as $component) {
            if (strtoupper($component['type'] ?? '') !== 'BODY') {
                continue;
            }

            $text = (string) ($component['text'] ?? '');

            foreach (array_values($parameters) as $index => $value) {
                $text = str_replace('{{' . ($index + 1) . '}}', $value, $text);
            }

            return $text;
        }

        return $template->name;
    }
}
