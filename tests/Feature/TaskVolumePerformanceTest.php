<?php

namespace Tests\Feature;

use App\Models\KpiWeightConfig;
use App\Models\Task;
use App\Models\User;
use App\Services\Performance\PerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * HTTP-level coverage for Task Volume: weight configuration and rendering.
 * The scoring formula itself is covered in Tests\Unit\TaskVolumeScoringTest.
 */
class TaskVolumePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-09';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
        foreach (['view performance', 'manage performance'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->manager = tap(User::factory()->create(['is_active' => true]))
            ->givePermissionTo(['view performance', 'manage performance']);
    }

    private function task(User $assignee): void
    {
        Task::create([
            'title' => 'Task', 'priority' => 'Medium', 'status' => 'Completed', 'type' => 'Other',
            'assigned_to' => $assignee->id, 'created_by' => $this->manager->id,
            'due_date' => '2026-09-10', 'completion_date' => '2026-09-10',
        ]);
    }

    public function test_it_counts_in_the_final_score_with_its_weight(): void
    {
        KpiWeightConfig::create([
            'scope_type' => KpiWeightConfig::SCOPE_GLOBAL,
            'task_completion_weight' => 0, 'on_time_weight' => 0, 'revision_weight' => 0,
            'sales_weight' => 0, 'satisfaction_weight' => 0, 'client_care_weight' => 0,
            'daily_target_weight' => 0, 'task_volume_weight' => 100,
        ]);
        $sam = User::factory()->create(['is_active' => true]);
        $this->task($sam);

        $result = app(PerformanceCalculationService::class)->finalScore($sam, self::PERIOD);

        $this->assertSame(['task_volume' => 100.0], $result['weights_used']);
        $this->assertSame($result['scores']['task_volume'], $result['final_score']);

        // Weighted 0 itself, it is shown but can't move the score.
        KpiWeightConfig::query()->update(['task_volume_weight' => 0]);
        $zero = app(PerformanceCalculationService::class)->finalScore($sam, self::PERIOD);
        $this->assertNotNull($zero['scores']['task_volume']);
        $this->assertNull($zero['final_score']);
    }

    public function test_saving_global_weights_requires_a_task_volume_value(): void
    {
        $this->actingAs($this->manager)->postJson(route('performance.config.weights.store'), [
            'scope_type' => 'global',
            'task_completion_weight' => 20, 'on_time_weight' => 20, 'revision_weight' => 15,
            'sales_weight' => 15, 'satisfaction_weight' => 15, 'client_care_weight' => 15,
            // task_volume_weight omitted
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('task_volume_weight');
    }

    public function test_weights_including_task_volume_must_total_100(): void
    {
        $this->actingAs($this->manager)->postJson(route('performance.config.weights.store'), [
            'scope_type' => 'global',
            'task_completion_weight' => 20, 'on_time_weight' => 20, 'revision_weight' => 15,
            'sales_weight' => 15, 'satisfaction_weight' => 15, 'client_care_weight' => 15,
            'daily_target_weight' => 0, 'task_volume_weight' => 10, // sums to 110
        ])->assertStatus(422);

        $this->actingAs($this->manager)->postJson(route('performance.config.weights.store'), [
            'scope_type' => 'global',
            'task_completion_weight' => 18, 'on_time_weight' => 18, 'revision_weight' => 12,
            'sales_weight' => 11, 'satisfaction_weight' => 11, 'client_care_weight' => 11,
            'daily_target_weight' => 9, 'task_volume_weight' => 10, // sums to 100
        ])->assertOk();

        $this->assertSame(10, KpiWeightConfig::where('scope_type', 'global')->value('task_volume_weight'));
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
            'satisfaction_weight', 'client_care_weight', 'daily_target_weight', 'task_volume_weight',
        ];
        $sum = 0;
        foreach ($matches as $match) {
            if (in_array($match[1], $globalFormFields, true)) {
                $sum += (int) $match[2];
            }
        }

        $this->assertSame(100, $sum);
    }

    public function test_the_scorecard_and_configuration_show_task_volume(): void
    {
        $sam = User::factory()->create(['is_active' => true]);
        $this->task($sam);

        $this->actingAs($this->manager)->get(route('performance.show', ['user' => $sam, 'period' => self::PERIOD]))
            ->assertOk()
            ->assertSee('Task Volume')
            ->assertSee('Volume score');

        $this->actingAs($this->manager)->get(route('performance.config'))
            ->assertOk()
            ->assertSee('Task Volume')
            ->assertSee('name="task_volume_weight"', false);
    }

    public function test_the_scoreboard_and_history_render_the_volume_column(): void
    {
        $sam = User::factory()->create(['is_active' => true, 'name' => 'Sam Scoreboard']);
        $this->task($sam);

        $this->actingAs($this->manager)->get(route('performance.index'))
            ->assertOk()
            ->assertSee('Volume')
            ->assertSee('Sam Scoreboard');
    }
}
