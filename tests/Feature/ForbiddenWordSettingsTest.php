<?php

namespace Tests\Feature;

use App\Models\ForbiddenWord;
use App\Models\User;
use App\Services\Chat\ChatWordFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Settings → Forbidden Words: the admin-managed list the chat warns on.
 */
class ForbiddenWordSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'manage chat moderation', 'guard_name' => 'web']);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(['is_active' => true]))
            ->givePermissionTo('manage chat moderation')
            ->fresh();
    }

    public function test_only_someone_with_the_permission_can_manage_the_list(): void
    {
        $nobody = User::factory()->create(['is_active' => true]);
        $word   = ForbiddenWord::create(['word' => 'existing', 'is_active' => true]);

        $this->actingAs($nobody)->get(route('forbidden-words.index'))->assertForbidden();
        $this->actingAs($nobody)->postJson(route('forbidden-words.store'), ['word' => 'new'])->assertForbidden();
        $this->actingAs($nobody)->putJson(route('forbidden-words.update', $word), ['is_active' => false])->assertForbidden();
        $this->actingAs($nobody)->deleteJson(route('forbidden-words.destroy', $word))->assertForbidden();

        $this->assertDatabaseMissing('forbidden_words', ['word' => 'new']);
        $this->assertTrue($word->fresh()->is_active);

        $this->actingAs($this->admin())->get(route('forbidden-words.index'))
            ->assertOk()
            ->assertSee('Forbidden Words')
            ->assertSee('existing');
    }

    public function test_a_word_is_added_lowercased_and_trimmed(): void
    {
        $this->actingAs($this->admin())->postJson(route('forbidden-words.store'), ['word' => '  BadWord  '])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('forbidden_words', ['word' => 'badword', 'is_active' => true]);
    }

    public function test_the_same_word_cannot_be_added_twice_regardless_of_case(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson(route('forbidden-words.store'), ['word' => 'badword'])->assertOk();

        $this->actingAs($admin)->postJson(route('forbidden-words.store'), ['word' => 'BADWORD'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('word');

        $this->assertSame(1, ForbiddenWord::where('word', 'badword')->count());
    }

    public function test_a_word_is_required(): void
    {
        $this->actingAs($this->admin())->postJson(route('forbidden-words.store'), ['word' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('word');
    }

    public function test_switching_one_off_stops_it_matching_without_deleting_it(): void
    {
        $admin = $this->admin();
        $word  = ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);

        $this->actingAs($admin)->putJson(route('forbidden-words.update', $word), ['is_active' => 0])->assertOk();

        $this->assertFalse($word->fresh()->is_active);
        $this->assertSame([], app(ChatWordFilter::class)->match('a badword here'));
    }

    public function test_a_word_can_be_deleted(): void
    {
        $word = ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);

        $this->actingAs($this->admin())->deleteJson(route('forbidden-words.destroy', $word))->assertOk();

        $this->assertDatabaseMissing('forbidden_words', ['id' => $word->id]);
    }

    public function test_every_change_is_logged(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('forbidden-words.store'), ['word' => 'badword'])->assertOk();
        $word = ForbiddenWord::where('word', 'badword')->firstOrFail();
        $this->actingAs($admin)->putJson(route('forbidden-words.update', $word), ['is_active' => 0])->assertOk();
        $this->actingAs($admin)->deleteJson(route('forbidden-words.destroy', $word))->assertOk();

        foreach (['Forbidden Word Added', 'Forbidden Word Updated', 'Forbidden Word Removed'] as $action) {
            $this->assertDatabaseHas('activity_logs', ['module' => 'Chat', 'action' => $action]);
        }
    }

    public function test_saving_flushes_the_matcher_cache_immediately(): void
    {
        $admin = $this->admin();
        $filter = app(ChatWordFilter::class);

        // Nothing on the list yet.
        $this->assertSame([], $filter->match('a badword here'));

        $this->actingAs($admin)->postJson(route('forbidden-words.store'), ['word' => 'badword'])->assertOk();

        // A fresh instance, same as any other request would resolve — the
        // cache must already reflect the new word, not a stale empty list.
        $this->assertSame(['badword'], app(ChatWordFilter::class)->match('a badword here'));
    }
}
