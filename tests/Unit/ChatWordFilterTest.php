<?php

namespace Tests\Unit;

use App\Models\ForbiddenWord;
use App\Services\Chat\ChatWordFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The matching rule behind the chat's forbidden-word warning: whole-word,
 * case-insensitive, only against the active list.
 */
class ChatWordFilterTest extends TestCase
{
    use RefreshDatabase;

    private function filter(): ChatWordFilter
    {
        return app(ChatWordFilter::class);
    }

    protected function tearDown(): void
    {
        ChatWordFilter::flushCache();
        parent::tearDown();
    }

    public function test_a_message_containing_a_forbidden_word_matches(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);

        $this->assertSame(['badword'], $this->filter()->match('this has a badword in it'));
    }

    public function test_matching_is_case_insensitive(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);

        $this->assertSame(['badword'], $this->filter()->match('this has a BadWord in it'));
    }

    public function test_matching_is_whole_word_not_substring(): void
    {
        ForbiddenWord::create(['word' => 'ass', 'is_active' => true]);

        $this->assertSame([], $this->filter()->match('the assignment goes in your class'));
        $this->assertSame(['ass'], $this->filter()->match('you ass'));
    }

    public function test_a_multi_word_phrase_matches_as_a_whole(): void
    {
        ForbiddenWord::create(['word' => 'go away', 'is_active' => true]);

        $this->assertSame(['go away'], $this->filter()->match('why dont you just go away already'));
        $this->assertSame([], $this->filter()->match('please go somewhere else'));
    }

    public function test_an_inactive_word_is_never_matched(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => false]);

        $this->assertSame([], $this->filter()->match('this has a badword in it'));
    }

    public function test_every_matched_word_is_reported(): void
    {
        ForbiddenWord::create(['word' => 'foo', 'is_active' => true]);
        ForbiddenWord::create(['word' => 'bar', 'is_active' => true]);

        $this->assertSame(['foo', 'bar'], $this->filter()->match('foo and bar together'));
    }

    public function test_a_clean_message_matches_nothing(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);

        $this->assertSame([], $this->filter()->match('a perfectly ordinary message'));
    }

    public function test_an_empty_or_null_body_matches_nothing(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);

        $this->assertSame([], $this->filter()->match(null));
        $this->assertSame([], $this->filter()->match('   '));
    }

    public function test_the_list_is_cached_until_flushed(): void
    {
        ForbiddenWord::create(['word' => 'badword', 'is_active' => true]);
        $this->assertSame(['badword'], $this->filter()->match('a badword here'));

        // Changing the list directly (bypassing the controller, which would
        // flush the cache itself) should not be seen until it's flushed.
        ForbiddenWord::query()->update(['is_active' => false]);
        $this->assertSame(['badword'], $this->filter()->match('a badword here'), 'stale cache should still match');

        ChatWordFilter::flushCache();
        $this->assertSame([], $this->filter()->match('a badword here'));
    }
}
