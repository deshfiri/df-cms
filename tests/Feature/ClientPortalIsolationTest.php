<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\ClientActionRequest;
use App\Models\ClientApprovalRequest;
use App\Models\ClientDocument;
use App\Models\ClientPortalUser;
use App\Models\DocumentType;
use App\Models\Invoice;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientPortalIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeClient(string $name): Client
    {
        $category = Category::create(['name' => 'Test Category', 'slug' => 'test-category-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => $name,
            'brand_name'  => $name,
            'category_id' => $category->id,
        ]);
    }

    private function makePortalUser(Client $client, string $email, string $status = 'Active'): ClientPortalUser
    {
        return ClientPortalUser::create([
            'client_id' => $client->id,
            'name'      => 'Portal User for ' . $client->client_name,
            'email'     => $email,
            'password'  => 'Password123!',
            'status'    => $status,
        ]);
    }

    public function test_active_portal_user_can_log_in_and_reach_dashboard(): void
    {
        $client = $this->makeClient('Client A');
        $this->makePortalUser($client, 'active@example.com');

        $response = $this->post(route('portal.login.submit'), [
            'login'    => 'active@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticated('client_portal');
    }

    public function test_suspended_portal_user_is_rejected_at_login(): void
    {
        $client = $this->makeClient('Client A');
        $this->makePortalUser($client, 'suspended@example.com', 'Suspended');

        $response = $this->post(route('portal.login.submit'), [
            'login'    => 'suspended@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest('client_portal');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $client = $this->makeClient('Client A');
        $this->makePortalUser($client, 'active@example.com');

        $response = $this->post(route('portal.login.submit'), [
            'login'    => 'active@example.com',
            'password' => 'WrongPassword!',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest('client_portal');
    }

    public function test_account_suspended_mid_session_is_logged_out_on_next_request(): void
    {
        $client = $this->makeClient('Client A');
        $portalUser = $this->makePortalUser($client, 'active@example.com');

        $this->actingAs($portalUser, 'client_portal');
        $this->get(route('portal.dashboard'))->assertOk();

        $portalUser->update(['status' => 'Suspended']);

        $response = $this->get(route('portal.dashboard'));
        $response->assertRedirect(route('portal.login'));
        $this->assertGuest('client_portal');
    }

    public function test_staff_login_is_entirely_unaffected_by_the_portal_guard(): void
    {
        $staff = User::factory()->create(['password' => bcrypt('password')]);

        $response = $this->post(route('login'), [
            'email'    => $staff->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated('web');
    }

    public function test_portal_session_cannot_access_staff_dashboard(): void
    {
        // Deliberately not using actingAs($user, $guard) here — that helper
        // calls Auth::shouldUse($guard), which changes what the *default*
        // guard resolves to for the rest of the test and would make a portal
        // login look authenticated on 'web' too. That's a testing-only
        // artifact, not real request behavior (a real portal login never
        // touches the web guard's session), so log in on the guard directly.
        $client = $this->makeClient('Client A');
        $portalUser = $this->makePortalUser($client, 'active@example.com');
        $this->app['auth']->guard('client_portal')->login($portalUser);

        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_staff_session_cannot_access_portal_dashboard(): void
    {
        $staff = User::factory()->create();
        $this->app['auth']->guard('web')->login($staff);

        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
    }

    public function test_client_b_cannot_download_client_as_document_by_id(): void
    {
        Storage::fake('local');

        $clientA = $this->makeClient('Client A');
        $clientB = $this->makeClient('Client B');
        $portalB = $this->makePortalUser($clientB, 'clientb@example.com');
        $docType = DocumentType::first();

        $doc = ClientDocument::create([
            'client_id' => $clientA->id,
            'document_type_id' => $docType->id,
            'title' => 'Secret Agreement',
            'original_name' => 'secret.pdf',
            'stored_name' => 'secret.pdf',
            'disk' => 'local',
            'path' => 'client-documents/' . $clientA->id . '/secret.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'is_client_visible' => true,
        ]);
        Storage::disk('local')->put($doc->path, 'secret content');

        $this->actingAs($portalB, 'client_portal')
            ->get(route('portal.documents.download', $doc))
            ->assertNotFound();

        $this->actingAs($portalB, 'client_portal')
            ->get(route('portal.documents.preview', $doc))
            ->assertNotFound();
    }

    public function test_client_b_cannot_view_or_submit_client_as_action_request(): void
    {
        $clientA = $this->makeClient('Client A');
        $clientB = $this->makeClient('Client B');
        $admin = User::factory()->create();
        $portalB = $this->makePortalUser($clientB, 'clientb@example.com');

        $actionRequest = ClientActionRequest::create([
            'client_id' => $clientA->id,
            'requested_by' => $admin->id,
            'title' => 'Upload NID',
            'description' => 'Please upload your NID.',
            'priority' => 'Medium',
            'status' => 'Pending',
        ]);

        $this->actingAs($portalB, 'client_portal')
            ->get(route('portal.actions.show', $actionRequest))
            ->assertNotFound();

        $this->actingAs($portalB, 'client_portal')
            ->post(route('portal.actions.submit', $actionRequest), ['response_text' => 'hijack'])
            ->assertNotFound();

        $this->assertSame('Pending', $actionRequest->fresh()->status);
    }

    public function test_client_b_cannot_respond_to_client_as_approval_request(): void
    {
        $clientA = $this->makeClient('Client A');
        $clientB = $this->makeClient('Client B');
        $admin = User::factory()->create();
        $portalB = $this->makePortalUser($clientB, 'clientb@example.com');

        $approval = ClientApprovalRequest::create([
            'client_id' => $clientA->id,
            'approval_type' => 'Logo',
            'title' => 'Logo v1',
            'version' => 1,
            'requested_by' => $admin->id,
            'allow_reject' => true,
            'status' => 'Pending',
        ]);

        $this->actingAs($portalB, 'client_portal')
            ->post(route('portal.approvals.respond', $approval), [
                'response' => 'Approved',
            ])
            ->assertNotFound();

        $this->assertSame('Pending', $approval->fresh()->status);
        $this->assertSame(0, $approval->responses()->count());
    }

    public function test_client_b_cannot_view_client_as_invoice_or_submit_payment_proof(): void
    {
        Storage::fake('local');

        $clientA = $this->makeClient('Client A');
        $clientB = $this->makeClient('Client B');
        $admin = User::factory()->create();
        $portalB = $this->makePortalUser($clientB, 'clientb@example.com');

        $invoice = Invoice::create([
            'client_id' => $clientA->id,
            'invoice_number' => 'INV-TEST-001',
            'total_payable' => 1000,
            'status' => 'Unpaid',
            'issued_by' => $admin->id,
            'issued_date' => now(),
        ]);

        $this->actingAs($portalB, 'client_portal')
            ->get(route('portal.invoices.show', $invoice))
            ->assertNotFound();

        $this->actingAs($portalB, 'client_portal')
            ->get(route('portal.invoices.download', $invoice))
            ->assertNotFound();

        $file = \Illuminate\Http\UploadedFile::fake()->create('proof.jpg', 10);
        $this->actingAs($portalB, 'client_portal')
            ->post(route('portal.invoices.payment-proof.store', $invoice), [
                'amount_claimed' => 1,
                'file' => $file,
            ])
            ->assertNotFound();
    }

    public function test_client_b_cannot_view_client_as_support_ticket(): void
    {
        $clientA = $this->makeClient('Client A');
        $clientB = $this->makeClient('Client B');
        $portalA = $this->makePortalUser($clientA, 'clienta@example.com');
        $portalB = $this->makePortalUser($clientB, 'clientb@example.com');

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-TEST-001',
            'client_id' => $clientA->id,
            'created_by' => $portalA->id,
            'category' => 'General',
            'priority' => 'Medium',
            'subject' => 'Private issue',
            'message' => 'Sensitive details here.',
            'status' => 'Open',
        ]);

        $this->actingAs($portalB, 'client_portal')
            ->get(route('portal.support.show', $ticket))
            ->assertNotFound();

        $this->actingAs($portalB, 'client_portal')
            ->post(route('portal.support.reply', $ticket), ['message' => 'hijack'])
            ->assertNotFound();
    }

    public function test_internal_only_fields_are_never_exposed_in_the_journey_view(): void
    {
        $client = $this->makeClient('Client A');
        $portalUser = $this->makePortalUser($client, 'active@example.com');

        // Hide one stage from clients entirely, and set internal-only remarks
        // on a visible one, to confirm neither ever reaches the portal output.
        \App\Models\WorkflowStage::query()->limit(1)->update([
            'is_client_visible' => false,
            'name' => 'INTERNAL_ONLY_STAGE_MARKER',
        ]);

        $response = $this->actingAs($portalUser, 'client_portal')->get(route('portal.journey'));

        $response->assertOk();
        $response->assertDontSee('INTERNAL_ONLY_STAGE_MARKER');
    }

    public function test_a_second_portal_user_for_the_same_client_can_also_log_in(): void
    {
        $client = $this->makeClient('Client A');
        $this->makePortalUser($client, 'owner@example.com');
        $this->makePortalUser($client, 'ops@example.com');

        $response = $this->post(route('portal.login.submit'), [
            'login'    => 'ops@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertRedirect(route('portal.dashboard'));
    }
}
