<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The ten security requirements from WHATSAPP_INTEGRATION_SPEC.md §55.
 *
 * Each test is named for the requirement it covers. These are the tests that
 * must never be weakened to make a feature work.
 */
class WhatsAppSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'view whatsapp', 'reply whatsapp', 'assign whatsapp', 'view all whatsapp',
            'manage whatsapp numbers', 'manage whatsapp templates', 'manage whatsapp settings',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Super Admin');

        return $user->fresh();
    }

    private function brand(string $name = 'Brand A'): Brand
    {
        $category = Category::create(['name' => 'C' . uniqid(), 'slug' => 'c-' . uniqid(), 'status' => true]);
        $client = Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => 'Client for ' . $name,
            'brand_name'  => $name,
            'category_id' => $category->id,
        ]);

        return Brand::create(['client_id' => $client->id, 'name' => $name]);
    }

    private function account(Brand $brand, string $phoneNumberId = null): WhatsAppAccount
    {
        $account = new WhatsAppAccount([
            'brand_id'             => $brand->id,
            'waba_id'              => 'waba_' . uniqid(),
            'phone_number_id'      => $phoneNumberId ?: ('pn_' . uniqid()),
            'display_phone_number' => '+8801700000000',
            'status'               => WhatsAppAccount::STATUS_CONNECTED,
        ]);
        $account->setAccessToken('test-token-value');
        $account->save();

        return $account;
    }

    private function conversation(WhatsAppAccount $account, ?User $assignee = null): WhatsAppConversation
    {
        $contact = WhatsAppContact::create(['wa_id' => '88017' . random_int(10000000, 99999999), 'profile_name' => 'Customer']);

        return WhatsAppConversation::create([
            'brand_id'                 => $account->brand_id,
            'whatsapp_account_id'      => $account->id,
            'whatsapp_contact_id'      => $contact->id,
            'assigned_user_id'         => $assignee?->id,
            'status'                   => WhatsAppConversation::STATUS_OPEN,
            'last_customer_message_at' => now(),
        ]);
    }

    // ── §55 Test 1 ───────────────────────────────────────────────────────

    public function test_a_user_without_whatsapp_permission_cannot_open_the_inbox(): void
    {
        $outsider = $this->user();   // no WhatsApp permissions at all

        $this->actingAs($outsider)->get(route('whatsapp.inbox'))->assertForbidden();
        $this->actingAs($outsider)->getJson(route('whatsapp.conversations'))->assertForbidden();
    }

    public function test_a_viewer_can_open_the_inbox(): void
    {
        $agent = $this->user('view whatsapp');

        $this->actingAs($agent)->get(route('whatsapp.inbox'))->assertOk();
    }

    // ── §55 Test 2 ───────────────────────────────────────────────────────

    public function test_view_permission_alone_cannot_send_a_message(): void
    {
        $agent = $this->user('view whatsapp');
        $conversation = $this->conversation($this->account($this->brand()), $agent);

        // They can read it...
        $this->actingAs($agent)->getJson(route('whatsapp.conversation', $conversation))
            ->assertOk()
            ->assertJsonPath('can_reply', false);

        // ...but not answer.
        $this->actingAs($agent)
            ->postJson(route('whatsapp.send', $conversation), ['body' => 'hello'])
            ->assertForbidden();

        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    public function test_reply_permission_allows_sending(): void
    {
        Queue::fake();

        $agent = $this->user('view whatsapp', 'reply whatsapp');
        $conversation = $this->conversation($this->account($this->brand()), $agent);

        $this->actingAs($agent)
            ->postJson(route('whatsapp.send', $conversation), ['body' => 'hello'])
            ->assertOk();

        $this->assertDatabaseHas('whatsapp_messages', [
            'whatsapp_conversation_id' => $conversation->id,
            'direction'                => WhatsAppMessage::DIRECTION_OUT,
            'body'                     => 'hello',
        ]);
    }

    // ── §55 Test 3 ───────────────────────────────────────────────────────

    public function test_an_agent_cannot_reach_another_agents_conversation(): void
    {
        $agentA = $this->user('view whatsapp', 'reply whatsapp');
        $agentB = $this->user('view whatsapp', 'reply whatsapp');

        $theirs = $this->conversation($this->account($this->brand()), $agentB);

        $this->actingAs($agentA)->getJson(route('whatsapp.conversation', $theirs))->assertForbidden();
        $this->actingAs($agentA)->postJson(route('whatsapp.send', $theirs), ['body' => 'x'])->assertForbidden();
    }

    public function test_an_unassigned_conversation_is_invisible_to_an_ordinary_agent(): void
    {
        $agent = $this->user('view whatsapp');
        $orphan = $this->conversation($this->account($this->brand()));   // nobody assigned

        $this->actingAs($agent)->getJson(route('whatsapp.conversation', $orphan))->assertForbidden();

        $this->actingAs($agent)->getJson(route('whatsapp.conversations'))
            ->assertOk()
            ->assertJsonCount(0, 'conversations');
    }

    // ── §55 Test 4 ───────────────────────────────────────────────────────

    public function test_guessing_a_conversation_id_does_not_bypass_authorization(): void
    {
        $agent = $this->user('view whatsapp', 'reply whatsapp');
        $mine  = $this->conversation($this->account($this->brand('Mine')), $agent);
        $other = $this->conversation($this->account($this->brand('Other')));

        // Walking the ids finds nothing extra.
        for ($id = 1; $id <= $other->id + 5; $id++) {
            $response = $this->actingAs($agent)->getJson('/whatsapp/conversations/' . $id);

            $id === $mine->id
                ? $response->assertOk()
                : $this->assertContains($response->status(), [403, 404], "Conversation {$id} leaked");
        }
    }

    // ── §55 Test 5 ───────────────────────────────────────────────────────

    public function test_a_brand_id_in_the_request_cannot_widen_what_is_returned(): void
    {
        $agent = $this->user('view whatsapp');
        $brandA = $this->brand('A');
        $brandB = $this->brand('B');

        $this->conversation($this->account($brandA), $agent);   // theirs
        $this->conversation($this->account($brandB));           // someone else's brand

        // Asking for brand B returns nothing rather than brand B's conversations.
        $this->actingAs($agent)
            ->getJson(route('whatsapp.conversations', ['brand_id' => $brandB->id]))
            ->assertOk()
            ->assertJsonCount(0, 'conversations');
    }

    // ── §55 Test 6 ───────────────────────────────────────────────────────

    /**
     * The send endpoint has no parameter for a number, an account or a brand —
     * they are derived from the conversation. Extra fields are simply ignored.
     */
    public function test_a_phone_number_id_in_the_payload_cannot_change_the_sending_account(): void
    {
        Queue::fake();

        $agent = $this->user('view whatsapp', 'reply whatsapp');

        $mine     = $this->account($this->brand('Mine'), 'pn_mine');
        $foreign  = $this->account($this->brand('Foreign'), 'pn_foreign');
        $conversation = $this->conversation($mine, $agent);

        $this->actingAs($agent)->postJson(route('whatsapp.send', $conversation), [
            'body'                => 'hello',
            'phone_number_id'     => $foreign->phone_number_id,
            'whatsapp_account_id' => $foreign->id,
            'brand_id'            => $foreign->brand_id,
        ])->assertOk();

        // The message belongs to the original conversation, whose account is
        // still ours — the injected ids changed nothing.
        $message = WhatsAppMessage::firstOrFail();
        $this->assertSame($conversation->id, $message->whatsapp_conversation_id);
        $this->assertSame($mine->id, $message->conversation->whatsapp_account_id);
    }

    // ── §55 Test 7 ───────────────────────────────────────────────────────

    public function test_credentials_never_appear_in_a_response(): void
    {
        Setting::set(WhatsAppSettings::KEY_APP_ID, '1234567890');
        Setting::set(WhatsAppSettings::KEY_APP_SECRET, Crypt::encryptString('super-secret-app-secret'));
        Setting::set(WhatsAppSettings::KEY_VERIFY_TOKEN, Crypt::encryptString('super-secret-verify-token'));

        $admin = $this->superAdmin();
        $agent = $this->user('view whatsapp', 'reply whatsapp');

        $conversation = $this->conversation($this->account($this->brand()), $agent);

        // The settings page shows the app id but never the secrets.
        $this->actingAs($admin)->get(route('settings.whatsapp'))
            ->assertOk()
            ->assertDontSee('super-secret-app-secret')
            ->assertDontSee('super-secret-verify-token');

        // Nor does any inbox payload carry a per-number access token.
        $this->actingAs($agent)->getJson(route('whatsapp.conversation', $conversation))
            ->assertOk()
            ->assertDontSee('test-token-value');

        $this->actingAs($agent)->getJson(route('whatsapp.conversations'))
            ->assertOk()
            ->assertDontSee('test-token-value');
    }

    /** A serialised account must not carry its token even if the model is loaded. */
    public function test_an_account_never_serialises_its_access_token(): void
    {
        $account = $this->account($this->brand());

        $this->assertStringNotContainsString('test-token-value', $account->toJson());
        $this->assertArrayNotHasKey('access_token', $account->toArray());
        // ...while the service layer can still read it.
        $this->assertSame('test-token-value', $account->accessToken());
    }

    // ── §55 Test 8 ───────────────────────────────────────────────────────

    public function test_a_failed_api_call_does_not_log_the_access_token(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token', 'code' => 190],
            ], 401),
        ]);

        $account = $this->account($this->brand());
        $client  = app(\App\Services\WhatsApp\MetaWhatsAppClient::class);

        try {
            $client->sendText($account, '8801700000000', 'hi');
            $this->fail('Expected the API call to throw.');
        } catch (\App\Services\WhatsApp\WhatsAppApiException $e) {
            // Neither the developer message nor the agent-facing one may carry
            // the credential that produced the failure.
            $this->assertStringNotContainsString('test-token-value', $e->getMessage());
            $this->assertStringNotContainsString('test-token-value', $e->userMessage());
        }
    }

    // ── §55 Test 9 ───────────────────────────────────────────────────────

    public function test_a_duplicate_webhook_delivery_does_not_create_a_second_message(): void
    {
        Setting::set(WhatsAppSettings::KEY_APP_SECRET, Crypt::encryptString('webhook-secret'));

        $account = $this->account($this->brand(), 'pn_webhook');

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => 'pn_webhook'],
                        'contacts' => [['wa_id' => '8801711111111', 'profile' => ['name' => 'Rahim']]],
                        'messages' => [[
                            'id'        => 'wamid.DUPLICATE_TEST',
                            'from'      => '8801711111111',
                            'timestamp' => (string) now()->timestamp,
                            'type'      => 'text',
                            'text'      => ['body' => 'Hello there'],
                        ]],
                    ],
                ]],
            ]],
        ];

        // Delivered twice, exactly as Meta retries.
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertDatabaseCount('whatsapp_messages', 1);
        $this->assertDatabaseCount('whatsapp_webhook_events', 1);
    }

    public function test_an_unsigned_webhook_is_rejected(): void
    {
        Setting::set(WhatsAppSettings::KEY_APP_SECRET, Crypt::encryptString('webhook-secret'));

        $this->postJson(route('whatsapp.webhook.receive'), ['object' => 'whatsapp_business_account'])
            ->assertForbidden();

        $this->assertDatabaseCount('whatsapp_webhook_events', 0);
    }

    public function test_a_webhook_with_a_wrong_signature_is_rejected(): void
    {
        Setting::set(WhatsAppSettings::KEY_APP_SECRET, Crypt::encryptString('webhook-secret'));

        $body = json_encode(['object' => 'whatsapp_business_account']);

        $this->call(
            'POST',
            route('whatsapp.webhook.receive'),
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'the-wrong-secret')],
            $body,
        )->assertForbidden();

        $this->assertDatabaseCount('whatsapp_webhook_events', 0);
    }

    /** Spec §19: an unrecognised number is never attached to a random brand. */
    public function test_a_webhook_for_an_unknown_number_creates_no_conversation(): void
    {
        Setting::set(WhatsAppSettings::KEY_APP_SECRET, Crypt::encryptString('webhook-secret'));

        $this->brand();   // a brand exists, but no account for this number

        $this->postWebhook([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => 'pn_belongs_to_nobody'],
                        'contacts' => [['wa_id' => '8801799999999', 'profile' => ['name' => 'Stranger']]],
                        'messages' => [[
                            'id' => 'wamid.UNKNOWN', 'from' => '8801799999999',
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text', 'text' => ['body' => 'hello?'],
                        ]],
                    ],
                ]],
            ]],
        ])->assertOk();

        $this->assertDatabaseCount('whatsapp_conversations', 0);
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    // ── §55 Test 10 ──────────────────────────────────────────────────────

    /**
     * The internal chat is a separate system and must be unaffected.
     *
     * The dedicated chat suites cover its behaviour; this asserts the boundary —
     * WhatsApp data never appears in internal chat, and the two unread counters
     * are computed independently.
     */
    public function test_whatsapp_traffic_never_reaches_the_internal_chat(): void
    {
        $agent = $this->user('view whatsapp', 'reply whatsapp');
        $conversation = $this->conversation($this->account($this->brand()), $agent);

        WhatsAppMessage::create([
            'whatsapp_conversation_id' => $conversation->id,
            'wamid'                    => 'wamid.ISOLATION',
            'direction'                => WhatsAppMessage::DIRECTION_IN,
            'type'                     => 'text',
            'body'                     => 'customer message',
            'status'                   => WhatsAppMessage::STATUS_RECEIVED,
        ]);
        $conversation->increment('unread_count');

        // The internal chat's own tables stayed empty...
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);

        // ...its unread counter reports zero...
        $this->assertSame(0, app(\App\Services\ChatService::class)->unreadCountFor($agent));

        // ...while WhatsApp's own counter sees the message.
        $this->assertSame(1, app(\App\Services\WhatsApp\WhatsAppConversationService::class)->unreadCountFor($agent));

        // And the internal chat still answers normally.
        $this->actingAs($agent)->getJson(route('chat.conversations'))
            ->assertOk()
            ->assertJsonPath('unread_total', 0);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Post a payload signed the way Meta signs it. */
    private function postWebhook(array $payload)
    {
        $body = json_encode($payload);

        return $this->call(
            'POST',
            route('whatsapp.webhook.receive'),
            [], [], [],
            [
                'CONTENT_TYPE'             => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'webhook-secret'),
            ],
            $body,
        );
    }
}
