<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Staff profile pictures.
 *
 * The column had been there since the first migration and nothing ever wrote to
 * it. A picture is an upload like any other — it goes to whichever provider is
 * active and is read back through the disk it was written to — but it is shown
 * beside a name all over the app, so it is served to any signed-in colleague.
 */
class ProfilePictureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function user(string $name = 'Rahim Uddin'): User
    {
        return User::factory()->create(['is_active' => true, 'name' => $name]);
    }

    private function upload(User $user, ?UploadedFile $file = null)
    {
        return $this->actingAs($user)
            ->from(route('account.edit'))
            ->post(route('account.avatar'), ['avatar' => $file ?: UploadedFile::fake()->image('me.jpg', 300, 300)]);
    }

    public function test_a_user_can_set_a_profile_picture(): void
    {
        $user = $this->user();

        $this->upload($user)->assertRedirect(route('account.edit'))->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertStringStartsWith('avatars/' . $user->id . '_', (string) $user->avatar);
        $this->assertSame('local', $user->avatar_disk);
        Storage::disk('local')->assertExists($user->avatar);
    }

    public function test_the_picture_is_served_to_signed_in_staff(): void
    {
        $user = $this->user();
        $this->upload($user);

        $response = $this->actingAs($this->user('Someone Else'))
            ->get(route('users.avatar', $user->fresh()))
            ->assertOk();

        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->streamedContent());
    }

    public function test_a_picture_is_never_public(): void
    {
        $user = $this->user();
        $this->upload($user);

        // The upload signed us in; a guest is the point of this test.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->get(route('users.avatar', $user->fresh()))->assertRedirect(route('login'));
    }

    public function test_someone_with_no_picture_is_a_404(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('users.avatar', $user))->assertNotFound();
    }

    public function test_replacing_a_picture_removes_the_old_file(): void
    {
        $user = $this->user();
        $this->upload($user);
        $first = $user->fresh()->avatar;

        $this->travel(1)->seconds();
        $this->upload($user, UploadedFile::fake()->image('new.png', 200, 200));

        $second = $user->fresh()->avatar;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
    }

    public function test_a_picture_can_be_removed(): void
    {
        $user = $this->user();
        $this->upload($user);
        $path = $user->fresh()->avatar;

        $this->actingAs($user)->delete(route('account.avatar.destroy'))->assertRedirect(route('account.edit'));

        $this->assertNull($user->fresh()->avatar);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_only_real_images_within_two_megabytes_are_accepted(): void
    {
        $user = $this->user();

        $this->upload($user, UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf'))
            ->assertSessionHasErrorsIn('avatar', 'avatar');

        $this->upload($user, UploadedFile::fake()->image('huge.jpg')->size(2049))
            ->assertSessionHasErrorsIn('avatar', 'avatar');

        $this->assertNull($user->fresh()->avatar);
    }

    // ── Where it shows ───────────────────────────────────────────────────

    public function test_it_replaces_the_initials_in_the_header_and_on_my_account(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('account.edit'))->assertOk()->assertDontSee('class="avatar-img"', false);

        $this->upload($user);

        $this->actingAs($user)->get(route('account.edit'))
            ->assertOk()
            ->assertSee(route('users.avatar', $user), false)
            ->assertSee('class="avatar-img"', false);
    }

    public function test_chat_hands_the_picture_to_the_people_list(): void
    {
        $me    = $this->user('Me');
        $other = $this->user('Nadia Rahman');
        $this->upload($other);

        $this->actingAs($me)
            ->getJson(route('chat.users', ['q' => 'Nadia']))
            ->assertOk()
            ->assertJsonPath('users.0.avatar_url', route('users.avatar', $other->fresh()));
    }

    public function test_presence_carries_the_picture_so_the_online_list_can_show_it(): void
    {
        $user = $this->user();
        $this->upload($user);

        $callback = \Illuminate\Support\Facades\Broadcast::driver()->getChannels()->get('online');

        $this->assertSame(route('users.avatar', $user->fresh()), $callback($user->fresh())['avatar']);
    }
}
