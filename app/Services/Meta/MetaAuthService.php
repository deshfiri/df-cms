<?php

namespace App\Services\Meta;

use App\Models\Brand;
use App\Models\BrandIntegration;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Meta OAuth: build the consent URL, exchange the code, store the token.
 *
 * The short-lived token Meta returns from the code exchange lasts about an
 * hour, which is useless for a background sync — so it is immediately traded
 * for a long-lived one (~60 days) and only that is stored.
 */
class MetaAuthService
{
    public function __construct(
        private readonly MetaApiService $api,
        private readonly MetaAppSettings $settings,
    ) {}

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured();
    }

    /**
     * Where to send the administrator to approve access.
     *
     * `state` carries the brand and a random nonce; the callback checks both,
     * so a stray callback cannot attach someone else's account to a brand.
     */
    public function authorizationUrl(Brand $brand, string $nonce): string
    {
        return 'https://www.facebook.com/' . $this->settings->apiVersion() . '/dialog/oauth?'
            . http_build_query([
                'client_id'     => $this->settings->appId(),
                'redirect_uri'  => $this->settings->redirectUri(),
                'state'         => $brand->id . ':' . $nonce,
                'scope'         => implode(',', $this->settings->scopes()),
                'response_type' => 'code',
            ]);
    }

    public static function newNonce(): string
    {
        return Str::random(40);
    }

    /** @return array{brand_id:int,nonce:string}|null */
    public static function parseState(?string $state): ?array
    {
        if (!$state || !str_contains($state, ':')) {
            return null;
        }

        [$brandId, $nonce] = explode(':', $state, 2);

        return ctype_digit($brandId) ? ['brand_id' => (int) $brandId, 'nonce' => $nonce] : null;
    }

    /**
     * Trade the one-time code for a long-lived token and store the integration.
     *
     * @throws MetaApiException
     */
    public function completeConnection(Brand $brand, string $code, User $actor): BrandIntegration
    {
        $shortLived = $this->exchangeCode($code);
        $longLived  = $this->exchangeForLongLivedToken($shortLived['access_token']);

        $profile = $this->api->withToken($longLived['access_token'])->get('me', ['fields' => 'id,name']);

        $integration = BrandIntegration::updateOrCreate(
            ['brand_id' => $brand->id, 'platform' => BrandIntegration::PLATFORM_META],
            [
                'status'      => BrandIntegration::STATUS_CONNECTED,
                'credentials' => [
                    'access_token' => $longLived['access_token'],
                    'token_type'   => $longLived['token_type'] ?? 'bearer',
                ],
                'metadata' => [
                    'account_id'   => $profile['id'] ?? null,
                    'account_name' => $profile['name'] ?? null,
                    'scopes'       => config('services.meta.scopes', []),
                ],
                'connected_at'     => now(),
                'token_expires_at' => isset($longLived['expires_in'])
                    ? Carbon::now()->addSeconds((int) $longLived['expires_in'])
                    : null,
                'last_error'   => null,
                'connected_by' => $actor->id,
            ]
        );

        return $integration->fresh();
    }

    public function disconnect(BrandIntegration $integration): void
    {
        // The token is cleared rather than the row: the sync history and the
        // resources the brand selected stay readable.
        $integration->update([
            'status'           => BrandIntegration::STATUS_DISCONNECTED,
            'credentials'      => null,
            'token_expires_at' => null,
            'last_error'       => null,
        ]);
    }

    /** @throws MetaApiException */
    private function exchangeCode(string $code): array
    {
        $response = Http::timeout(30)->get($this->api->baseUrl() . '/oauth/access_token', [
            'client_id'     => $this->settings->appId(),
            'client_secret' => $this->settings->appSecret(),
            'redirect_uri'  => $this->settings->redirectUri(),
            'code'          => $code,
        ]);

        if ($response->failed() || !$response->json('access_token')) {
            throw MetaApiException::fromResponse(
                $response->json('error') ?? ['message' => 'Meta did not return an access token.'],
                $response->status()
            );
        }

        return $response->json();
    }

    /** @throws MetaApiException */
    private function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $response = Http::timeout(30)->get($this->api->baseUrl() . '/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->settings->appId(),
            'client_secret'     => $this->settings->appSecret(),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if ($response->failed() || !$response->json('access_token')) {
            throw MetaApiException::fromResponse(
                $response->json('error') ?? ['message' => 'Meta refused to issue a long-lived token.'],
                $response->status()
            );
        }

        return $response->json();
    }
}
