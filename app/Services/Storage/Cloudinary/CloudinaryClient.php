<?php

namespace App\Services\Storage\Cloudinary;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The bit of Cloudinary's REST API this application actually needs.
 *
 * Deliberately no SDK: the upload/destroy/rename endpoints are three signed
 * form posts, and the delivery CDN answers everything else. One less dependency
 * to keep current, and the signing rule stays visible instead of buried.
 *
 * Everything is uploaded as `raw`. Cloudinary's image and video resource types
 * split a file into a public_id plus a separately-tracked format, which breaks
 * the one-to-one "path is the key" mapping a filesystem disk needs; raw keeps
 * the stored path and the public_id identical and accepts any file type
 * byte-for-byte, which is what a document store wants.
 */
class CloudinaryClient
{
    private const API  = 'https://api.cloudinary.com/v1_1';
    private const CDN  = 'https://res.cloudinary.com';
    private const TYPE = 'raw';

    public function __construct(
        private readonly string $cloudName,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        /** Optional prefix every path is stored under, so one cloud can host several apps. */
        private readonly ?string $folder = null,
    ) {
    }

    /** The public delivery URL for a stored path. */
    public function url(string $path): string
    {
        return self::CDN . '/' . $this->cloudName . '/' . self::TYPE . '/upload/' . $this->publicId($path);
    }

