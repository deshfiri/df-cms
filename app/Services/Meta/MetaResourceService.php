<?php

namespace App\Services\Meta;

use App\Models\BrandIntegration;
use App\Models\IntegrationResource;
use Illuminate\Support\Facades\DB;

/**
 * Discovers what a connected Meta account can offer, and records what the user
 * picked for the brand.
 *
 * Discovery is read-only. Nothing is synced until a resource is selected, so a
 * connection to an account with fifty ad accounts does not drag fifty accounts'
 * worth of campaigns into the database.
 */
class MetaResourceService
{
    public function __construct(
        private readonly MetaApiService $api,
    ) {}

    /**
     * Everything available on the connected account, grouped by type.
     *
     * A failure fetching one type is not allowed to hide the others — an app
     * without instagram_basic should still be able to pick an ad account.
     *
     * @return array<string,array<int,array<string,mixed>>>
     * @throws MetaApiException  only when the token itself is unusable
     */
    public function discover(BrandIntegration $integration): array
    {
        $api = $this->api->withToken($integration->accessToken());

        return [
            IntegrationResource::TYPE_AD_ACCOUNT => $this->safely(fn () => array_map(
                fn (array $row) => [
                    'external_id' => $row['id'],
                    'name'        => $row['name'] ?? $row['id'],
                    'status'      => $this->accountStatus($row['account_status'] ?? null),
                    'metadata'    => [
                        'currency'         => $row['currency'] ?? null,
                        'timezone'         => $row['timezone_name'] ?? null,
                        'business_name'    => $row['business']['name'] ?? null,
                        'account_id'       => $row['account_id'] ?? null,
                    ],
                ],
                $api->paginate('me/adaccounts', [
                    'fields' => 'id,name,account_id,account_status,currency,timezone_name,business{id,name}',
                ])
            ), $integration),

            IntegrationResource::TYPE_PAGE => $this->safely(fn () => array_map(
                fn (array $row) => [
                    'external_id' => $row['id'],
                    'name'        => $row['name'] ?? $row['id'],
                    'status'      => ($row['is_published'] ?? true) ? 'active' : 'unpublished',
                    'metadata'    => ['category' => $row['category'] ?? null],
                ],
                $api->paginate('me/accounts', ['fields' => 'id,name,category,is_published'])
            ), $integration),

            IntegrationResource::TYPE_BUSINESS => $this->safely(fn () => array_map(
                fn (array $row) => [
                    'external_id' => $row['id'],
                    'name'        => $row['name'] ?? $row['id'],
                    'status'      => 'active',
                    'metadata'    => [],
                ],
                $api->paginate('me/businesses', ['fields' => 'id,name'])
            ), $integration),

            // Instagram accounts and pixels hang off pages and ad accounts
            // respectively, so they are discovered from what we just found.
            IntegrationResource::TYPE_INSTAGRAM => $this->safely(
                fn () => $this->instagramAccounts($api),
                $integration
            ),
            IntegrationResource::TYPE_PIXEL => $this->safely(
                fn () => $this->pixels($api),
                $integration
            ),
        ];
    }

    /**
     * Store the discovered resources, marking the chosen ones selected.
     *
     * @param  array<string,array<int,string>>  $selection  type => external ids
     */
    public function storeSelection(BrandIntegration $integration, array $discovered, array $selection): void
    {
        DB::transaction(function () use ($integration, $discovered, $selection) {
            foreach ($discovered as $type => $rows) {
                $chosen = $selection[$type] ?? [];

                foreach ($rows as $row) {
                    IntegrationResource::updateOrCreate(
                        [
                            'brand_integration_id' => $integration->id,
                            'type'                 => $type,
                            'external_id'          => $row['external_id'],
                        ],
                        [
                            'name'        => $row['name'] ?? null,
                            'status'      => $row['status'] ?? null,
                            'metadata'    => $row['metadata'] ?? [],
                            'is_selected' => in_array($row['external_id'], $chosen, true),
                        ]
                    );
                }
            }
        });
    }

    /** @return array<int,array<string,mixed>> */
    private function instagramAccounts(MetaApiService $api): array
    {
        $accounts = [];

        foreach ($api->paginate('me/accounts', ['fields' => 'id,name,instagram_business_account{id,username,name}']) as $page) {
            $ig = $page['instagram_business_account'] ?? null;

            if (!$ig) {
                continue;
            }

            $accounts[] = [
                'external_id' => $ig['id'],
                'name'        => $ig['username'] ?? ($ig['name'] ?? $ig['id']),
                'status'      => 'active',
                'metadata'    => ['linked_page_id' => $page['id'], 'linked_page_name' => $page['name'] ?? null],
            ];
        }

        return $accounts;
    }

    /** @return array<int,array<string,mixed>> */
    private function pixels(MetaApiService $api): array
    {
        $pixels = [];

        foreach ($api->paginate('me/adaccounts', ['fields' => 'id']) as $account) {
            foreach ($api->paginate($account['id'] . '/adspixels', ['fields' => 'id,name,last_fired_time']) as $pixel) {
                $pixels[$pixel['id']] = [
                    'external_id' => $pixel['id'],
                    'name'        => $pixel['name'] ?? $pixel['id'],
                    'status'      => 'active',
                    'metadata'    => [
                        'ad_account_id'   => $account['id'],
                        'last_fired_time' => $pixel['last_fired_time'] ?? null,
                    ],
                ];
            }
        }

        // Keyed by id above so a pixel shared across ad accounts appears once.
        return array_values($pixels);
    }

    /**
     * Run one discovery step, letting a permission gap degrade to an empty list
     * rather than failing the whole page. A dead token is still fatal.
     *
     * @throws MetaApiException
     */
    private function safely(callable $fetch, BrandIntegration $integration): array
    {
        try {
            return $fetch();
        } catch (MetaApiException $e) {
            if ($e->kind === MetaApiException::KIND_TOKEN_EXPIRED) {
                throw $e;
            }

            return [];
        }
    }

    /** Meta reports account status as an integer code. */
    private function accountStatus(?int $code): string
    {
        return match ($code) {
            1       => 'active',
            2       => 'disabled',
            3       => 'unsettled',
            7       => 'pending_review',
            9       => 'grace_period',
            100     => 'closed',
            default => 'unknown',
        };
    }
}
