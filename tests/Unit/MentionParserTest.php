<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\MentionParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @Name mentions are matched against a bounded candidate set, never all
 * users — User has no username/handle, only a free-text name, so this is
 * exact-name matching, not a lookup.
 */
class MentionParserTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name): User
    {
        return User::factory()->create(['name' => $name, 'is_active' => true]);
    }

    public function test_a_mentioned_candidate_is_extracted(): void
    {
        $anika = $this->user('Anika Rahman');
        $candidates = collect([$anika]);

        $found = MentionParser::extract('Can @Anika Rahman take a look?', $candidates);

        $this->assertSame([$anika->id], $found->pluck('id')->all());
    }

    public function test_matching_is_case_insensitive(): void
    {
        $anika = $this->user('Anika Rahman');

        $found = MentionParser::extract('cc @ANIKA RAHMAN please', collect([$anika]));

        $this->assertSame([$anika->id], $found->pluck('id')->all());
    }

    public function test_someone_named_but_not_a_candidate_is_never_extracted(): void
    {
        // A real user named Bashir exists in the system, but is not party to
        // this discussion — mentioning "@Bashir" here must not resolve to him.
        User::factory()->create(['name' => 'Bashir Uddin', 'is_active' => true]);
        $anika = $this->user('Anika Rahman');

        $found = MentionParser::extract('@Bashir Uddin what do you think, @Anika Rahman?', collect([$anika]));

        $this->assertSame([$anika->id], $found->pluck('id')->all());
    }

    public function test_a_longer_name_is_not_swallowed_by_a_shorter_one(): void
    {
        $john = $this->user('John');
        $johnSmith = $this->user('John Smith');

        $found = MentionParser::extract('please review, @John Smith', collect([$john, $johnSmith]));

        $this->assertSame([$johnSmith->id], $found->pluck('id')->all());
    }

    public function test_no_at_sign_means_no_mention_even_if_the_name_appears(): void
    {
        $anika = $this->user('Anika Rahman');

        $found = MentionParser::extract('Anika Rahman already saw this', collect([$anika]));

        $this->assertSame([], $found->pluck('id')->all());
    }

    public function test_several_distinct_mentions_are_all_extracted(): void
    {
        $anika = $this->user('Anika Rahman');
        $bashir = $this->user('Bashir Uddin');

        $found = MentionParser::extract('@Anika Rahman and @Bashir Uddin, please check', collect([$anika, $bashir]));

        $this->assertSame(
            [$anika->id, $bashir->id],
            $found->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_highlight_wraps_a_matched_mention_and_escapes_everything_else(): void
    {
        $anika = $this->user('Anika Rahman');

        $html = MentionParser::highlight('<script>x</script> @Anika Rahman please review', collect([$anika]));

        $this->assertStringContainsString('<span class="mention">@Anika Rahman</span>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_highlight_leaves_a_non_candidate_name_unwrapped(): void
    {
        $anika = $this->user('Anika Rahman');

        $html = MentionParser::highlight('@Somebody Else said hi', collect([$anika]));

        $this->assertStringNotContainsString('class="mention"', $html);
        $this->assertStringContainsString('@Somebody Else', $html);
    }

    public function test_an_empty_candidate_set_never_crashes_and_escapes_plainly(): void
    {
        $html = MentionParser::highlight('<b>@anyone</b>', collect());

        $this->assertSame('&lt;b&gt;@anyone&lt;/b&gt;', $html);
        $this->assertSame([], MentionParser::extract('@anyone', collect())->all());
    }
}
