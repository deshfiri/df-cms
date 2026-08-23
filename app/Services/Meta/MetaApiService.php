<?php

namespace App\Services\Meta;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only place that talks HTTP to Meta.
 *
 * Everything above it — pages, ad accounts, campaigns, insights — is written in
 * terms of get()/paginate() and never touches Guzzle, URLs or tokens directly.
 * That is what keeps a second platform from having to reinvent retries.
 *
 * Handles: cursor pagination, retry with exponential backoff on throttling, and
 * turning Meta's error payloads into a classified MetaApiException.
 */
class MetaApiService
{
    /** Meta's own cap is 500; 100 keeps individual responses manageable. */
    private const PAGE_SIZE = 100;

    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ?string $accessToken = null,
        private readonly ?string $apiVersion = null,
    ) {}

    /** A copy of this service bound to one brand's token. */
    public function withToken(string $token): self
    {
        return new self($token, $this->apiVersion);
    }

    public function baseUrl(): string
    {
        // Resolved lazily so a version set in Settings is picked up without
        // rebuilding the container.
        $version = $this->apiVersion ?: app(MetaAppSettings::class)->apiVersion();

        return 'https://graph.facebook.com/' . $version;
    }

    /**
     * One GET against the Graph API.
     *
     * @throws MetaApiException
     */
    public function get(string $path, array $query = []): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            $response = Http::timeout(30)
                ->retry(1, 250, throw: false)
                ->get($this->baseUrl() . '/' . ltrim($path, '/'), $query + [
                    'access_token' => $this->accessToken,
                ]);

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            $exception = $this->exceptionFrom($response);

            // Back off and try again on throttling or a wobble at their end.
            if ($exception->isRetryable() && $attempt < self::MAX_ATTEMPTS) {
                // 2s, 4s, 8s — enough to clear a short rate-limit window without
                // holding a queue worker for minutes.
                sleep(2 ** $attempt);
                continue;
            }

            // Never log the token, only where the call was going.
            Log::warning('Meta API request failed', [
                'path'    => $path,
                'kind'    => $exception->kind,
                'code'    => $exception->metaCode,
                'status'  => $exception->httpStatus,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Walk a cursor-paginated edge and return every row.
     *
     * @param  int  $maxPages  A stop so a runaway edge cannot spin forever.
     * @return array<int,array<string,mixed>>
     */
    public function paginate(string $path, array $query = [], int $maxPages = 25): array
    {
        $query += ['limit' => self::PAGE_SIZE];

        $rows = [];
        $after = null;
        $page = 0;

        do {
            $page++;

            $payload = $this->get($path, $after ? $query + ['after' => $after] : $query);
            $rows = array_merge($rows, $payload['data'] ?? []);

            $after = $payload['paging']['cursors']['after'] ?? null;
            // Meta signals "no more" by dropping paging.next, not by an empty cursor.
            $hasNext = isset($payload['paging']['next']);
        } while ($hasNext && $after && $page < $maxPages);

        return $rows;
    }

    private function exceptionFrom(Response $response): MetaApiException
    {
        $error = $response->json('error');

        if (is_array($error)) {
            return MetaApiException::fromResponse($error, $response->status());
        }

        return new MetaApiException(
            'HTTP ' . $response->status() . ' from Meta',
            $response->status() >= 500 ? MetaApiException::KIND_UNAVAILABLE : MetaApiException::KIND_UNKNOWN,
            null,
            $response->status(),
        );
    }
}
