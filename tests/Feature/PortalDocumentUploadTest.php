<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientPortalUser;
use App\Models\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A client used to be able to submit exactly one document per upload — a
 * batch of five meant five trips through the modal. The form now accepts
 * several files in one submission; title is optional and falls back to each
 * file's own name, or is numbered per file when several are uploaded under
 * one shared title.
 */
class PortalDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

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

    private function portalUser(Client $client): ClientPortalUser
    {
        return ClientPortalUser::create([
            'client_id' => $client->id,
            'name'      => 'Portal User',
            'email'     => 'portal-' . uniqid() . '@example.com',
            'password'  => 'Password123!',
            'status'    => 'Active',
        ]);
    }

    private function submittableType(): DocumentType
    {
        return DocumentType::create([
            'name' => 'Test Type ' . uniqid(), 'is_active' => true, 'is_client_submittable' => true, 'sort_order' => 1,
        ]);
    }

    /**
     * Deliberately not actingAs($portal, 'client_portal') — that helper also
     * calls Auth::shouldUse('client_portal'), which would make the service's
     * unguarded Auth::id() call (used for the staff-side uploaded_by column)
     * resolve to the portal user's own id instead of null, tripping the
     * users FK. Same reasoning as ClientPortalIsolationTest.
     */
    private function loginAsPortal(ClientPortalUser $portal): void
    {
        $this->app['auth']->guard('client_portal')->login($portal);
    }

    public function test_a_client_can_submit_several_documents_in_one_request(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $portal = $this->portalUser($client);
        $type   = $this->submittableType();
        $this->loginAsPortal($portal);

        $this->post(route('portal.documents.store'), [
            'document_type_id' => $type->id,
            'files' => [
                UploadedFile::fake()->create('front.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('back.pdf', 100, 'application/pdf'),
            ],
        ])->assertRedirect(route('portal.documents.index'));

        $docs = ClientDocument::where('client_id', $client->id)->orderBy('id')->get();
        $this->assertCount(2, $docs);
        $this->assertSame(['front', 'back'], $docs->pluck('title')->all());
        $this->assertTrue($docs->every(fn ($d) => $d->is_client_submitted));
        $this->assertTrue($docs->every(fn ($d) => $d->client_review_status === 'Pending Review'));
        $this->assertTrue($docs->every(fn ($d) => $d->submitted_by_portal_user_id === $portal->id));
        // Portal-submitted, not staff-submitted — attributing it to nobody, not to whoever happened to be signed in.
        $this->assertTrue($docs->every(fn ($d) => $d->uploaded_by === null));
    }

    public function test_a_blank_title_falls_back_to_each_files_own_name(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $portal = $this->portalUser($client);
        $type   = $this->submittableType();
        $this->loginAsPortal($portal);

        $this->post(route('portal.documents.store'), [
            'document_type_id' => $type->id,
            'title' => '',
            'files' => [UploadedFile::fake()->create('passport-scan.pdf', 100, 'application/pdf')],
        ])->assertRedirect();

        $doc = ClientDocument::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('passport-scan', $doc->title);
    }

    public function test_a_shared_title_is_numbered_across_several_files(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $portal = $this->portalUser($client);
        $type   = $this->submittableType();
        $this->loginAsPortal($portal);

        $this->post(route('portal.documents.store'), [
            'document_type_id' => $type->id,
            'title' => 'Utility Bill',
            'files' => [
                UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
            ],
        ])->assertRedirect();

        $titles = ClientDocument::where('client_id', $client->id)->orderBy('id')->pluck('title')->all();
        $this->assertSame(['Utility Bill (1)', 'Utility Bill (2)', 'Utility Bill (3)'], $titles);
    }

    /** A single file keeps behaving exactly as before — no "(1)" suffix for just one. */
    public function test_a_given_title_on_a_single_file_is_used_verbatim(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $portal = $this->portalUser($client);
        $type   = $this->submittableType();
        $this->loginAsPortal($portal);

        $this->post(route('portal.documents.store'), [
            'document_type_id' => $type->id,
            'title' => 'My NID',
            'files' => [UploadedFile::fake()->create('nid.pdf', 100, 'application/pdf')],
        ])->assertRedirect();

        $doc = ClientDocument::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('My NID', $doc->title);
    }

    public function test_more_than_ten_files_in_one_request_is_rejected(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $portal = $this->portalUser($client);
        $type   = $this->submittableType();

        $files = collect(range(1, 11))->map(fn ($i) => UploadedFile::fake()->create("f{$i}.pdf", 10, 'application/pdf'))->all();
        $this->loginAsPortal($portal);

        $this->post(route('portal.documents.store'), [
            'document_type_id' => $type->id,
            'files' => $files,
        ])->assertSessionHasErrors('files');

        $this->assertSame(0, ClientDocument::where('client_id', $client->id)->count());
    }

    public function test_a_batch_upload_is_still_refused_for_a_non_submittable_type(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $portal = $this->portalUser($client);
        $notSubmittable = DocumentType::create(['name' => 'Internal ' . uniqid(), 'is_active' => true, 'is_client_submittable' => false]);
        $this->loginAsPortal($portal);

        $this->post(route('portal.documents.store'), [
            'document_type_id' => $notSubmittable->id,
            'files' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')],
        ])->assertForbidden();
    }
}
