<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Finds @Name mentions in a comment's text against a bounded set of
 * candidates — never all users, since a name typed as "@John" could
 * otherwise match anyone in the system named John.
 *
 * User has no username/handle, only a free-text (non-unique) name, so
 * matching is by exact name, case-insensitive, on a word boundary — longest
 * candidate name first, so "@John Smith" isn't swallowed by a shorter "John"
 * match. Two candidates sharing the exact same name are, necessarily, both
 * counted as mentioned; that's an accepted limitation of not having a
 * unique handle to key off.
 */
class MentionParser
{
    /**
     * @param  Collection<int,User>  $candidates
     * @return Collection<int,User> the subset actually mentioned in $text
     */
    public static function extract(string $text, Collection $candidates): Collection
    {
        $names = self::sortedNames($candidates);
        if ($names->isEmpty() || trim($text) === '') {
            return collect();
        }

        preg_match_all(self::pattern($names), $text, $matches);
        $mentioned = collect($matches[1] ?? [])->map(fn ($m) => mb_strtolower($m))->unique();

        return $candidates->filter(fn (User $u) => $mentioned->contains(mb_strtolower($u->name)))->values();
    }

    /**
     * $text as safe HTML, with every @Name mention that matched a candidate
     * wrapped in a highlight span — same rule as extract(). Everything else
     * is escaped exactly as plain text would be.
     *
     * @param  Collection<int,User>  $candidates
     */
    public static function highlight(string $text, Collection $candidates): string
    {
        $names = self::sortedNames($candidates);
        if ($names->isEmpty() || $text === '') {
            return e($text);
        }

        $html = '';
        foreach (preg_split(self::pattern($names), $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $part) {
            $html .= $i % 2 === 0 ? e($part) : '<span class="mention">@' . e($part) . '</span>';
        }

        return $html;
    }

    /** @param Collection<int,User> $candidates */
    private static function sortedNames(Collection $candidates): Collection
    {
        return $candidates->pluck('name')->filter()->unique()->sortByDesc(fn ($n) => mb_strlen($n))->values();
    }

    private static function pattern(Collection $names): string
    {
        return '/@(' . $names->map(fn ($n) => preg_quote($n, '/'))->implode('|') . ')\b/iu';
    }
}
