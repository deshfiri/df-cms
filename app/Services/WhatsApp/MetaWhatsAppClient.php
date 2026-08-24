<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Every HTTP call this application makes to Meta's WhatsApp Cloud API.
 *
 * Nothing else may talk to Meta directly. Keeping it in one class is what makes
 * two guarantees checkable rather than hoped for: no access token is ever logged,
 * and no caller can choose which phone number a message leaves from — the number
 * always comes from the WhatsAppAccount passed in, never from request input.
 *
 * Errors become WhatsAppApiException with the credential stripped out.
 */
class MetaWhatsAppClient
{
    private const BASE = 'https://graph.facebook.com';

    /**
     * Meta error codes that are worth trying again.
     *
     * 4 and 80007 are rate limits, 131_026 is "recipient unavailable", 500-ish
     * are transient. Everything else — bad token, invalid number, template not
     * approved — will fail identically forever, and retrying only delays the
     * failure reaching the agent.
     */
    private const RETRYABLE_CODES = [4, 80007, 130429, 131026, 131_048, 133_016, 1, 2];

    public function __construct(
        private readonly WhatsAppSettings $settings,
    ) {
    }

    // ── Onboarding (Embedded Signup) ─────────────────────────────────────

    /**
     * Step 2 of Embedded Signup: turn the popup's authorization code into a
     * business access token.
     *
     * The app secret is required here, which is precisely why this exchange
     * happens on the server and the code — not the token — is what the browser
     * is allowed to hold.
     */
    public function exchangeCode(string $code): array
    {
        $response = $this->request()->get($this->url('oauth/access_token'), [
            'client_id'     => $this->settings->appId(),
            'client_secret' => $this->settings->appSecret(),
            'code'          => $code,
        ]);

        $this->throwUnlessOk($response, 'Could not complete the WhatsApp connection with Meta.');

        return $response->json();
    }

    /**
     * Subscribe our app to a WhatsApp Business Account's webhooks.
     *
     * Without this Meta accepts the connection but never delivers a message, so
     * onboarding treats a failure here as a failed connection rather than a
     * warning.
     */
    public function subscribeApp(string $wabaId, string $token): void
    {
        $response = $this->request($token)->post($this->url("{$wabaId}/subscribed_apps"));

        $this->throwUnlessOk($response, 'Meta accepted the account but would not enable message delivery for it.');
    }

    /**
     * Register the phone number for Cloud API messaging.
     *
     * The PIN is two-factor for the number itself. A number already registered
     * returns an error that is safe to ignore, which is why this reports rather
     * than throws on that specific case.
     */
    public function registerPhoneNumber(string $phoneNumberId, string $pin, string $token): bool
    {
        $response = $this->request($token)->post($this->url("{$phoneNumberId}/register"), [
            'messaging_product' => 'whatsapp',
            'pin'               => $pin,
        ]);

        if ($response->successful()) {
            return true;
        }

        // "Phone number already registered" — the desired end state either way.
        if ((int) $response->json('error.code') === 133_005 || (int) $response->json('error.code') === 133_006) {
            return true;
        }

        $this->throwUnlessOk($response, 'Meta would not register this number for messaging.');

        return false;
    }

    /** Display number, verified name and quality rating for one phone number. */
    public function phoneNumberDetails(string $phoneNumberId, string $token): array
    {
        $response = $this->request($token)->get($this->url($phoneNumberId), [
            'fields' => 'display_phone_number,verified_name,quality_rating,code_verification_status,platform_type',
        ]);

        $this->throwUnlessOk($response, 'Could not read this number from Meta.');

        return $response->json();
    }

    /** Every phone number attached to a WhatsApp Business Account. */
    public function wabaPhoneNumbers(string $wabaId, string $token): array
    {
        $response = $this->request($token)->get($this->url("{$wabaId}/phone_numbers"), [
            'fields' => 'id,display_phone_number,verified_name,quality_rating',
        ]);

        $this->throwUnlessOk($response, 'Could not list the numbers on this WhatsApp Business Account.');

        return $response->json('data') ?? [];
    }

    /**
     * Prove an account still works.
     *
     * Used before marking a number connected, and by the "Reconnect" action —
     * spec §16: never mark connected merely because credentials were saved.
     */
    public function validateAccount(WhatsAppAccount $account): array
    {
        return $this->phoneNumberDetails($account->phone_number_id, $this->tokenFor($account));
    }

