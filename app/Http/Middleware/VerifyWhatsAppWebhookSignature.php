<?php

namespace App\Http\Middleware;

use App\Services\WhatsApp\WhatsAppSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proves a webhook delivery really came from Meta.
 *
 * The endpoint is public and CSRF-exempt, so this signature is the *only* thing
 * standing between the internet and our message ingestion. Without it anyone
 * could post a payload naming any phone_number_id and inject messages into a
 * brand's inbox.
 *
 * Meta signs the raw request body with the app secret and sends the result as
 * `X-Hub-Signature-256: sha256=<hex>`. Two details matter:
 *
 *  - the signature covers the *raw* body, so the comparison must use
 *    getContent() and never a re-encoded version of the parsed payload;
 *  - the comparison must be hash_equals, not ===, so a timing side channel
 *    cannot be used to discover a valid signature byte by byte.
 */
class VerifyWhatsAppWebhookSignature
{
    public function __construct(
        private readonly WhatsAppSettings $settings,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->settings->appSecret();

        if (!filled($secret)) {
            // Refuse rather than accept: an unconfigured installation must not
            // silently trust unsigned traffic.
            Log::warning('whatsapp.webhook_rejected', ['reason' => 'no app secret configured']);

            return response()->json(['error' => 'WhatsApp is not configured.'], 403);
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');

        if (!str_starts_with($header, 'sha256=')) {
            Log::warning('whatsapp.webhook_rejected', ['reason' => 'missing or malformed signature header']);

            return response()->json(['error' => 'Invalid signature.'], 403);
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (!hash_equals($expected, substr($header, 7))) {
            Log::warning('whatsapp.webhook_rejected', ['reason' => 'signature mismatch']);

            return response()->json(['error' => 'Invalid signature.'], 403);
        }

        return $next($request);
    }
}
