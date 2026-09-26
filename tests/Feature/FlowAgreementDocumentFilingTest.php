<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\DocumentType;
use App\Models\Flow;
use App\Models\FlowItem;
use App\Models\User;
use App\Services\FlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A file attached while an item sits at its workflow's very first stage is
 * also filed into the client's own Documents tab (Agreement type, or Other
 * if that type has been renamed/retired) — the first stage of any workflow
 * is where a client's agreement gets settled, so whatever's attached there
 * also belongs in their permanent record. It stays a normal workflow
 * attachment too; this only adds a copy, it never redirects it.
 */
class FlowAgreementDocumentFilingTest extends TestCase
{
    use RefreshDatabase;

    private FlowService $flow;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->flow = app(FlowService::class);
        Permission::firstOrCreate(['name' => 'manage workflows', 'guard_name' => 'web']);
    }

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function client(): Client
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'ACME Ltd', 'brand_name' => 'ACME',
            'category_id' => $category->id,
        ]);
    }

    /** A two-stage flow whose item is created (and so starts) at the first stage. */
    private function itemFor(User $worker, ?Client $client): FlowItem
    {
        $admin = tap($this->user())->givePermissionTo('manage workflows');
        $flow  = Flow::create(['name' => 'Onboarding', 'is_active' => true, 'created_by' => $admin->id]);
        $flow->stages()->create(['name' => 'Agreement', 'position' => 1])->users()->sync([$worker->id]);
        $flow->stages()->create(['name' => 'Delivery', 'position' => 2])->users()->sync([$worker->id]);

        $item = $this->flow->createItem($flow->refresh(), [
            'title' => 'Onboard', 'client_id' => $client?->id,
        ], $admin);

        return $this->flow->claim($item->fresh(), $worker);
    }

    public function test_a_file_attached_at_the_first_stage_is_also_filed_under_agreement(): void
    {
        $worker = $this->user();
        $client = $this->client();
        $item   = $this->itemFor($worker, $client);

        $this->actingAs($worker)->postJson(route('flow-items.attachments.store', $item), [
            'kind' => 'file',
            'file' => UploadedFile::fake()->create('contract.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $doc = ClientDocument::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('contract', $doc->title);
        $this->assertSame('agreement', $doc->documentType->slug);
        // Still a real client document — same rules as any other upload.
        $this->assertNotNull($doc->path);
        $this->assertTrue(Storage::disk($doc->disk)->exists($doc->path));
    }

    public function test_the_file_still_shows_up_as_a_normal_workflow_attachment_too(): void
    {
        $worker = $this->user();
        $client = $this->client();
        $item   = $this->itemFor($worker, $client);

        $this->actingAs($worker)->postJson(route('flow-items.attachments.store', $item), [
            'kind' => 'file',
            'file' => UploadedFile::fake()->create('contract.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $this->assertSame(1, $item->attachments()->count());
        $this->assertSame(1, ClientDocument::where('client_id', $client->id)->count());
    }

    public function test_a_custom_title_on_the_attachment_carries_over(): void
    {
        $worker = $this->user();
        $client = $this->client();
        $item   = $this->itemFor($worker, $client);

        $this->actingAs($worker)->postJson(route('flow-items.attachments.store', $item), [
            'kind'  => 'file',
            'title' => 'Signed Master Agreement',
            'file'  => UploadedFile::fake()->create('scan.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $doc = ClientDocument::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('Signed Master Agreement', $doc->title);
    }

    public function test_a_file_attached_after_the_item_has_moved_past_the_first_stage_is_not_filed(): void
    {
        $worker = $this->user();
        $client = $this->client();
        $item   = $this->itemFor($worker, $client);
        // Stays claimed by the same worker at the next stage too, so the
        // attach call below is only testing the stage check, not a claim gate.
        $this->flow->advance($item->fresh(), $worker, null, $worker->id);

        $this->actingAs($worker)->postJson(route('flow-items.attachments.store', $item->fresh()), [
            'kind' => 'file',
            'file' => UploadedFile::fake()->create('progress-shot.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $this->assertSame(0, ClientDocument::where('client_id', $client->id)->count());
    }

    public function test_an_internal_item_with_no_client_is_never_filed(): void
    {
        $worker = $this->user();
        $item   = $this->itemFor($worker, null);

        $this->actingAs($worker)->postJson(route('flow-items.attachments.store', $item), [
            'kind' => 'file',
            'file' => UploadedFile::fake()->create('internal.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $this->assertSame(0, ClientDocument::count());
    }

    public function test_a_link_or_note_attachment_is_never_filed(): void
    {
        $worker = $this->user();
        $client = $this->client();
        $item   = $this->itemFor($worker, $client);

        $this->actingAs($worker)->postJson(route('flow-items.attachments.store', $item), [
            'kind' => 'note', 'body' => 'Signed in person, no scan yet.',
        ])->assertOk();

        $this->assertSame(0, ClientDocument::where('client_id', $client->id)->count());
    }

    public function test_falls_back_to_other_when_the_agreement_type_is_not_active(): void
    {
        DocumentType::where('slug', 'agreement')->update(['is_active' => false]);

        $worker = $this->user();
        $client = $this->client();
        $item   = $this->itemFor($worker, $client);

        $this->actingAs($worker)->postJson(route('flow-items.attachments.store', $item), [
            'kind' => 'file',
            'file' => UploadedFile::fake()->create('contract.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $doc = ClientDocument::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('other', $doc->documentType->slug);
    }
}
