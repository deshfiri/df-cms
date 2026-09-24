<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\KpiWeightConfig;
use App\Models\Task;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * HTTP-level coverage for Output Volume: weight configuration and
 * rendering. The scoring formula itself (per-scope math, averaging,
 * company-wide cohorts) is covered in Tests\Unit\OutputVolumeScoringTest.
 */
class OutputVolumePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-09';

    private User $manager;
    private Flow $flow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
        foreach (['view performance', 'manage performance'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->manager = tap(User::factory()->create(['is_active' => true]))
            ->givePermissionTo(['view performance', 'manage performance']);
        $this->flow = Flow::create(['name' => 'Test Flow', 'is_active' => true]);
    }

    private function task(User $assignee): void
    {
        $task = Task::create([
            'title' => 'Task', 'priority' => 'Medium', 'status' => 'Completed', 'type' => 'Other',
            'created_by' => $this->manager->id,
            'due_date' => '2026-09-10', 'completion_date' => '2026-09-10',
        ]);
        $task->assignees()->sync([$assignee->id]);
    }

    private function flowItem(User $assignee): void
    {
        FlowItem::create([
            'flow_id' => $this->flow->id, 'title' => 'Item', 'status' => FlowItem::STATUS_COMPLETED,
            'assigned_to' => $assignee->id, 'created_by' => $this->manager->id,
            'due_date' => '2026-09-10', 'completed_at' => '2026-09-10',
        ]);
    }

    private function clientFor(User $owner): void
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);
        Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'Client ' . uniqid(), 'brand_name' => 'Brand',
            'category_id' => $category->id, 'assigned_to' => $owner->id,
        ]);
    }

    public function test_it_counts_in_the_final_score_with_its_weight(): void
    {
        KpiWeightConfig::create([
            'scope_type' => KpiWeightConfig::SCOPE_GLOBAL,
            'task_completion_weight' => 0, 'on_time_weight' => 0, 'revision_weight' => 0,
            'sales_weight' => 0, 'satisfaction_weight' => 0, 'client_care_weight' => 0,
            'daily_target_weight' => 0, 'output_volume_weight' => 100,
        ]);
        $sam = User::factory()->create(['is_active' => true]);
        $this->flowItem($sam);

        $result = app(PerformanceCalculationService::class)->finalScore($sam, self::PERIOD);

        $this->assertSame(['output_volume' => 100.0], $result['weights_used']);
        $this->assertSame($result['scores']['output_volume'], $result['final_score']);

        // Weighted 0 itself, it is shown but can't move the score.
        KpiWeightConfig::query()->update(['output_volume_weight' => 0]);
        $zero = app(PerformanceCalculationService::class)->finalScore($sam, self::PERIOD);
        $this->assertNotNull($zero['scores']['output_volume']);
        $this->assertNull($zero['final_score']);
    }

    public function test_saving_global_weights_requires_an_output_volume_value(): void
    {
        $this->actingAs($this->manager)->postJson(route('performance.config.weights.store'), [
            'scope_type' => 'global',
            'task_completion_weight' => 20, 'on_time_weight' => 20, 'revision_weight' => 15,
            'sales_weight' => 15, 'satisfaction_weight' => 15, 'client_care_weight' => 15,
            // output_volume_weight omitted
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('output_volume_weight');
    }

    public function test_weights_including_output_volume_must_total_100(): void
    {
        $this->actingAs($this->manager)->postJson(route('performance.config.weights.store'), [
            'scope_type' => 'global',
            'task_completion_weight' => 20, 'on_time_weight' => 20, 'revision_weight' => 15,
            'sales_weight' => 15, 'satisfaction_weight' => 15, 'client_care_weight' => 15,
            'daily_target_weight' => 0, 'output_volume_weight' => 10, // sums to 110
        ])->assertStatus(422);

        $this->actingAs($this->manager)->postJson(route('performance.config.weights.store'), [
            'scope_type' => 'global',
            'task_completion_weight' => 16, 'on_time_weight' => 16, 'revision_weight' => 11,
            'sales_weight' => 10, 'satisfaction_weight' => 9, 'client_care_weight' => 10,
            'daily_target_weight' => 8, 'output_volume_weight' => 10, 'task_giving_weight' => 10, // sums to 100
        ])->assertOk();

        $this->assertSame(10, KpiWeightConfig::where('scope_type', 'global')->value('output_volume_weight'));
    }

    public function test_the_global_weights_form_defaults_already_sum_to_100(): void
    {
        // Regression guard: an earlier version of this form's display
        // defaults didn't match the scoring engine's own fallback and summed
        // to more than 100, disabling the Save button before anyone touched
        // the page.
        $response = $this->actingAs($this->manager)->get(route('performance.config'))->assertOk();

        preg_match_all('/name="(\w+_weight)" value="(\d+)"/', $response->getContent(), $matches, PREG_SET_ORDER);
        $globalFormFields = [
            'task_completion_weight', 'on_time_weight', 'revision_weight', 'sales_weight',
            'satisfaction_weight', 'client_care_weight', 'daily_target_weight', 'output_volume_weight',
            'task_giving_weight',
        ];
        $sum = 0;
        foreach ($matches as $match) {
            if (in_array($match[1], $globalFormFields, true)) {
                $sum += (int) $match[2];
            }
        }

        $this->assertSame(100, $sum);
    }

    public function test_the_scorecard_shows_every_applicable_scope(): void
    {
        $sam = User::factory()->create(['is_active' => true]);
        $this->task($sam);
        $this->flowItem($sam);
        $this->clientFor($sam);

        $this->actingAs($this->manager)->get(route('performance.show', ['user' => $sam, 'period' => self::PERIOD]))
            ->assertOk()
            ->assertSee('Output Volume')
            ->assertSee('Overall volume score')
            ->assertSee('Workflow Items')
            ->assertSee('Client Handling');
    }

    public function test_the_configuration_screen_shows_output_volume(): void
    {
        $this->actingAs($this->manager)->get(route('performance.config'))
            ->assertOk()
            ->assertSee('Output Volume')
            ->assertSee('name="output_volume_weight"', false);
    }

    public function test_the_scoreboard_and_history_render_the_volume_column(): void
    {
        $sam = User::factory()->create(['is_active' => true, 'name' => 'Sam Scoreboard']);
        $this->flowItem($sam);

        $this->actingAs($this->manager)->get(route('performance.index'))
            ->assertOk()
            ->assertSee('Volume')
            ->assertSee('Sam Scoreboard');
    }
}
