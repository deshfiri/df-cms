<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Flow;
use App\Models\User;
use App\Services\FlowService;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The client-progress needle behind WorkflowVolumeClientProgressTest ("0%
 * doesn't count yet") is a client-handling concept: it exists so someone
 * managing a client's overall progress isn't credited for items sitting
 * untouched. A worker without client permission never sees or manages that
 * progress at all — they just work whatever stage lands in front of them —
 * so gating their Output Volume credit on it would dock them for something
 * outside their role. For them, every distinct client a flow item is linked
 * to counts as soon as they've touched it, same as a standalone item, and
 * the Client Handling scope never applies to them regardless of any stray
 * `assigned_to` row.
 */
class WorkflowVolumeNoClientAccessTest extends TestCase
{
    use RefreshDatabase;

    private FlowService $flow;
    private PerformanceCalculationService $performance;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Permission::firstOrCreate(['name' => 'view clients', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'manage clients', 'guard_name' => 'web']);
        $this->flow = app(FlowService::class);
        $this->performance = app(PerformanceCalculationService::class);
    }

    /** A user with no permissions at all — a stage-only worker, not a client handler. */
    private function worker(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function actor(): User
    {
        return tap(User::factory()->create(['is_active' => true]))->givePermissionTo('manage clients')->fresh();
    }

    private function client(): Client
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'ACME Ltd', 'brand_name' => 'ACME',
            'category_id' => $category->id,
        ]);
    }

    private function flowFor(User $worker, int $stageCount = 2): Flow
    {
        $flow = Flow::create(['name' => 'Flow ' . uniqid(), 'is_active' => true, 'created_by' => $this->actor()->id]);
        foreach (range(1, $stageCount) as $position) {
            $flow->stages()->create(['name' => "Stage {$position}", 'position' => $position])->users()->sync([$worker->id]);
        }

        return $flow->refresh();
    }

    public function test_a_client_linked_item_still_counts_even_while_sitting_at_its_first_stage(): void
    {
        $worker = $this->worker();
        $client = $this->client();
        $flow   = $this->flowFor($worker, 3);

        $item = $this->flow->createItem($flow, ['title' => 'Item', 'due_date' => '2026-09-10', 'client_id' => $client->id], $this->actor());
        $this->flow->claim($item->fresh(), $worker); // still sitting at stage 1 — 0% done, but this worker touched it

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];
        $this->assertSame(1.0, $scope['mine'], 'a worker without client permission should be credited for touching it regardless of the client\'s own progress');
    }

    /** Mirrors the reported case: every item a permission-less worker moved along should count, matching the busiest toucher. */
    public function test_cohort_max_also_bypasses_the_progress_gate_for_a_worker_without_client_access(): void
    {
        $worker = $this->worker();

        foreach (range(1, 3) as $i) {
            $client = $this->client();
            $flow   = $this->flowFor($worker, 2);
            $item   = $this->flow->createItem($flow, ['title' => "Item {$i}", 'due_date' => '2026-09-10', 'client_id' => $client->id], $this->actor());
            $item   = $this->flow->claim($item->fresh(), $worker);
            $this->flow->advance($item, $worker); // passed to the next stage — nothing left held
        }

        $scope = $this->performance->outputVolume($worker, '2026-09')['scopes']['workflow'];

        $this->assertSame(3.0, $scope['mine']);
        $this->assertSame(3.0, $scope['cohort_max']);
        $this->assertSame(100.0, $scope['pct'], 'a worker who cleared everything they touched should not be marked down against the company max');
    }

    public function test_client_handling_scope_never_applies_to_a_user_without_client_access(): void
    {
        $worker = $this->worker();
        $client = $this->client();
        // A stray assignment (e.g. left over from before a role change) — never
        // legitimately reachable through ClientPolicy, but nothing stops the
        // column itself from holding it.
        $client->forceFill(['assigned_to' => $worker->id])->save();

        // Nothing else to show in Output Volume either, so with the
        // client_handling scope correctly excluded there is nothing left at all.
        $this->assertNull($this->performance->outputVolume($worker, '2026-09'));

        // Give them something unrelated to show, and confirm client_handling
        // still never appears alongside it.
        $flow = $this->flowFor($worker, 1);
        $item = $this->flow->createItem($flow, ['title' => 'Internal', 'due_date' => '2026-09-10'], $this->actor());
        $this->flow->claim($item->fresh(), $worker);

        $result = $this->performance->outputVolume($worker, '2026-09');

        $this->assertArrayHasKey('workflow', $result['scopes']);
        $this->assertArrayNotHasKey('client_handling', $result['scopes']);
    }
}
