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
 * The client page's "Agreement" tile reads Pending/Signed. It used to derive
 * that purely from the client's root-level document list, which excludes any
 * document uploaded as a new version of another (via parent_id) — exactly
 * how a signed copy is normally uploaded: as a new version of the original,
 * unsigned agreement. That left the tile stuck on Pending forever once a
 * client's agreement had ever been through that "replace with signed copy"
 * flow. Fixed by computing it server-side across every version, matched by
 * the document type's slug rather than its (renameable) display name.
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

    public function test_agreement_status_is_pending_with_no_signed_agreement_uploaded(): void
    {
        $client = $this->client();
        $this->agreementType();

        $response = $this->actingAs($this->staff())->getJson(route('clients.documents.index', $client));

        $response->assertOk();
        $response->assertJson(['hasSignedAgreement' => false]);
    }

    public function test_agreement_status_is_signed_when_uploaded_directly(): void
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
        $response->assertJson(['hasSignedAgreement' => true]);
    }

    /**
     * The exact bug: the signed copy uploaded as a NEW VERSION of the
     * original agreement — a parent_id row, excluded from the root-only
     * documents list the tile used to read from.
     */
    public function test_agreement_status_is_signed_when_uploaded_as_a_new_version_of_the_original(): void
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

        // Before the signed copy: still Pending.
        $this->actingAs($staff)->getJson(route('clients.documents.index', $client))
            ->assertJson(['hasSignedAgreement' => false]);

        // Replace it with the signed copy — a new version, not a fresh document.
        $this->actingAs($staff)->postJson(route('clients.documents.store', $client), [
            'document_type_id' => $signed->id, 'title' => 'Client Agreement', 'parent_id' => $original['id'],
            'file' => UploadedFile::fake()->create('agreement-signed.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        // The root list still only shows the original — confirms this is
        // genuinely testing the excluded-from-root-list case, not a fluke.
        $index = $this->actingAs($staff)->getJson(route('clients.documents.index', $client));
        $this->assertSame(1, $index->json('total'));

        $index->assertJson(['hasSignedAgreement' => true]);
    }

    public function test_agreement_status_is_not_fooled_by_the_plain_agreement_type_alone(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $plain = $this->agreementType();
        $this->signedAgreementType();
        $staff = $this->staff();

        $this->actingAs($staff)->postJson(route('clients.documents.store', $client), [
            'document_type_id' => $plain->id, 'title' => 'Client Agreement',
            'file' => UploadedFile::fake()->create('agreement.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $response = $this->actingAs($staff)->getJson(route('clients.documents.index', $client));
        $response->assertJson(['hasSignedAgreement' => false]);
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
        $response->assertJson(['hasSignedAgreement' => true]);
    }
}
