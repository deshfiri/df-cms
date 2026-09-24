<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\KpiWeightConfig;
use App\Models\PerformanceSetting;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Client care: credit for adding clients and looking after your own — from the
 * work recorded against them, never from viewing them.
 */
class ClientCarePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-09';

    private User $sam;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
        foreach (['view performance', 'manage performance'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        PerformanceSetting::current()->update(['client_care_target_points' => 20]);
        $this->sam      = User::factory()->create(['is_active' => true, 'name' => 'Sales Sam']);
        $this->category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);
    }

    private function client(array $attributes = []): Client
    {
        return Client::create($attributes + [
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'Client ' . uniqid(), 'brand_name' => 'Brand',
            'category_id' => $this->category->id, 'client_status' => 'Running',
        ]);
    }

    /** Log a piece of work as $user at $when. */
    private function work(User $user, Client $client, string $module, string $action, string $when): void
    {
        $this->travelTo(Carbon::parse($when));
        $this->actingAs($user);
        app(ActivityLogService::class)->log($module, $action, $client->id);
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
    }

    private function care(User $user): ?array
    {
        return app(PerformanceCalculationService::class)->clientCare($user, self::PERIOD);
    }

    public function test_looking_after_your_own_clients_is_scored_by_coverage_and_activity(): void
    {
        $a = $this->client(['assigned_to' => $this->sam->id]);
        $b = $this->client(['assigned_to' => $this->sam->id]);
        $this->client(['assigned_to' => $this->sam->id, 'client_status' => 'Hold']);   // not active

        $this->work($this->sam, $a, 'Note', 'Created', '2026-09-02 10:00');
        $this->work($this->sam, $a, 'Client', 'Updated', '2026-09-03 10:00');
        $this->work($this->sam, $a, 'Meeting', 'Completed', '2026-09-04 10:00');

        $care = $this->care($this->sam);

        $this->assertSame(3, $care['clients_total']);
        $this->assertSame(2, $care['clients_active']);
        $this->assertSame(1, $care['active_maintained']);
        $this->assertSame(3, $care['upkeep_days']);
        $this->assertSame(3, $care['points']);
        $this->assertSame(50.0, $care['coverage_pct']);          // a, not b
        $this->assertSame(15.0, $care['activity_pct']);          // 3 of 20
        $this->assertSame(32.5, $care['score']);
    }

    public function test_adding_clients_by_hand_counts_and_imports_do_not(): void
    {
        $this->actingAs($this->sam);
        $mine = $this->client(['created_by' => $this->sam->id]);                              // unassigned → Sam's
        $theirs = $this->client(['created_by' => $this->sam->id, 'assigned_to' => User::factory()->create()->id]);
        $imported = $this->client(['created_by' => $this->sam->id]);
        app(ActivityLogService::class)->log('Import', 'Client Imported', $imported->id);

        $care = $this->care($this->sam);

        $this->assertSame(2, $care['clients_added'], 'Handing a client to someone else still credits bringing it in.');
        $this->assertSame(6, $care['points']);
        $this->assertSame(2, $care['clients_active'], 'The unassigned ones are still Sam\'s to look after.');
        $this->assertNotContains($theirs->id, [$mine->id]);
    }

    public function test_repetition_is_not_extra_care(): void
    {
        $a = $this->client(['assigned_to' => $this->sam->id]);

        // Ten edits in one day are one day.
        foreach (range(1, 10) as $i) {
            $this->work($this->sam, $a, 'Client', 'Updated', sprintf('2026-09-05 09:%02d', $i * 5));
        }
        $this->assertSame(1, $this->care($this->sam)['upkeep_days']);

        // And one client can carry at most four days of the month.
        foreach (range(6, 15) as $day) {
            $this->work($this->sam, $a, 'Note', 'Created', "2026-09-{$day} 10:00");
        }
        $this->assertSame(4, $this->care($this->sam)['upkeep_days']);
    }

    public function test_only_real_work_on_your_own_clients_counts(): void
    {
        $mine   = $this->client(['assigned_to' => $this->sam->id]);
        $others = $this->client(['assigned_to' => User::factory()->create()->id]);

        $this->work($this->sam, $others, 'Note', 'Created', '2026-09-02 10:00');           // someone else's client
        $this->work($this->sam, $mine, 'Document', 'Downloaded: Contract', '2026-09-03 10:00');   // reading, not work
        $this->work($this->sam, $mine, 'Note', 'Deleted', '2026-09-04 10:00');             // removing isn't upkeep
        $this->work($this->sam, $mine, 'Client', 'Updated', '2026-08-30 10:00');           // last month

        $care = $this->care($this->sam);
        $this->assertSame(0, $care['upkeep_days']);
        $this->assertSame(0.0, $care['coverage_pct']);
    }

    public function test_someone_with_no_client_work_is_not_scored_on_it(): void
    {
        $designer = User::factory()->create(['is_active' => true]);
        $client   = $this->client(['assigned_to' => $this->sam->id]);
        $this->work($designer, $client, 'Document', 'Uploaded', '2026-09-02 10:00');       // Sam's client, not theirs

        $this->assertNull($this->care($designer));
        $this->assertNull(app(PerformanceCalculationService::class)->finalScore($designer, self::PERIOD)['scores']['client_care']);
    }

    public function test_it_counts_in_the_final_score_with_its_weight(): void
    {
        KpiWeightConfig::create([
            'scope_type' => KpiWeightConfig::SCOPE_GLOBAL,
            'task_completion_weight' => 20, 'on_time_weight' => 20, 'revision_weight' => 15,
            'sales_weight' => 15, 'satisfaction_weight' => 15, 'client_care_weight' => 15,
        ]);
        $a = $this->client(['assigned_to' => $this->sam->id]);
        $this->work($this->sam, $a, 'Note', 'Created', '2026-09-02 10:00');

        $result = app(PerformanceCalculationService::class)->finalScore($this->sam, self::PERIOD);

        // Client care is the only KPI with data, so it is the whole score.
        $this->assertSame(['client_care' => 100.0], $result['weights_used']);
        $this->assertSame($result['scores']['client_care'], $result['final_score']);

        // Weighted 0 by a profile, it is shown but can't move the score.
        KpiWeightConfig::query()->update(['client_care_weight' => 0, 'task_completion_weight' => 35]);
        $zero = app(PerformanceCalculationService::class)->finalScore($this->sam, self::PERIOD);
        $this->assertNotNull($zero['scores']['client_care']);
        $this->assertNull($zero['final_score']);
    }

    public function test_batch_scoring_matches_scoring_one_by_one(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $a = $this->client(['assigned_to' => $this->sam->id]);
        $b = $this->client(['assigned_to' => $other->id]);
        $this->work($this->sam, $a, 'Note', 'Created', '2026-09-02 10:00');
        $this->work($other, $b, 'Client', 'Status Changed', '2026-09-03 10:00');

        $one = [app(PerformanceCalculationService::class)->clientCare($this->sam, self::PERIOD), app(PerformanceCalculationService::class)->clientCare($other, self::PERIOD)];

        $batched = app(PerformanceCalculationService::class);
        $batched->prefetch(collect([$this->sam, $other]), self::PERIOD);

        $this->assertSame($one, [$batched->clientCare($this->sam, self::PERIOD), $batched->clientCare($other, self::PERIOD)]);
    }

    public function test_the_scorecard_and_configuration_show_client_care(): void
    {
        $manager = tap(User::factory()->create(['is_active' => true]))->givePermissionTo(['view performance', 'manage performance']);
        $a = $this->client(['assigned_to' => $this->sam->id]);
        $this->work($this->sam, $a, 'Note', 'Created', '2026-09-02 10:00');

        $this->actingAs($manager)->get(route('performance.show', ['user' => $this->sam, 'period' => self::PERIOD]))
            ->assertOk()
            ->assertSee('Client Care')
            ->assertSee('Active and looked after');

        $this->actingAs($manager)->postJson(route('performance.config.weights.store'), [
            'scope_type' => 'global',
            'task_completion_weight' => 20, 'on_time_weight' => 20, 'revision_weight' => 10,
            'sales_weight' => 15, 'satisfaction_weight' => 15, 'client_care_weight' => 20,
            'daily_target_weight' => 0, 'output_volume_weight' => 0, 'task_giving_weight' => 0,
        ])->assertOk();
        $this->assertSame(20, KpiWeightConfig::where('scope_type', 'global')->value('client_care_weight'));

        $this->actingAs($manager)->get(route('performance.config'))
            ->assertOk()
            ->assertSee('Client Care')
            ->assertSee('name="client_care_target_points"', false);
    }
}
