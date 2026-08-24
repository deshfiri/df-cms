<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use App\Services\Chat\ChatAttachmentPruner;
use App\Services\Chat\ChatRetentionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Chat attachment retention.
 *
 * The rule everything here defends: pruning deletes the *file*, never the
 * message. Chat history is an audit trail in this application — retraction has
 * always been a flag rather than a row removal — and expiring an attachment must
 * not punch a hole in it.
 */
class ChatRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'monitor chats', 'guard_name' => 'web']);
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Super Admin');

        return $user->fresh();
    }

    private function enableRetention(int $days = 30): void
    {
        app(ChatRetentionSettings::class)->put(true, $days);
    }

    /** A message with an attachment that really exists on disk. */
    private function messageWithFile(string $disk = 'local', int $daysAgo = 0, string $body = null): Message
    {
        $from = User::factory()->create(['is_active' => true]);
        $to   = User::factory()->create(['is_active' => true]);

        $conversation = Conversation::between($from->id, $to->id);
        $path = 'chat/' . $conversation->id . '/' . uniqid() . '.pdf';

        Storage::disk($disk)->put($path, 'the file contents');

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id'       => $from->id,
            'body'            => $body,
            'attachment_path' => $path,
            'attachment_disk' => $disk,
            'attachment_name' => 'report.pdf',
            'attachment_mime' => 'application/pdf',
            'attachment_size' => 17,
        ]);

        // created_at is guarded, so age is set directly.
        $message->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $message->fresh();
    }

    // ── The policy switch ────────────────────────────────────────────────

    public function test_retention_is_off_by_default(): void
    {
        $this->assertFalse(app(ChatRetentionSettings::class)->enabled());
        $this->assertSame(30, app(ChatRetentionSettings::class)->days());
    }

    public function test_nothing_is_deleted_while_retention_is_off(): void
    {
        Storage::fake('local');
        $message = $this->messageWithFile(daysAgo: 400);

        $result = app(ChatAttachmentPruner::class)->prune();

        $this->assertSame(0, $result['purged']);
        Storage::disk('local')->assertExists($message->attachment_path);
        $this->assertNull($message->fresh()->attachment_purged_at);
    }

    public function test_a_stored_day_count_outside_the_allowed_range_is_clamped(): void
    {
        Setting::set(ChatRetentionSettings::KEY_DAYS, '99999');
        $this->assertSame(ChatRetentionSettings::MAX_DAYS, app(ChatRetentionSettings::class)->days());

        Setting::set(ChatRetentionSettings::KEY_DAYS, '0');
        $this->assertSame(ChatRetentionSettings::DEFAULT_DAYS, app(ChatRetentionSettings::class)->days());
    }

    // ── What gets deleted ────────────────────────────────────────────────

    public function test_an_attachment_past_the_window_is_deleted(): void
    {
        Storage::fake('local');
        $this->enableRetention(30);

        $old = $this->messageWithFile(daysAgo: 40);

        $result = app(ChatAttachmentPruner::class)->prune();

        $this->assertSame(1, $result['purged']);
        Storage::disk('local')->assertMissing($old->attachment_path);
    }

    public function test_an_attachment_inside_the_window_is_left_alone(): void
    {
        Storage::fake('local');
        $this->enableRetention(30);

        $recent = $this->messageWithFile(daysAgo: 5);

        app(ChatAttachmentPruner::class)->prune();

        Storage::disk('local')->assertExists($recent->attachment_path);
        $this->assertNull($recent->fresh()->attachment_purged_at);
    }

    /** The whole point: the conversation survives its files. */
    public function test_the_message_survives_and_still_names_the_file(): void
    {
        Storage::fake('local');
        $this->enableRetention(30);

        $message = $this->messageWithFile(daysAgo: 40, body: 'here is the report');

        app(ChatAttachmentPruner::class)->prune();

        $fresh = $message->fresh();

        $this->assertNotNull($fresh, 'The message row must never be deleted.');
        $this->assertSame('here is the report', $fresh->body);
        $this->assertSame('report.pdf', $fresh->attachment_name);
        $this->assertNotNull($fresh->attachment_purged_at);
        // ...and it no longer claims to hold a file.
        $this->assertNull($fresh->attachment_path);
        $this->assertFalse($fresh->hasAttachment());
        $this->assertTrue($fresh->attachmentWasPurged());
    }

    public function test_a_purged_attachment_only_message_still_says_what_it_was(): void
    {
        Storage::fake('local');
        $this->enableRetention(30);

        $message = $this->messageWithFile(daysAgo: 40);   // no body at all

        app(ChatAttachmentPruner::class)->prune();

        $this->assertSame('📎 report.pdf (no longer available)', $message->fresh()->previewLine());
    }

    /** A file written before a CDN switch is deleted from where it actually is. */
    public function test_a_file_is_deleted_from_the_disk_it_was_written_to(): void
    {
        Storage::fake('local');
        Storage::fake('cloudinary');
        $this->enableRetention(30);

        $onCdn = $this->messageWithFile(disk: 'cloudinary', daysAgo: 40);

        app(ChatAttachmentPruner::class)->prune();

        Storage::disk('cloudinary')->assertMissing($onCdn->attachment_path);
    }

    public function test_pruning_twice_does_not_reprocess_the_same_message(): void
    {
        Storage::fake('local');
        $this->enableRetention(30);

        $this->messageWithFile(daysAgo: 40);

        $this->assertSame(1, app(ChatAttachmentPruner::class)->prune()['purged']);
        // Idempotent: the purged marker is what keeps it out of the second run.
        $this->assertSame(0, app(ChatAttachmentPruner::class)->prune()['purged']);
    }

    public function test_a_dry_run_reports_without_deleting(): void
    {
        Storage::fake('local');
        $this->enableRetention(30);

        $message = $this->messageWithFile(daysAgo: 40);

        $result = app(ChatAttachmentPruner::class)->preview();

        $this->assertSame(1, $result['eligible']);
        $this->assertSame(0, $result['purged']);
        Storage::disk('local')->assertExists($message->attachment_path);
    }

    // ── How it reads afterwards ──────────────────────────────────────────

    public function test_the_thread_reports_an_expired_attachment(): void
    {
        Storage::fake('local');
        $this->enableRetention(30);

        $message = $this->messageWithFile(daysAgo: 40);
        app(ChatAttachmentPruner::class)->prune();

        $conversation = Conversation::findOrFail($message->conversation_id);
        $sender = User::findOrFail($message->sender_id);
        $other  = User::findOrFail($conversation->otherParticipantId($sender->id));

        $this->actingAs($sender)
            ->getJson(route('chat.open', $other))
            ->assertOk()
            ->assertJsonPath('messages.0.attachment', null)
            ->assertJsonPath('messages.0.attachment_expired.name', 'report.pdf');
    }

    public function test_downloading_a_purged_attachment_is_refused(): void
    {
        Storage::fake('local');
        $this->enableRetention(30);

        $message = $this->messageWithFile(daysAgo: 40);
        app(ChatAttachmentPruner::class)->prune();

        $sender = User::findOrFail($message->sender_id);

        $this->actingAs($sender)
            ->get(route('chat.attachment', $message))
            ->assertNotFound();
    }

    // ── The settings screen ──────────────────────────────────────────────

    public function test_only_a_super_admin_may_change_retention(): void
    {
        $plain = User::factory()->create(['is_active' => true]);

        $this->actingAs($plain)->get(route('settings.chat'))->assertForbidden();
        $this->actingAs($this->superAdmin())->get(route('settings.chat'))->assertOk();
    }

    public function test_the_policy_can_be_switched_on_with_a_day_count(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.chat.update'), ['retention_enabled' => 1, 'retention_days' => 45])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(app(ChatRetentionSettings::class)->enabled());
        $this->assertSame(45, app(ChatRetentionSettings::class)->days());
    }

    public function test_switching_it_on_without_a_day_count_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.chat.update'), ['retention_enabled' => 1])
            ->assertSessionHasErrors('retention_days');

        $this->assertFalse(app(ChatRetentionSettings::class)->enabled());
    }

    public function test_switching_it_off_needs_no_day_count(): void
    {
        $this->enableRetention(45);

        $this->actingAs($this->superAdmin())
            ->post(route('settings.chat.update'), [])
            ->assertSessionHasNoErrors();

        $this->assertFalse(app(ChatRetentionSettings::class)->enabled());
    }

    public function test_the_inventory_counts_what_is_held(): void
    {
        Storage::fake('local');

        $this->messageWithFile(daysAgo: 1);
        $this->messageWithFile(daysAgo: 2);

        $inventory = app(ChatAttachmentPruner::class)->inventory();

        $this->assertSame(2, $inventory['files']);
        $this->assertSame(34, $inventory['bytes']);
        $this->assertSame(0, $inventory['purged']);
    }

    // ── The command ──────────────────────────────────────────────────────

    public function test_the_command_does_nothing_while_the_policy_is_off(): void
    {
        $this->artisan('chat:prune-attachments')
            ->expectsOutputToContain('switched off')
            ->assertExitCode(0);
    }

    public function test_the_command_deletes_and_reports(): void
    {
        Storage::fake('local');
        $this->enableRetention(30);

        $message = $this->messageWithFile(daysAgo: 40);

        $this->artisan('chat:prune-attachments')->assertExitCode(0);

        Storage::disk('local')->assertMissing($message->attachment_path);
    }
}
