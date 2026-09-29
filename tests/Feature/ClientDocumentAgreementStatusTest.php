<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The client page's "Agreement" tile reads Pending/Approved.
 *
 * Two things had to be fixed here, discovered one after the other against a
 * real client's data:
 *
 * 1. It used to derive the status purely from the client's root-level
 *    document list, which excludes any document uploaded as a new version of
 *    another (via parent_id) — a common way to replace an earlier copy. That
 *    left the tile stuck on Pending forever once a client's agreement had
 *    gone through that "upload a replacement copy" flow, no matter how many
 *    documents existed. Fixed by computing it server-side across every
 *    version, not just the current one.
 *
 * 2. The real "Agreement and Formalities" workflow stage uploads its
 *    document under the plain "Agreement" document type, not "Signed
 *    Agreement" — a document type that turned out not to be what the actual
 *    workflow ever produces. The check originally required "Signed
 *    Agreement" specifically, so a completely normal, correctly-typed
 *    upload from the real workflow never satisfied it. Fixed by accepting
 *    either type — both represent the agreement being on file.
 *
 * Both are matched by the document type's slug, not its display name, since
 * a type can be renamed from Settings while its slug stays fixed.
 */
class ClientDocumentAgreementStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['view clients', 'manage clients'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }

    private function client(): Client
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'Test Client', 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
    }

    private function staff(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['view clients', 'manage clients']);

        return $user;
    }

    // Both are already seeded by the document_types table's own migration.
    private function agreementType(): DocumentType
    {
        return DocumentType::firstOrCreate(['slug' => 'agreement'], ['name' => 'Agreement', 'is_active' => true, 'sort_order' => 1]);
    }

    private function signedAgreementType(): DocumentType
    {
        return DocumentType::firstOrCreate(['slug' => 'signed-agreement'], ['name' => 'Signed Agreement', 'is_active' => true, 'sort_order' => 2]);
    }

    public function test_agreement_status_is_pending_with_nothing_uploaded(): void
    {
        $client = $this->client();
        $this->agreementType();

        $response = $this->actingAs($this->staff())->getJson(route('clients.documents.index', $client));

        $response->assertOk();
        $response->assertJson(['hasApprovedAgreement' => false]);
    }

    /**
     * The real-world case: the "Agreement and Formalities" workflow stage
     * uploads under the plain "Agreement" type — not "Signed Agreement" —
     * and that alone must be enough to mark the status Approved.
     */
    public function test_agreement_status_is_approved_when_uploaded_under_the_plain_agreement_type(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $plain = $this->agreementType();
        $staff = $this->staff();

        $this->actingAs($staff)->postJson(route('clients.documents.store', $client), [
            'document_type_id' => $plain->id, 'title' => 'Client Agreement',
            'file' => UploadedFile::fake()->create('agreement.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $response = $this->actingAs($staff)->getJson(route('clients.documents.index', $client));
        $response->assertJson(['hasApprovedAgreement' => true]);
    }

    public function test_agreement_status_is_approved_when_uploaded_under_signed_agreement_too(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $signed = $this->signedAgreementType();
        $staff = $this->staff();

        $this->actingAs($staff)->postJson(route('clients.documents.store', $client), [
            'document_type_id' => $signed->id, 'title' => 'Signed Agreement',
            'file' => UploadedFile::fake()->create('signed.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $response = $this->actingAs($staff)->getJson(route('clients.documents.index', $client));
        $response->assertJson(['hasApprovedAgreement' => true]);
    }

    /**
     * The first bug found: a replacement copy uploaded as a NEW VERSION of
     * the original — a parent_id row, excluded from the root-only documents
     * list the tile used to read from.
     */
    public function test_agreement_status_is_approved_when_uploaded_as_a_new_version_of_the_original(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $plain = $this->agreementType();
        $signed = $this->signedAgreementType();
        $staff = $this->staff();

        $original = $this->actingAs($staff)->postJson(route('clients.documents.store', $client), [
            'document_type_id' => $plain->id, 'title' => 'Client Agreement',
            'file' => UploadedFile::fake()->create('agreement.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('document');

        // Before the replacement: already Approved, since the original was
        // itself a plain "Agreement" upload — this only isolates the
        // version-nesting behaviour, not the type-matching fixed above.
        $this->actingAs($staff)->getJson(route('clients.documents.index', $client))
            ->assertJson(['hasApprovedAgreement' => true]);

        // Replace it with a signed copy — a new version, not a fresh document.
        $this->actingAs($staff)->postJson(route('clients.documents.store', $client), [
            'document_type_id' => $signed->id, 'title' => 'Client Agreement', 'parent_id' => $original['id'],
            'file' => UploadedFile::fake()->create('agreement-signed.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        // The root list still only shows the original — confirms this is
        // genuinely testing the excluded-from-root-list case, not a fluke.
        $index = $this->actingAs($staff)->getJson(route('clients.documents.index', $client));
        $this->assertSame(1, $index->json('total'));

        $index->assertJson(['hasApprovedAgreement' => true]);
    }

    public function test_agreement_status_is_not_fooled_by_an_unrelated_document_type(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $invoice = DocumentType::firstOrCreate(['slug' => 'invoice'], ['name' => 'Invoice', 'is_active' => true, 'sort_order' => 3]);
        $this->agreementType();
        $this->signedAgreementType();
        $staff = $this->staff();

        $this->actingAs($staff)->postJson(route('clients.documents.store', $client), [
            'document_type_id' => $invoice->id, 'title' => 'Hosting Invoice',
            'file' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $response = $this->actingAs($staff)->getJson(route('clients.documents.index', $client));
        $response->assertJson(['hasApprovedAgreement' => false]);
    }

    public function test_agreement_status_survives_the_document_type_being_renamed(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $signed = $this->signedAgreementType();
        $staff = $this->staff();

        $this->actingAs($staff)->postJson(route('clients.documents.store', $client), [
            'document_type_id' => $signed->id, 'title' => 'Signed Agreement',
            'file' => UploadedFile::fake()->create('signed.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        // Renaming the type in Settings changes only the display name — the
        // slug (what the check is keyed on) stays fixed.
        $signed->update(['name' => 'Executed Agreement']);

        $response = $this->actingAs($staff)->getJson(route('clients.documents.index', $client));
        $response->assertJson(['hasApprovedAgreement' => true]);
    }
}
