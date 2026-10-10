<?php

namespace Tests\Unit;

use App\Http\Requests\Client\UpdateCustomerReasonRequest;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Client;
use App\Models\User;
use App\Services\ClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Customer Reason has three ways in — the list's inline edit, the edit form
 * and the create form — and all of them meet in ClientService. These pin the
 * shared rules: trimmed, blank means none, and only a real change is logged.
 */
class ClientServiceCustomerReasonTest extends TestCase
{
    use RefreshDatabase;

    private ClientService $service;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ClientService::class);

        // Super Admin, so the edit path's change-approval guard lets edits through.
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        Auth::login($this->admin);
    }

    private function makeClient(?string $reason = null): Client
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'ACME', 'brand_name' => 'ACME',
            'category_id' => $category->id, 'client_status' => 'Running', 'customer_reason' => $reason,
        ]);
    }

    private function reasonLogs(Client $client)
    {
        return ActivityLog::where('client_id', $client->id)->where('action', 'Customer Reason Changed')->orderBy('id')->get();
    }

    public function test_whitespace_only_reason_is_stored_as_null(): void
    {
        $client = $this->makeClient('Old');

        $this->service->updateCustomerReason($client, "   \n\t ");

        $this->assertNull($client->fresh()->customer_reason);
    }

    public function test_inner_text_and_line_breaks_are_kept(): void
    {
        $client = $this->makeClient();

        $this->service->updateCustomerReason($client, "  Line one\nLine two  ");

        $this->assertSame("Line one\nLine two", $client->fresh()->customer_reason);
    }

    public function test_unchanged_reason_is_a_no_op(): void
    {
        $client = $this->makeClient('Same');
        $stamp  = $client->updated_at;

        $this->travel(5)->minutes();
        $returned = $this->service->updateCustomerReason($client, '  Same  ');

        $this->assertSame($client, $returned);
        $this->assertEquals($stamp, $client->fresh()->updated_at, 'nothing was written');
        $this->assertCount(0, $this->reasonLogs($client));
    }

    public function test_blank_to_blank_is_not_logged(): void
    {
        $client = $this->makeClient(null);

        $this->service->updateCustomerReason($client, '');
        $this->service->updateCustomerReason($client->fresh(), null);

        $this->assertCount(0, $this->reasonLogs($client));
    }

    public function test_each_real_change_is_logged_in_order_with_old_and_new(): void
    {
        $client = $this->makeClient();

        $this->service->updateCustomerReason($client, 'A');
        $this->service->updateCustomerReason($client->fresh(), 'B');
        $this->service->updateCustomerReason($client->fresh(), '');

        $logs = $this->reasonLogs($client);
        $this->assertSame([[null, 'A'], ['A', 'B'], ['B', null]], $logs->map(fn ($l) => [$l->old_value, $l->new_value])->all());
        $this->assertTrue($logs->every(fn ($l) => $l->user_id === $this->admin->id && $l->module === 'Client'));
    }

    public function test_update_credits_the_given_actor(): void
    {
        // An approved change replayed by a manager is credited to whoever made it.
        $requester = User::factory()->create();
        $client    = $this->makeClient();

        $this->service->update($client, ['customer_reason' => 'From requester'], $requester);

        $this->assertSame($requester->id, $this->reasonLogs($client)->sole()->user_id);
    }

    public function test_update_without_the_key_leaves_reason_untouched_and_unlogged(): void
    {
        $client = $this->makeClient('Keep');

        $this->service->update($client, ['client_name' => 'Renamed']);

        $this->assertSame('Keep', $client->fresh()->customer_reason);
        $this->assertCount(0, $this->reasonLogs($client));
    }

    public function test_create_trims_and_logs_the_initial_reason(): void
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        $client = $this->service->create([
            'client_name' => 'New', 'brand_name' => 'New', 'category_id' => $category->id,
            'client_status' => 'Running', 'customer_reason' => '  First  ',
        ]);

        $this->assertSame('First', $client->fresh()->customer_reason);
        $log = $this->reasonLogs($client)->sole();
        $this->assertNull($log->old_value);
        $this->assertSame('First', $log->new_value);
    }

    public function test_create_with_a_blank_reason_stores_null_and_logs_nothing(): void
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        $client = $this->service->create([
            'client_name' => 'New', 'brand_name' => 'New', 'category_id' => $category->id,
            'client_status' => 'Running', 'customer_reason' => '   ',
        ]);

        $this->assertNull($client->fresh()->customer_reason);
        $this->assertCount(0, $this->reasonLogs($client));
    }

    public function test_shared_validation_rules(): void
    {
        $rules = ['customer_reason' => UpdateCustomerReasonRequest::customerReasonRules()];
        $passes = fn ($value) => Validator::make(['customer_reason' => $value], $rules)->passes();

        $this->assertTrue($passes(null));
        $this->assertTrue($passes(str_repeat('a', 1000)));
        $this->assertTrue($passes('বাজেট কম')); // non-ASCII text counts characters, not bytes
        $this->assertFalse($passes(str_repeat('a', 1001)));
        $this->assertFalse($passes(['not', 'a', 'string']));
    }
}
