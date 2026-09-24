<?php

namespace App\Services\Chat;

use App\Models\ForbiddenWord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Matches a message's text against the admin-managed forbidden word list.
 *
 * Whole-word/phrase matching, not a plain substring search: banning "ass"
 * must not flag "class" or "assignment". Case-insensitive, so the list only
 * needs one form of each word.
 */
class ChatWordFilter
{
    private const CACHE_KEY = 'chat.forbidden_words';

    /**
     * Every active word this message's text contains, in the order they're
     * configured. Empty when there's nothing to check or nothing matched.
     *
     * @return array<int,string>
     */
    public function match(?string $body): array
    {
        $body = trim((string) $body);
        if ($body === '') {
            return [];
        }

        $matched = [];
        foreach ($this->activeWords() as $word) {
            if (preg_match($this->pattern($word), $body)) {
                $matched[] = $word;
            }
        }

        return $matched;
    }

    /** @return Collection<int,string> */
    private function activeWords(): Collection
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => ForbiddenWord::active()->pluck('word'));
    }

    private function pattern(string $word): string
    {
        return '/\b' . preg_quote($word, '/') . '\b/iu';
    }

    /** Call after any change to the forbidden-word list. */
    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
