<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\AdCampaignDailyReport;
use App\Models\Category;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The endpoints and policy for deleting a campaign existed, but nothing in the
 * interface ever called them — there was no way to remove a campaign.
 */
class AdCampaignDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view ads', 'manage ads'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function user(array $roles = [], array $permissions = []): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $user->assignRole($role);
        }

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function campaign(): AdCampaign
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        $client = Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'ACME Ltd',
            'brand_name'  => 'ACME',
            'category_id' => $category->id,
        ]);

        return AdCampaign::create([
            'client_id'  => $client->id,
            'name'       => 'Ramadan push',
            'status'     => 'Active',
            'budget'     => 5000,
            'created_by' => User::factory()->create(['is_active' => true])->id,
        ]);
    }

    public function test_a_manager_can_delete_a_campaign(): void
    {
        $campaign = $this->campaign();

        $this->actingAs($this->user(['Manager'], ['view ads', 'manage ads']))
            ->deleteJson(route('ads.destroy', $campaign))
            ->assertOk()
            ->assertJsonPath('success', true);

        // Soft delete: gone from the list, retained in the table.
        $this->assertSoftDeleted('ad_campaigns', ['id' => $campaign->id]);
        $this->assertNull(AdCampaign::find($campaign->id));
    }

    public function test_someone_who_merely_manages_ads_cannot_delete(): void
    {
        $campaign = $this->campaign();

        // 'manage ads' is enough to edit a campaign but not to remove one.
        $this->actingAs($this->user([], ['view ads', 'manage ads']))
            ->deleteJson(route('ads.destroy', $campaign))
            ->assertForbidden();

        $this->assertNotNull(AdCampaign::find($campaign->id));
    }

    public function test_the_delete_button_is_only_rendered_for_those_who_may_use_it(): void
    {
        $campaign = $this->campaign();

        $manager = $this->user(['Manager'], ['view ads', 'manage ads']);
        $worker  = $this->user([], ['view ads', 'manage ads']);

        // The controller branches on $request->ajax(), which needs the XHR
        // header — a plain getJson() returns the whole page, whose inline
        // handler mentions the class and would pass either way.
        $rows = fn (User $user) => $this->actingAs($user)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('ads.index') . '?draw=1&start=0&length=10')
            ->assertOk()
            ->json('data.0.actions');

        $this->assertStringContainsString('campaign-delete', $rows($manager));
        $this->assertStringNotContainsString('campaign-delete', $rows($worker));
    }

    public function test_a_deleted_campaigns_spend_leaves_the_headline_totals(): void
    {
        $campaign = $this->campaign();

        AdCampaignDailyReport::create([
            'ad_campaign_id' => $campaign->id,
            'report_date'    => now()->toDateString(),
            'spend'          => 1200,
            'sales'          => 3000,
            'leads'          => 10,
            'orders'         => 4,
        ]);

        $manager = $this->user(['Manager'], ['view ads', 'manage ads']);

        $before = $this->actingAs($manager)->get(route('ads.index'))->viewData('totals');
        $this->assertSame(1200.0, $before['total_spend']);

        $this->actingAs($manager)->deleteJson(route('ads.destroy', $campaign))->assertOk();

        // The reports survive the soft delete, so without scoping they would
        // keep inflating the totals above a table that no longer lists them.
        $after = $this->actingAs($manager)->get(route('ads.index'))->viewData('totals');
        $this->assertSame(0.0, $after['total_spend']);
        $this->assertSame(0.0, $after['total_sales']);
    }
}
