<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Several workflows can exist at once; marking one as "lead" is what the
 * admin/manager dashboard's pipeline widgets read from. Only one flow may
 * hold the flag at a time.
 */
class LeadWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'manage workflows', 'guard_name' => 'web']);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(['is_active' => true]))->givePermissionTo('manage workflows')->fresh();
    }

    public function test_setting_a_flow_as_lead_unsets_the_previous_lead(): void
    {
        $admin = $this->admin();
        $a = Flow::create(['name' => 'A', 'is_active' => true, 'is_lead' => true, 'created_by' => $admin->id]);
        $b = Flow::create(['name' => 'B', 'is_active' => true, 'created_by' => $admin->id]);

        $this->actingAs($admin)->post(route('workflows.set-lead', $b))
            ->assertOk()
            ->assertJson(['success' => true, 'is_lead' => true]);

        $this->assertFalse($a->fresh()->is_lead);
        $this->assertTrue($b->fresh()->is_lead);
    }

    public function test_clicking_the_current_lead_again_unsets_it(): void
    {
        $admin = $this->admin();
        $flow = Flow::create(['name' => 'A', 'is_active' => true, 'is_lead' => true, 'created_by' => $admin->id]);

        $this->actingAs($admin)->post(route('workflows.set-lead', $flow))
            ->assertOk()
            ->assertJson(['success' => true, 'is_lead' => false]);

        $this->assertFalse($flow->fresh()->is_lead);
    }

    public function test_it_requires_the_manage_workflows_permission(): void
    {
        $staff = User::factory()->create(['is_active' => true]);
        $flow = Flow::create(['name' => 'A', 'is_active' => true, 'created_by' => $staff->id]);

        $this->actingAs($staff)->post(route('workflows.set-lead', $flow))->assertForbidden();
    }

    public function test_the_workflows_page_shows_which_flow_is_lead(): void
    {
        $admin = $this->admin();
        Flow::create(['name' => 'Lead Flow', 'is_active' => true, 'is_lead' => true, 'created_by' => $admin->id]);
        Flow::create(['name' => 'Other Flow', 'is_active' => true, 'created_by' => $admin->id]);

        $this->actingAs($admin)->get(route('workflows.index'))
            ->assertOk()
            ->assertSee('Lead Flow')
            ->assertSee('bi-star-fill', false);
    }
}
