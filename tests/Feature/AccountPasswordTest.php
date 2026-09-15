<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Staff changing their own password from My Account.
 *
 * Before this only a Super Admin could set a staff password. The page acts on
 * the signed-in user alone, proves the current password first, and is
 * throttled so that proof can't be guessed at.
 */
class AccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $password = 'OldPass123'): User
    {
        return User::factory()->create(['is_active' => true, 'password' => $password]);
    }

    private function change(User $user, array $overrides = [])
    {
        return $this->actingAs($user)
            ->from(route('account.edit'))
            ->put(route('account.password'), $overrides + [
                'current_password'      => 'OldPass123',
                'password'              => 'NewPass456',
                'password_confirmation' => 'NewPass456',
            ]);
    }

    public function test_any_staff_member_can_open_their_account_page(): void
    {
        $user = $this->staff();

        $this->actingAs($user)
            ->get(route('account.edit'))
            ->assertOk()
            ->assertSee('Change password')
            ->assertSee($user->email);
    }

    public function test_the_user_menu_links_to_it(): void
    {
        $this->actingAs($this->staff())
            ->get(route('account.edit'))
            ->assertSee('href="' . route('account.edit') . '"', false);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('account.edit'))->assertRedirect(route('login'));
        $this->put(route('account.password'), [])->assertRedirect(route('login'));
    }

    public function test_a_user_can_change_their_password(): void
    {
        $user = $this->staff();

        $this->change($user)
            ->assertRedirect(route('account.edit'))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('NewPass456', $user->password));
        $this->assertFalse(Hash::check('OldPass123', $user->password));

        // Still signed in afterwards.
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_new_password_works_at_login(): void
    {
        $user = $this->staff();
        $this->change($user);
        auth()->logout();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'NewPass456']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_current_password_must_be_right(): void
    {
        $user = $this->staff();

        $this->change($user, ['current_password' => 'wrong-one'])
            ->assertRedirect(route('account.edit'))
            ->assertSessionHasErrorsIn('password', 'current_password');

        $this->assertTrue(Hash::check('OldPass123', $user->fresh()->password));
    }

    public function test_the_confirmation_must_match(): void
    {
        $user = $this->staff();

        $this->change($user, ['password_confirmation' => 'Different789'])
            ->assertSessionHasErrorsIn('password', 'password');

        $this->assertTrue(Hash::check('OldPass123', $user->fresh()->password));
    }

    public function test_weak_or_unchanged_passwords_are_refused(): void
    {
        $user = $this->staff();

        foreach (['short1', 'lettersonly', '12345678', 'OldPass123'] as $weak) {
            $this->change($user, ['password' => $weak, 'password_confirmation' => $weak])
                ->assertSessionHasErrorsIn('password', 'password');
        }

        $this->assertTrue(Hash::check('OldPass123', $user->fresh()->password));
    }

    public function test_other_devices_can_be_signed_out(): void
    {
        $user  = $this->staff();
        $other = $this->staff();

        DB::table('sessions')->insert([
            ['id' => 'laptop-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'phone-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'colleague-session', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
        ]);
        config(['session.driver' => 'database']);

        $this->change($user, ['logout_others' => '1'])->assertSessionHas('success');

        $this->assertDatabaseMissing('sessions', ['id' => 'laptop-session']);
        $this->assertDatabaseMissing('sessions', ['id' => 'phone-session']);
        // Nobody else's sessions are touched.
        $this->assertDatabaseHas('sessions', ['id' => 'colleague-session']);
    }

    public function test_other_devices_are_left_alone_when_not_asked(): void
    {
        $user = $this->staff();
        DB::table('sessions')->insert(['id' => 'laptop-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        config(['session.driver' => 'database']);

        $this->change($user)->assertSessionHas('success');

        $this->assertDatabaseHas('sessions', ['id' => 'laptop-session']);
    }

    public function test_the_change_is_logged_without_the_password(): void
    {
        $user = $this->staff();
        $this->change($user);

        $log = ActivityLog::where('module', 'User')->where('action', 'Password Changed')->sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertStringNotContainsString('NewPass456', (string) $log->new_value);
        $this->assertStringNotContainsString('OldPass123', (string) $log->new_value);
    }

    public function test_guessing_the_current_password_is_throttled(): void
    {
        $user = $this->staff();

        for ($i = 0; $i < 6; $i++) {
            $this->change($user, ['current_password' => 'guess-' . $i]);
        }

        $this->change($user)->assertStatus(429);
        $this->assertTrue(Hash::check('OldPass123', $user->fresh()->password));
    }
}
