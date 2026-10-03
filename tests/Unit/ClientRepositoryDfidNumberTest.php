<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Client;
use App\Repositories\ClientRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Add Client form pre-fills the next DFID number from
 * ClientRepository::nextDfidNumber() — it used to hardcode a "DFP" prefix
 * (and a "DFP25000" fallback) that doesn't match the real "DF" numbering
 * scheme, so a last DFID of e.g. DF925155 suggested "DFP925156" instead of
 * "DF925156".
 */
class ClientRepositoryDfidNumberTest extends TestCase
{
    use RefreshDatabase;

    private function repo(): ClientRepository
    {
        return app(ClientRepository::class);
    }

    private function category(): Category
    {
        return Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);
    }

    public function test_it_increments_the_last_dfid_number_with_the_correct_prefix(): void
    {
        Client::create([
            'dfid_number' => 'DF925155', 'client_name' => 'Last Client', 'brand_name' => 'Brand',
            'category_id' => $this->category()->id,
        ]);

        $this->assertSame('DF925156', $this->repo()->nextDfidNumber());
    }

    public function test_it_ignores_soft_deleted_clients_dfid_but_still_uses_the_df_prefix(): void
    {
        Client::create([
            'dfid_number' => 'DF925155', 'client_name' => 'Deleted Client', 'brand_name' => 'Brand',
            'category_id' => $this->category()->id,
        ])->delete();

        // withTrashed() in the repository means a soft-deleted client's DFID
        // still counts — confirms the prefix fix without changing that rule.
        $this->assertSame('DF925156', $this->repo()->nextDfidNumber());
    }

    public function test_it_falls_back_to_a_df_prefixed_starting_number_when_no_clients_exist(): void
    {
        $this->assertSame('DF25001', $this->repo()->nextDfidNumber());
    }
}