    // ── Sending ──────────────────────────────────────────────────────────

    /**
     * @param  string  $to  The recipient's wa_id.
     * @return string  The provider message id (wamid).
     */
    public function sendText(WhatsAppAccount $account, string $to, string $body, ?string $replyToWamid = null): string
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['preview_url' => true, 'body' => $body],
        ];

        if ($replyToWamid) {
            $payload['context'] = ['message_id' => $replyToWamid];
        }

        return $this->dispatchMessage($account, $payload);
    }

    /**
     * @param  string  $type  image|video|audio|document|sticker
     * @param  string  $mediaId  An id from {@see uploadMedia()} — never a URL.
     */
    public function sendMedia(
        WhatsAppAccount $account,
        string $to,
        string $type,
        string $mediaId,
        ?string $caption = null,
        ?string $filename = null,
    ): string {
        $media = ['id' => $mediaId];

        // Only documents and images carry a caption; sending one on audio is an
        // error rather than a no-op.
        if ($caption !== null && in_array($type, ['image', 'video', 'document'], true)) {
            $media['caption'] = $caption;
        }

        if ($filename !== null && $type === 'document') {
            $media['filename'] = $filename;
        }

        return $this->dispatchMessage($account, [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => $type,
            $type               => $media,
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $components  Meta's component array.
     */
    public function sendTemplate(
        WhatsAppAccount $account,
        string $to,
        string $templateName,
        string $language,
        array $components = [],
    ): string {
        $template = [
            'name'     => $templateName,
            'language' => ['code' => $language],
        ];

        if ($components) {
            $template['components'] = $components;
        }

        return $this->dispatchMessage($account, [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => $template,
        ]);
    }

    /** Tell Meta the agent has seen an incoming message, so the customer sees ticks. */
    public function markRead(WhatsAppAccount $account, string $wamid): void
    {
        $response = $this->request($this->tokenFor($account))->post(
            $this->url("{$account->phone_number_id}/messages"),
            [
                'messaging_product' => 'whatsapp',
                'status'            => 'read',
                'message_id'        => $wamid,
            ],
        );

        // Best effort: a read receipt that fails must never break opening a thread.
        if ($response->failed()) {
            Log::info('whatsapp.read_receipt_failed', [
                'account_id' => $account->id,
                'error'      => $response->json('error.message'),
            ]);
        }
    }

    // ── Media ────────────────────────────────────────────────────────────

    /** Upload a file to Meta and get the media id to send it with. */
    public function uploadMedia(WhatsAppAccount $account, UploadedFile $file): string
    {
        $response = $this->request($this->tokenFor($account))
            ->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
            ->post($this->url("{$account->phone_number_id}/media"), [
                'messaging_product' => 'whatsapp',
                'type'              => $file->getMimeType(),
            ]);

        $this->throwUnlessOk($response, 'WhatsApp would not accept that attachment.');

        $id = $response->json('id');

        if (!$id) {
            throw new WhatsAppApiException(
                'Media upload returned no id',
                'WhatsApp accepted the file but did not return a reference for it.',
            );
        }

        return $id;
    }

    /**
     * Resolve a media id to its temporary download URL.
     *
     * These expire within minutes, which is exactly why the contents are pulled
     * down and stored locally at receive time rather than linked to.
     */
    public function mediaUrl(WhatsAppAccount $account, string $mediaId): array
    {
        $response = $this->request($this->tokenFor($account))->get($this->url($mediaId));

        $this->throwUnlessOk($response, 'Could not locate that attachment on WhatsApp.');

        return $response->json();
    }

    /**
     * Download media bytes.
     *
     * Meta's CDN still requires the bearer token, so this cannot be a plain
     * file_get_contents.
     *
     * @return string raw bytes
     */
    public function downloadMedia(WhatsAppAccount $account, string $url): string
    {
        $response = $this->request($this->tokenFor($account))->timeout(120)->get($url);

        $this->throwUnlessOk($response, 'Could not download that attachment from WhatsApp.');

        return $response->body();
    }

    // ── Templates ────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function templates(WhatsAppAccount $account): array
    {
        $token = $this->tokenFor($account);
        $all   = [];
        $after = null;

        do {
            $response = $this->request($token)->get($this->url("{$account->waba_id}/message_templates"), array_filter([
                'fields' => 'id,name,language,category,status,components',
                'limit'  => 200,
                'after'  => $after,
            ]));

            $this->throwUnlessOk($response, 'Could not read the templates for this number.');

            $all   = array_merge($all, $response->json('data') ?? []);
            $after = $response->json('paging.cursors.after');

            // Meta returns a cursor even on the last page; stop when it stops
            // returning rows rather than trusting the cursor alone.
        } while ($after && ($response->json('data') ?? []) !== []);

        return $all;
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Post a message envelope and return the provider id.
     *
     * Every send funnels through here, so the phone number id in the URL is
     * always the account's own — there is no code path where a caller supplies it.
     */
    private function dispatchMessage(WhatsAppAccount $account, array $payload): string
    {
        $response = $this->request($this->tokenFor($account))->post(
            $this->url("{$account->phone_number_id}/messages"),
            $payload,
        );

        $this->throwUnlessOk($response, 'WhatsApp did not accept that message.');

        $wamid = $response->json('messages.0.id');

        if (!$wamid) {
            throw new WhatsAppApiException(
                'Send returned no message id',
                'WhatsApp accepted the message but did not confirm an id for it.',
            );
        }

        return $wamid;
    }

    private function tokenFor(WhatsAppAccount $account): string
    {
        $token = $account->accessToken();

        if (!filled($token)) {
            throw new WhatsAppApiException(
                'No usable access token for account ' . $account->id,
                $account->unusableReason() ?? 'This WhatsApp number has no usable access token.',
            );
        }

        return $token;
    }

    private function url(string $path): string
    {
        return self::BASE . '/' . $this->settings->apiVersion() . '/' . ltrim($path, '/');
    }

    private function request(?string $token = null)
    {
        $request = Http::acceptJson()->timeout(30)->connectTimeout(10);

        return $token ? $request->withToken($token) : $request;
    }

    /**
     * Turn a failed response into an exception carrying Meta's own explanation.
     *
     * Deliberately reads error.error_user_msg first: for policy failures Meta
     * writes a sentence meant for a human, which beats anything invented here.
     */
    private function throwUnlessOk(Response|ConnectionException $response, string $fallback): void
    {
        if ($response instanceof ConnectionException) {
            throw new WhatsAppApiException(
                'Could not reach Meta: ' . $response->getMessage(),
                'WhatsApp could not be reached. Please try again shortly.',
                retryable: true,
                previous: $response,
            );
        }

        if ($response->successful()) {
            return;
        }

        $error   = $response->json('error') ?? [];
        $code    = isset($error['code']) ? (int) $error['code'] : null;
        $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;

        $userMessage = $error['error_user_msg']
            ?? ($error['message'] ?? null);

        // Log the diagnostic detail, never the credential that produced it.
        Log::warning('whatsapp.api_error', [
            'status'  => $response->status(),
            'code'    => $code,
            'subcode' => $subcode,
            'message' => $error['message'] ?? null,
        ]);

        throw new WhatsAppApiException(
            sprintf('Meta API error %s: %s', $code ?? $response->status(), $error['message'] ?? 'unknown'),
            $this->humanise($code, $userMessage) ?: $fallback,
            $code,
            $subcode,
            retryable: $response->serverError() || in_array($code, self::RETRYABLE_CODES, true),
        );
    }

    /** Meta's phrasing is not always fit for an agent; the common cases are rewritten. */
    private function humanise(?int $code, ?string $metaMessage): ?string
    {
        return match ($code) {
            190     => 'The access token for this WhatsApp number is no longer valid. Reconnect the number.',
            131_047 => 'This customer has not messaged in over 24 hours, so only an approved template can be sent.',
            131_026 => 'WhatsApp could not deliver to this number — it may not have WhatsApp, or it may have blocked this business.',
            131_051 => 'That message type is not supported for this recipient.',
            132_000, 132_001, 132_005, 132_007, 132_012, 132_015 =>
                'That template was rejected by WhatsApp — check it is approved and that the parameters match.',
            4, 80007, 130_429 => 'WhatsApp is rate limiting this number. The message will be retried shortly.',
            default => $metaMessage,
        };
    }
}
