<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BrandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view ads', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'manage ads', 'guard_name' => 'web']);
    }

    private function makeClient(string $name = 'Test Client'): Client
    {
        $category = Category::create(['name' => 'Test Category', 'slug' => 'test-category-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => $name,
            'brand_name'  => 'Test Brand',
            'category_id' => $category->id,
        ]);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);

        if (in_array($role, ['Marketing', 'Manager', 'Super Admin'], true)) {
            $user->givePermissionTo(['view ads', 'manage ads']);
        }

        return $user;
    }

    public function test_manage_ads_holder_can_create_a_brand_for_a_client(): void
    {
        $marketing = $this->makeUser('Marketing');
        $client = $this->makeClient();

        $response = $this->actingAs($marketing)->postJson(route('clients.brands.store', $client), [
            'name' => 'Summer Collection',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('brands', ['client_id' => $client->id, 'name' => 'Summer Collection']);
    }

    public function test_user_without_ads_permission_cannot_create_a_brand(): void
    {
        $sales = $this->makeUser('Sales');
        $client = $this->makeClient();

        $response = $this->actingAs($sales)->postJson(route('clients.brands.store', $client), [
            'name' => 'Unauthorized Brand',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('brands', ['name' => 'Unauthorized Brand']);
    }

    public function test_duplicate_brand_name_rejected_for_same_client_but_allowed_across_clients(): void
    {
        $marketing = $this->makeUser('Marketing');
        $client = $this->makeClient();
        $otherClient = $this->makeClient('Other Client');

        $this->actingAs($marketing)->postJson(route('clients.brands.store', $client), ['name' => 'Shared Name'])->assertOk();

        $duplicate = $this->actingAs($marketing)->postJson(route('clients.brands.store', $client), ['name' => 'Shared Name']);
        $duplicate->assertStatus(422);

        $acrossClients = $this->actingAs($marketing)->postJson(route('clients.brands.store', $otherClient), ['name' => 'Shared Name']);
        $acrossClients->assertOk();
    }

    public function test_campaign_can_be_created_with_a_brand_belonging_to_the_same_client(): void
    {
        $marketing = $this->makeUser('Marketing');
        $client = $this->makeClient();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Winter Line']);

        $response = $this->actingAs($marketing)->postJson(route('clients.ads.store', $client), [
            'name'     => 'Winter Campaign',
            'status'   => 'Active',
            'brand_id' => $brand->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('ad_campaigns', ['name' => 'Winter Campaign', 'brand_id' => $brand->id]);
    }

    public function test_campaign_creation_rejects_a_brand_belonging_to_a_different_client(): void
    {
        $marketing = $this->makeUser('Marketing');
        $client = $this->makeClient();
        $otherClient = $this->makeClient('Other Client');
        $foreignBrand = Brand::create(['client_id' => $otherClient->id, 'name' => 'Foreign Brand']);

        $response = $this->actingAs($marketing)->postJson(route('clients.ads.store', $client), [
            'name'     => 'Cross Tenant Campaign',
            'status'   => 'Active',
            'brand_id' => $foreignBrand->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('ad_campaigns', ['name' => 'Cross Tenant Campaign']);
    }

    public function test_deleting_a_brand_nulls_its_campaigns_instead_of_deleting_them(): void
    {
        $marketing = $this->makeUser('Marketing');
        $client = $this->makeClient();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Retiring Brand']);
        $campaign = AdCampaign::create([
            'client_id'  => $client->id,
            'brand_id'   => $brand->id,
            'name'       => 'Still Alive',
            'status'     => 'Active',
            'created_by' => $marketing->id,
        ]);

        $response = $this->actingAs($marketing)->deleteJson(route('clients.brands.destroy', [$client, $brand]));

        $response->assertOk();
        $this->assertSoftDeleted('brands', ['id' => $brand->id]);
        $this->assertDatabaseHas('ad_campaigns', ['id' => $campaign->id, 'brand_id' => null]);
    }

    public function test_ads_index_filters_by_brand_id(): void
    {
        $marketing = $this->makeUser('Marketing');
        $client = $this->makeClient();
        $brandA = Brand::create(['client_id' => $client->id, 'name' => 'Brand A']);
        $brandB = Brand::create(['client_id' => $client->id, 'name' => 'Brand B']);

        AdCampaign::create(['client_id' => $client->id, 'brand_id' => $brandA->id, 'name' => 'Campaign A', 'status' => 'Active', 'created_by' => $marketing->id]);
        AdCampaign::create(['client_id' => $client->id, 'brand_id' => $brandB->id, 'name' => 'Campaign B', 'status' => 'Active', 'created_by' => $marketing->id]);

        $response = $this->actingAs($marketing)->getJson(route('clients.ads.index', $client) . '?brand_id=' . $brandA->id);

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['Campaign A'], $names);
    }
}
