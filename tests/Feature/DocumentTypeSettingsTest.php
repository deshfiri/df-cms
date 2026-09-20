<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientPortalUser;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Settings → Document Types: the list a client's documents are filed under,
 * and what adding, renaming and retiring one does to the upload forms.
 */
class DocumentTypeSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach (['manage document types', 'view clients', 'manage clients', 'manage documents'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function admin(): User
    {
        return tap(User::factory()->create(['is_active' => true]))
            ->givePermissionTo(['manage document types', 'view clients', 'manage clients', 'manage documents'])
            ->fresh();
    }

    private function client(): Client
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'ACME Ltd',
            'brand_name'  => 'ACME',
            'category_id' => $category->id,
        ]);
    }

    private function document(Client $client, DocumentType $type, User $uploader, string $title): ClientDocument
    {
        return ClientDocument::create([
            'client_id' => $client->id, 'document_type_id' => $type->id, 'uploaded_by' => $uploader->id,
            'title' => $title, 'original_name' => 'file.pdf', 'stored_name' => uniqid() . '.pdf',
            'path' => 'docs/' . uniqid() . '.pdf', 'disk' => 'local', 'extension' => 'pdf',
            'mime_type' => 'application/pdf', 'file_size' => 100,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'                  => 'Bank Statement',
            'description'           => 'Last three months',
            'icon'                  => 'bi-bank',
            'is_required'           => 0,
            'is_client_submittable' => 0,
        ], $overrides);
    }

    // ── Who may manage the list ──────────────────────────────────────────

    public function test_only_someone_with_the_permission_can_manage_them(): void
    {
        $nobody = User::factory()->create(['is_active' => true]);
        $type   = DocumentType::create(['name' => 'Existing', 'is_active' => true]);

        $this->actingAs($nobody)->get(route('document-types.index'))->assertForbidden();
        $this->actingAs($nobody)->post(route('document-types.store'), $this->payload())->assertForbidden();
        $this->actingAs($nobody)->putJson(route('document-types.update', $type), ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($nobody)->deleteJson(route('document-types.destroy', $type))->assertForbidden();

        $this->assertSame('Existing', $type->fresh()->name);
        $this->assertDatabaseMissing('document_types', ['name' => 'Bank Statement']);

        $this->actingAs($this->admin())->get(route('document-types.index'))
            ->assertOk()
            ->assertSee('Document Types')
            ->assertSee('Existing');
    }

    // ── Adding ───────────────────────────────────────────────────────────

    public function test_a_new_type_is_added_and_offered_on_the_client_page(): void
    {
        // The client page checks permissions from across the app, and Spatie
        // throws on one that was never created.
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $admin  = $this->admin();
        $client = $this->client();

        $this->actingAs($admin)->postJson(route('document-types.store'), $this->payload())
            ->assertOk()
            ->assertJsonPath('success', true);

        $type = DocumentType::where('name', 'Bank Statement')->firstOrFail();
        $this->assertSame('bank-statement', $type->slug);
        $this->assertSame('bi-bank', $type->icon);
        $this->assertTrue($type->is_active);

        // It reaches the picker on the client's Documents tab.
        $this->actingAs($admin)->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee('Bank Statement');

        // And staff can file a document under it.
        $this->actingAs($admin)->postJson(route('clients.documents.store', $client), [
            'file'             => UploadedFile::fake()->create('statement.pdf', 20, 'application/pdf'),
            'document_type_id' => $type->id,
            'title'            => 'March statement',
        ])->assertCreated();

        $this->assertSame(1, $type->documents()->count());
    }

    public function test_two_types_cannot_share_a_name(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson(route('document-types.store'), $this->payload())->assertOk();

        $this->actingAs($admin)->postJson(route('document-types.store'), $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, DocumentType::where('name', 'Bank Statement')->count());
    }

    public function test_an_icon_has_to_look_like_an_icon(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('document-types.store'), $this->payload(['icon' => '"><script>alert(1)</script>']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('icon');
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('document-types.store'), $this->payload(['name' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    // ── Editing ──────────────────────────────────────────────────────────

    public function test_renaming_keeps_the_documents_and_the_slug(): void
    {
        $admin  = $this->admin();
        $client = $this->client();
        // A name of its own: the app ships with a list of defaults.
        $type   = DocumentType::create(['name' => 'Bank Letter', 'slug' => 'bank-letter', 'is_active' => true]);

        $doc = $this->document($client, $type, $admin, 'Licence');

        $this->actingAs($admin)->putJson(route('document-types.update', $type), [
            'name' => 'Bank Letters', 'sort_order' => 3,
        ])->assertOk();

        $type->refresh();
        $this->assertSame('Bank Letters', $type->name);
        $this->assertSame('bank-letter', $type->slug, 'the slug is left alone on a rename');
        $this->assertSame(3, $type->sort_order);
        $this->assertSame($type->id, $doc->fresh()->document_type_id);
    }

    public function test_the_order_decides_how_they_are_listed(): void
    {
        $admin = $this->admin();
        DocumentType::query()->delete();
        DocumentType::create(['name' => 'Zebra', 'is_active' => true, 'sort_order' => 1]);
        DocumentType::create(['name' => 'Alpha', 'is_active' => true, 'sort_order' => 2]);

        $names = DocumentType::active()->pluck('name')->all();

        $this->assertSame(['Zebra', 'Alpha'], $names);

        $this->actingAs($admin)->putJson(
            route('document-types.update', DocumentType::where('name', 'Alpha')->first()),
            ['sort_order' => 0]
        )->assertOk();

        $this->assertSame(['Alpha', 'Zebra'], DocumentType::active()->pluck('name')->all());
    }

    // ── Retiring ─────────────────────────────────────────────────────────

    public function test_switching_one_off_closes_both_upload_forms(): void
    {
        $admin  = $this->admin();
        $client = $this->client();
        $type   = DocumentType::create(['name' => 'Old Form', 'is_active' => true, 'is_client_submittable' => true]);

        $this->actingAs($admin)->putJson(route('document-types.update', $type), ['is_active' => 0])->assertOk();
        $this->assertFalse($type->fresh()->is_active);

        // Staff can no longer file anything new under it…
        $this->actingAs($admin)->postJson(route('clients.documents.store', $client), [
            'file'             => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
            'document_type_id' => $type->id,
            'title'            => 'Nope',
        ])->assertStatus(422)->assertJsonValidationErrors('document_type_id');

        // …and neither can the client, even posting the id straight at the portal.
        $portalUser = ClientPortalUser::create([
            'client_id' => $client->id, 'name' => 'Portal User',
            'email' => 'portal-' . uniqid() . '@example.com', 'password' => 'Password123!', 'status' => 'Active',
        ]);

        $this->actingAs($portalUser, 'client_portal')->post(route('portal.documents.store'), [
            'document_type_id' => $type->id,
            'title'            => 'Nope',
            'file'             => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertForbidden();

        $this->assertSame(0, $type->documents()->count());
    }

    public function test_only_types_open_to_clients_show_in_the_portal(): void
    {
        $client = $this->client();
        $open   = DocumentType::create(['name' => 'Client Upload', 'is_active' => true, 'is_client_submittable' => true]);
        $staff  = DocumentType::create(['name' => 'Internal Only', 'is_active' => true, 'is_client_submittable' => false]);

        $portalUser = ClientPortalUser::create([
            'client_id' => $client->id, 'name' => 'Portal User',
            'email' => 'portal-' . uniqid() . '@example.com', 'password' => 'Password123!', 'status' => 'Active',
        ]);

        $this->actingAs($portalUser, 'client_portal')->get(route('portal.documents.index'))
            ->assertOk()
            ->assertSee($open->name)
            ->assertDontSee($staff->name);
    }

    public function test_a_type_in_use_cannot_be_deleted(): void
    {
        $admin  = $this->admin();
        $client = $this->client();
        $type   = DocumentType::create(['name' => 'In Use', 'is_active' => true]);

        $this->document($client, $type, $admin, 'Something');

        $this->actingAs($admin)->deleteJson(route('document-types.destroy', $type))
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Switch it off instead'));

        $this->assertDatabaseHas('document_types', ['id' => $type->id]);
    }

    public function test_an_unused_type_can_be_deleted(): void
    {
        $type = DocumentType::create(['name' => 'Never Used', 'is_active' => true]);

        $this->actingAs($this->admin())->deleteJson(route('document-types.destroy', $type))->assertOk();

        $this->assertDatabaseMissing('document_types', ['id' => $type->id]);
    }

    public function test_every_change_is_logged(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('document-types.store'), $this->payload())->assertOk();
        $type = DocumentType::where('name', 'Bank Statement')->firstOrFail();
        $this->actingAs($admin)->putJson(route('document-types.update', $type), ['name' => 'Bank Statements'])->assertOk();
        $this->actingAs($admin)->deleteJson(route('document-types.destroy', $type))->assertOk();

        foreach (['Created', 'Updated', 'Deleted'] as $action) {
            $this->assertDatabaseHas('activity_logs', ['module' => 'Document Type', 'action' => $action]);
        }
    }
}
