<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\SoundSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Settings → Sounds: an admin (or anyone given 'manage sound settings')
 * chooses which sound plays for what, for everyone.
 */
class SoundSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Permission::firstOrCreate(['name' => 'manage sound settings', 'guard_name' => 'web']);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(['is_active' => true]))->givePermissionTo('manage sound settings')->fresh();
    }

    private function sounds(): SoundSettings
    {
        return app(SoundSettings::class);
    }

    /** Every event as it ships, with the given changes on top. */
    private function payload(array $overrides = []): array
    {
        $events = [];
        foreach (SoundSettings::EVENTS as $key => $meta) {
            $events[$key] = ['on' => '1', 'sound' => $meta['default'], 'volume' => $meta['volume']];
        }

        return array_replace_recursive(['enabled' => '1', 'events' => $events], $overrides);
    }

    private function save(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin())->post(route('settings.sounds.update'), $this->payload($overrides));
    }

    // ── Who may change it ────────────────────────────────────────────────

    public function test_only_someone_with_the_permission_opens_or_saves_it(): void
    {
        $nobody = User::factory()->create(['is_active' => true]);

        $this->actingAs($nobody)->get(route('settings.sounds'))->assertForbidden();
        $this->save(['events' => ['message' => ['sound' => 'tone:ping']]], $nobody)->assertForbidden();
        $this->assertSame('file:message_alert', $this->sounds()->config()['events']['message']['sound']);

        $this->actingAs($this->admin())->get(route('settings.sounds'))
            ->assertOk()
            ->assertSee('What plays for what')
            ->assertSee('Incoming call');
    }

    public function test_someone_given_only_sounds_sees_only_sounds_in_settings(): void
    {
        $page = $this->actingAs($this->admin())->get(route('settings.sounds'))->assertOk();

        $page->assertSee('href="' . route('settings.sounds') . '"', false)
            ->assertDontSee('href="' . route('settings.storage') . '"', false)
            ->assertDontSee('href="' . route('settings.chat') . '"', false)
            ->assertDontSee('href="' . route('roles.index') . '"', false);
    }

    public function test_the_sidebar_leads_there_only_for_those_who_hold_it(): void
    {
        $this->actingAs($this->admin())->get(route('account.edit'))
            ->assertSee('href="' . route('settings.sounds') . '"', false);

        $this->actingAs(User::factory()->create(['is_active' => true]))->get(route('account.edit'))
            ->assertDontSee('href="' . route('settings.sounds') . '"', false);
    }

    // ── What the browser is told ─────────────────────────────────────────

    public function test_out_of_the_box_it_plays_what_it_always_did(): void
    {
        $browser = $this->sounds()->forBrowser();

        $this->assertTrue($browser['enabled']);
        $this->assertStringContainsString('sounds/message_alert.mp3', $browser['events']['message']['src']);
        foreach (['notification', 'task', 'workflow', 'meeting', 'call'] as $event) {
            $this->assertStringContainsString('sounds/notification.mp3', $browser['events'][$event]['src'], $event);
        }
        $this->assertSame(0.6, $browser['events']['message']['volume']);
        $this->assertSame(0.55, $browser['events']['call']['volume']);
        $this->assertTrue($browser['events']['call']['loop']);
        $this->assertFalse($browser['events']['message']['loop']);
    }

    public function test_saved_choices_reach_every_page(): void
    {
        $this->save([
            'events' => [
                'message' => ['sound' => 'tone:chime', 'volume' => 40],
                'task'    => ['on' => '0'],
                'call'    => ['sound' => 'tone:ring'],
            ],
        ])->assertRedirect(route('settings.sounds'))->assertSessionHasNoErrors();

        $events = $this->sounds()->forBrowser()['events'];
        $this->assertSame(['src' => null, 'tone' => 'chime', 'on' => true, 'volume' => 0.4, 'loop' => false], $events['message']);
        $this->assertFalse($events['task']['on']);
        $this->assertSame('ring', $events['call']['tone']);

        // Any page, for anyone — it is in the layout.
        $this->actingAs(User::factory()->create(['is_active' => true]))->get(route('account.edit'))
            ->assertSee('"tone":"chime"', false);
    }

    public function test_switching_sounds_off_for_everyone(): void
    {
        $payload = $this->payload();
        unset($payload['enabled']);

        $this->actingAs($this->admin())->post(route('settings.sounds.update'), $payload)->assertSessionHasNoErrors();

        $this->assertFalse($this->sounds()->forBrowser()['enabled']);
        // The per-event choices are kept for when it goes back on.
        $this->assertSame('file:message_alert', $this->sounds()->config()['events']['message']['sound']);
    }

    public function test_a_volume_of_zero_means_silent(): void
    {
        $this->save(['events' => ['meeting' => ['volume' => 0]]])->assertSessionHasNoErrors();

        $this->assertFalse($this->sounds()->forBrowser()['events']['meeting']['on']);
    }

    public function test_nonsense_is_refused(): void
    {
        $this->save(['events' => ['message' => ['sound' => 'file:../../.env']]])->assertSessionHasErrors('events.message.sound');
        $this->save(['events' => ['message' => ['volume' => 150]]])->assertSessionHasErrors('events.message.volume');

        $payload = $this->payload();
        unset($payload['events']['call']);
        $this->actingAs($this->admin())->post(route('settings.sounds.update'), $payload)->assertSessionHasErrors('events.call');

        $this->assertNull(Setting::get(SoundSettings::KEY));
    }

    // ── Uploads ──────────────────────────────────────────────────────────

    public function test_an_uploaded_sound_is_stored_and_played_to_everyone(): void
    {
        // A real MP3, so its type is detected from the bytes as it would be live.
        $copy = tempnam(sys_get_temp_dir(), 'snd');
        copy(public_path('sounds/message_alert.mp3'), $copy);
        $bytes = filesize($copy);

        $this->save([
            'events'  => ['message' => ['sound' => SoundSettings::UPLOAD]],
            'uploads' => ['message' => new UploadedFile($copy, 'Ding Dong.mp3', null, null, true)],
        ])->assertSessionHasNoErrors();

        $custom = $this->sounds()->customFile('message');
        $this->assertSame('Ding Dong.mp3', $custom['name']);
        $this->assertSame('local', $custom['disk']);
        $this->assertStringStartsWith(SoundSettings::FOLDER . '/', $custom['path']);
        Storage::disk('local')->assertExists($custom['path']);
        $this->assertSame(SoundSettings::CUSTOM, $this->sounds()->config()['events']['message']['sound']);

        $src = $this->sounds()->forBrowser()['events']['message']['src'];
        $this->assertStringStartsWith(route('sounds.play', 'message'), $src);

        // Any signed-in user can fetch it; it is served as audio, never sniffed.
        $anyone = User::factory()->create(['is_active' => true]);
        $response = $this->actingAs($anyone)->get($src)->assertOk();
        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame($bytes, strlen($response->getContent()));

        // Safari asks in ranges.
        $this->actingAs($anyone)->get($src, ['Range' => 'bytes=0-1'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-1/' . $bytes)
            ->assertHeader('Content-Length', '2');
        $this->actingAs($anyone)->get($src, ['Range' => 'bytes=-10'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes ' . ($bytes - 10) . '-' . ($bytes - 1) . '/' . $bytes);
        $this->actingAs($anyone)->get($src, ['Range' => 'bytes=999999-'])->assertStatus(416);
    }

    public function test_replacing_an_upload_removes_the_old_file(): void
    {
        $this->save([
            'events'  => ['call' => ['sound' => SoundSettings::UPLOAD]],
            'uploads' => ['call' => UploadedFile::fake()->create('ring-one.wav', 5, 'audio/wav')],
        ])->assertSessionHasNoErrors();
        $first = $this->sounds()->customFile('call');

        $this->save([
            'events'  => ['call' => ['sound' => SoundSettings::UPLOAD]],
            'uploads' => ['call' => UploadedFile::fake()->create('ring-two.ogg', 5, 'audio/ogg')],
        ])->assertSessionHasNoErrors();
        $second = $this->sounds()->customFile('call');

        Storage::disk('local')->assertMissing($first['path']);
        Storage::disk('local')->assertExists($second['path']);
        $this->assertSame('audio/ogg', $second['mime']);
    }

    public function test_an_upload_is_kept_when_switching_to_a_built_in_and_back(): void
    {
        $this->save([
            'events'  => ['task' => ['sound' => SoundSettings::UPLOAD]],
            'uploads' => ['task' => UploadedFile::fake()->create('task.mp3', 5, 'audio/mpeg')],
        ]);
        $path = $this->sounds()->customFile('task')['path'];

        $this->save(['events' => ['task' => ['sound' => 'tone:pop']]])->assertSessionHasNoErrors();
        $this->assertSame('pop', $this->sounds()->forBrowser()['events']['task']['tone']);
        Storage::disk('local')->assertExists($path);

        $this->save(['events' => ['task' => ['sound' => SoundSettings::CUSTOM]]])->assertSessionHasNoErrors();
        $this->assertStringStartsWith(route('sounds.play', 'task'), $this->sounds()->forBrowser()['events']['task']['src']);
    }

    public function test_only_real_audio_is_accepted(): void
    {
        // Named like audio, but its content is a web page.
        $this->save([
            'events'  => ['message' => ['sound' => SoundSettings::UPLOAD]],
            'uploads' => ['message' => UploadedFile::fake()->create('trick.mp3', 1, 'text/html')],
        ])->assertSessionHasErrors('uploads.message');

        // Audio content, but not a name that will be served as audio.
        $this->save([
            'events'  => ['message' => ['sound' => SoundSettings::UPLOAD]],
            'uploads' => ['message' => UploadedFile::fake()->create('trick.svg', 1, 'audio/mpeg')],
        ])->assertSessionHasErrors('uploads.message');

        // Too long to be an alert.
        $this->save([
            'events'  => ['message' => ['sound' => SoundSettings::UPLOAD]],
            'uploads' => ['message' => UploadedFile::fake()->create('song.mp3', SoundSettings::MAX_UPLOAD_KB + 1, 'audio/mpeg')],
        ])->assertSessionHasErrors('uploads.message');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_upload_or_custom_needs_a_file(): void
    {
        $this->save(['events' => ['message' => ['sound' => SoundSettings::UPLOAD]]])
            ->assertSessionHasErrors('uploads.message');

        $this->save(['events' => ['message' => ['sound' => SoundSettings::CUSTOM]]])
            ->assertSessionHasErrors('events.message.sound');
    }

    public function test_the_sound_route_serves_only_configured_uploads(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get(route('sounds.play', 'message'))->assertNotFound();   // none uploaded
        $this->actingAs($user)->get('/alert-sounds/nothing')->assertNotFound();

        // A stored value pointing somewhere it should not is ignored, not served.
        Storage::disk('local')->put('secrets.php', '<?php // secret');
        Setting::set(SoundSettings::KEY, json_encode(['enabled' => true, 'events' => [
            'message' => ['on' => true, 'sound' => 'custom', 'volume' => 60, 'custom' => ['path' => 'secrets.php', 'disk' => 'local']],
        ]]));

        $this->actingAs($user)->get(route('sounds.play', 'message'))->assertNotFound();
        $this->assertSame('file:message_alert', $this->sounds()->config()['events']['message']['sound']);
    }

    public function test_every_change_is_logged(): void
    {
        $this->save(['events' => ['workflow' => ['sound' => 'tone:triple']]]);

        $this->assertDatabaseHas('activity_logs', ['module' => 'Settings', 'action' => 'Alert Sounds Changed']);
    }

    // ── Which notification plays which sound ─────────────────────────────

    public function test_notifications_say_which_sound_they_play(): void
    {
        $this->assertSame('task', SoundSettings::eventForNotification('App\\Notifications\\TaskAssigned'));
        $this->assertSame('task', SoundSettings::eventForNotification('App\\Notifications\\TaskReviewed'));
        $this->assertSame('workflow', SoundSettings::eventForNotification('App\\Notifications\\FlowItemAwaitingYou'));
        $this->assertSame('workflow', SoundSettings::eventForNotification('App\\Notifications\\StageAwaitingApproval'));
        $this->assertSame('meeting', SoundSettings::eventForNotification('App\\Notifications\\MeetingReminder'));
        $this->assertSame('notification', SoundSettings::eventForNotification('App\\Notifications\\PaymentProofSubmitted'));
        $this->assertSame('notification', SoundSettings::eventForNotification(null));

        $user = User::factory()->create(['is_active' => true]);
        $user->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\TaskAssigned', 'data' => ['title' => 'A task for you'],
        ]);

        $this->actingAs($user)->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonPath('notifications.0.sound', 'task');
    }
}