    /**
     * @param  string|resource  $contents
     *
     * @throws CloudinaryException
     */
    public function upload(string $path, mixed $contents): array
    {
        $params = [
            'public_id'  => $this->publicId($path),
            'overwrite'  => 'true',
            'invalidate' => 'true',
            'timestamp'  => (string) time(),
        ];

        try {
            $response = Http::asMultipart()
                ->timeout(120)
                ->attach('file', $contents, basename($path))
                ->post($this->endpoint('upload'), $params + [
                    'api_key'   => $this->apiKey,
                    'signature' => $this->sign($params),
                ]);
        } catch (ConnectionException $e) {
            throw new CloudinaryException('Could not reach Cloudinary: ' . $e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new CloudinaryException($this->errorFrom($response->json(), 'Upload rejected by Cloudinary.'));
        }

        return $response->json() ?? [];
    }

    /**
     * Metadata straight off the delivery CDN.
     *
     * A HEAD here rather than the Admin API on purpose: existence and size are
     * checked on every single download, and the Admin API is rate limited per
     * hour while the CDN is not.
     *
     * @return array<string,string>|null  null when the asset is not there
     */
    public function head(string $path): ?array
    {
        try {
            $response = Http::timeout(20)->head($this->url($path));
        } catch (ConnectionException) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        return [
            'size'          => (string) ($response->header('Content-Length') ?: '0'),
            'mime'          => (string) ($response->header('Content-Type') ?: 'application/octet-stream'),
            'last_modified' => (string) ($response->header('Last-Modified') ?: ''),
        ];
    }

    /**
     * Ask the Admin API whether an asset exists.
     *
     * The authoritative answer, and the reason it is needed: the delivery CDN
     * caches a 404. Anything that checks a path *before* writing to it — picking
     * a non-colliding filename, for instance — teaches the edge that the path is
     * missing, and that cached 404 outlives the upload that follows. The Admin
     * API has no such cache.
     *
     * Rate limited, so this is only consulted when the CDN says "no".
     *
     * @return array<string,mixed>|null  the resource, or null when truly absent
     */
    public function resource(string $path): ?array
    {
        try {
            $response = Http::withBasicAuth($this->apiKey, $this->apiSecret)
                ->timeout(20)
                ->get(self::API . '/' . $this->cloudName . '/resources/' . self::TYPE . '/upload/' . $this->publicId($path));
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? ($response->json() ?? null) : null;
    }

    /**
     * Open a read stream on the stored file.
     *
     * @return resource
     *
     * @throws CloudinaryException
     */
    public function readStream(string $path)
    {
        $response = $this->fetch($this->url($path));

        /*
         * A miss here does not mean the file is absent. The delivery edge caches
         * 404s, so a path that was checked before it was written keeps reading
         * as missing from that URL. The Admin API hands back a *versioned* URL
         * (/v1234567/…), which is a different address and therefore not the one
         * carrying the cached miss.
         */
        if ($response === null || !$response->successful()) {
            $versioned = $this->resource($path)['secure_url'] ?? null;

            $response = $versioned ? $this->fetch($versioned) : null;
        }

        if ($response === null || !$response->successful()) {
            throw new CloudinaryException("Cloudinary has no file at '{$path}'.");
        }

        $stream = $response->toPsrResponse()->getBody()->detach();

        if (!is_resource($stream)) {
            throw new CloudinaryException("Could not open a stream for '{$path}'.");
        }

        return $stream;
    }

    /** A streamed GET that reports failure rather than throwing. */
    private function fetch(string $url): ?Response
    {
        try {
            return Http::timeout(120)->withOptions(['stream' => true])->get($url);
        } catch (ConnectionException) {
            return null;
        }
    }

    /** @throws CloudinaryException */
    public function destroy(string $path): void
    {
        $params = [
            'invalidate' => 'true',
            'public_id'  => $this->publicId($path),
            'timestamp'  => (string) time(),
        ];

        $response = Http::asForm()->timeout(60)->post($this->endpoint('destroy'), $params + [
            'api_key'   => $this->apiKey,
            'signature' => $this->sign($params),
        ]);

        // "not found" is the state the caller wanted; only a real failure raises.
        if ($response->failed() && ($response->json('result') !== 'not found')) {
            throw new CloudinaryException($this->errorFrom($response->json(), 'Cloudinary refused the delete.'));
        }
    }

    /** @throws CloudinaryException */
    public function rename(string $from, string $to): void
    {
        $params = [
            'from_public_id' => $this->publicId($from),
            'overwrite'      => 'true',
            'timestamp'      => (string) time(),
            'to_public_id'   => $this->publicId($to),
        ];

        $response = Http::asForm()->timeout(60)->post($this->endpoint('rename'), $params + [
            'api_key'   => $this->apiKey,
            'signature' => $this->sign($params),
        ]);

        if ($response->failed()) {
            throw new CloudinaryException($this->errorFrom($response->json(), 'Cloudinary refused the rename.'));
        }
    }

    /**
     * Admin API listing under a prefix.
     *
     * Rate limited, so this is only used for browsing — never on a download.
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws CloudinaryException
     */
    public function listResources(string $prefix): array
    {
        $resources = [];
        $cursor    = null;

        do {
            $response = Http::withBasicAuth($this->apiKey, $this->apiSecret)
                ->timeout(60)
                ->get(self::API . '/' . $this->cloudName . '/resources/' . self::TYPE . '/upload', array_filter([
                    'prefix'      => $this->publicId($prefix),
                    'max_results' => 500,
                    'next_cursor' => $cursor,
                ]));

            if ($response->failed()) {
                throw new CloudinaryException($this->errorFrom($response->json(), 'Cloudinary refused the listing.'));
            }

            $resources = array_merge($resources, $response->json('resources') ?? []);
            $cursor    = $response->json('next_cursor');
        } while ($cursor);

        return $resources;
    }

    /**
     * Cheapest authenticated call there is — used by the "Test connection"
     * button to prove the credentials, not just the cloud name.
     *
     * @throws CloudinaryException
     */
    public function ping(): void
    {
        try {
            $response = Http::withBasicAuth($this->apiKey, $this->apiSecret)
                ->timeout(20)
                ->get(self::API . '/' . $this->cloudName . '/ping');
        } catch (ConnectionException $e) {
            throw new CloudinaryException('Could not reach Cloudinary: ' . $e->getMessage(), previous: $e);
        }

        if ($response->status() === 401) {
            throw new CloudinaryException('Cloudinary rejected the API key or secret.');
        }

        if ($response->failed()) {
            throw new CloudinaryException($this->errorFrom($response->json(), 'Cloudinary did not accept the credentials.'));
        }
    }

    /** Strip the configured folder back off, turning a public_id into a stored path. */
    public function toPath(string $publicId): string
    {
        $prefix = $this->folderPrefix();

        return $prefix !== '' && str_starts_with($publicId, $prefix)
            ? substr($publicId, strlen($prefix))
            : $publicId;
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function endpoint(string $action): string
    {
        return self::API . '/' . $this->cloudName . '/' . self::TYPE . '/' . $action;
    }

    private function publicId(string $path): string
    {
        return $this->folderPrefix() . ltrim($path, '/');
    }

    private function folderPrefix(): string
    {
        return filled($this->folder) ? trim($this->folder, '/') . '/' : '';
    }

    /**
     * Cloudinary's signature: every signed parameter sorted by name, joined as
     * a query string, with the API secret appended and the lot hashed. The
     * values must be byte-identical to what is actually posted, which is why
     * booleans are carried around as the strings 'true'/'false'.
     */
    private function sign(array $params): string
    {
        ksort($params);

        return sha1(urldecode(http_build_query($params)) . $this->apiSecret);
    }

    private function errorFrom(mixed $body, string $fallback): string
    {
        return is_array($body) ? (string) ($body['error']['message'] ?? $fallback) : $fallback;
    }
}
