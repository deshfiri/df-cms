<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_a_message_can_carry_an_image_with_no_text(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $response = $this->actingAs($me)
            ->post(route('chat.send', $other), [
                'file' => UploadedFile::fake()->image('screenshot.png', 400, 300),
            ])
            ->assertOk();

        $this->assertNull($response->json('message.body'));
        $this->assertTrue($response->json('message.attachment.is_image'));
        $this->assertSame('screenshot.png', $response->json('message.attachment.name'));

        $message = Message::firstOrFail();
        Storage::disk('local')->assertExists($message->attachment_path);
    }

    public function test_a_message_can_carry_a_file_alongside_text(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $response = $this->actingAs($me)
            ->post(route('chat.send', $other), [
                'body' => 'Here is the contract',
                'file' => UploadedFile::fake()->create('contract.pdf', 120, 'application/pdf'),
            ])
            ->assertOk();

        $this->assertSame('Here is the contract', $response->json('message.body'));
        $this->assertFalse($response->json('message.attachment.is_image'));
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $this->actingAs($this->user())
            ->postJson(route('chat.send', $this->user()), [])
            ->assertStatus(422);
    }

    public function test_an_oversized_attachment_is_rejected(): void
    {
        $this->actingAs($this->user())
            ->postJson(route('chat.send', $this->user()), [
                'file' => UploadedFile::fake()->create('huge.zip', 25000),
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_the_stored_name_never_uses_the_uploaded_filename(): void
    {
        $me = $this->user();

        $this->actingAs($me)->post(route('chat.send', $this->user()), [
            'file' => UploadedFile::fake()->create('../../evil .php', 10),
        ])->assertOk();

        $message = Message::firstOrFail();

        // The path is generated, so nothing from the upload reaches the disk.
        $this->assertStringNotContainsString('evil', $message->attachment_path);
        $this->assertStringNotContainsString('..', $message->attachment_path);
        $this->assertMatchesRegularExpression('#^chat/\d+/[0-9a-f-]{36}\.php$#', $message->attachment_path);

        // Laravel has already reduced the traversal to a basename by the time
        // getClientOriginalName() is called — belt as well as braces.
        $this->assertSame('evil .php', $message->attachment_name);
    }

    public function test_a_participant_can_fetch_the_attachment(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $this->actingAs($me)->post(route('chat.send', $other), [
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ])->assertOk();

        $message = Message::firstOrFail();

        $this->actingAs($other)->get(route('chat.attachment', $message))->assertOk();
        $this->actingAs($me)->get(route('chat.attachment', $message))->assertOk();
    }

    public function test_an_outsider_cannot_fetch_the_attachment(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $this->actingAs($me)->post(route('chat.send', $other), [
            'file' => UploadedFile::fake()->image('private.png'),
        ])->assertOk();

        $this->actingAs($this->user())
            ->get(route('chat.attachment', Message::firstOrFail()))
            ->assertForbidden();
    }

    public function test_a_message_without_an_attachment_has_nothing_to_fetch(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $this->actingAs($me)->post(route('chat.send', $other), ['body' => 'text only'])->assertOk();

        $this->actingAs($me)
            ->get(route('chat.attachment', Message::firstOrFail()))
            ->assertNotFound();
    }

    public function test_the_conversation_list_describes_an_attachment_only_message(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $this->actingAs($other)->post(route('chat.send', $me), [
            'file' => UploadedFile::fake()->image('shot.png'),
        ])->assertOk();

        $preview = $this->actingAs($me)
            ->getJson(route('chat.conversations'))
            ->json('conversations.0.last_body');

        $this->assertSame('📷 Photo', $preview);
    }

    // ── Voice messages ───────────────────────────────────────────────────

    public function test_a_recorded_voice_message_keeps_its_duration(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $response = $this->actingAs($me)
            ->post(route('chat.send', $other), [
                'file'     => UploadedFile::fake()->create('voice-message-1.webm', 40, 'audio/webm'),
                'duration' => 7,
            ])
            ->assertOk();

        $this->assertTrue($response->json('message.attachment.is_voice'));
        $this->assertFalse($response->json('message.attachment.is_image'));
        $this->assertSame('0:07', $response->json('message.attachment.duration'));
        $this->assertSame(7, Message::firstOrFail()->attachment_duration);
    }

    public function test_an_audio_only_webm_sniffed_as_video_is_still_a_voice_message(): void
    {
        // Chrome's MediaRecorder output is routinely detected as video/webm even
        // with no video track, so this must not fall through to a file chip.
        $response = $this->actingAs($this->user())
            ->post(route('chat.send', $this->user()), [
                'file'     => UploadedFile::fake()->create('voice-message-2.webm', 30, 'video/webm'),
                'duration' => 12,
            ])
            ->assertOk();

        $this->assertTrue($response->json('message.attachment.is_voice'));
        $this->assertSame('0:12', $response->json('message.attachment.duration'));
    }

    public function test_a_duration_claimed_for_a_non_audio_upload_is_ignored(): void
    {
        $response = $this->actingAs($this->user())
            ->post(route('chat.send', $this->user()), [
                'file'     => UploadedFile::fake()->create('not-really.pdf', 10, 'application/pdf'),
                'duration' => 30,
            ])
            ->assertOk();

        // Rendering as a dead audio player would be worse than a file chip.
        $this->assertFalse($response->json('message.attachment.is_voice'));
        $this->assertNull($response->json('message.attachment.duration'));
        $this->assertNull(Message::firstOrFail()->attachment_duration);
    }

    public function test_an_implausible_duration_is_rejected(): void
    {
        foreach ([0, 601] as $seconds) {
            $this->actingAs($this->user())
                ->postJson(route('chat.send', $this->user()), [
                    'file'     => UploadedFile::fake()->create('voice.webm', 10, 'audio/webm'),
                    'duration' => $seconds,
                ])
                ->assertStatus(422);
        }

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_a_voice_message_is_served_inline_so_it_can_be_played(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $this->actingAs($me)->post(route('chat.send', $other), [
            'file'     => UploadedFile::fake()->create('voice.webm', 20, 'audio/webm'),
            'duration' => 4,
        ])->assertOk();

        $response = $this->actingAs($other)
            ->get(route('chat.attachment', Message::firstOrFail()))
            ->assertOk();

        $this->assertSame('audio/webm', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_conversation_list_describes_a_voice_message(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $this->actingAs($other)->post(route('chat.send', $me), [
            'file'     => UploadedFile::fake()->create('voice.webm', 15, 'audio/webm'),
            'duration' => 65,
        ])->assertOk();

        $preview = $this->actingAs($me)
            ->getJson(route('chat.conversations'))
            ->json('conversations.0.last_body');

        $this->assertSame('🎤 Voice message (1:05)', $preview);
    }

    public function test_attachments_are_stored_under_their_conversation(): void
    {
        $me    = $this->user();
        $other = $this->user();

        $this->actingAs($me)->post(route('chat.send', $other), [
            'file' => UploadedFile::fake()->image('a.png'),
        ])->assertOk();

        $conversation = Conversation::firstOrFail();

        $this->assertStringStartsWith(
            'chat/' . $conversation->id . '/',
            Message::firstOrFail()->attachment_path
        );
    }
}
