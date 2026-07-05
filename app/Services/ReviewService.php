<?php

namespace App\Services;

use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Str;

class ReviewService
{
    public function create(array $data, User $actor): Review
    {
        $isAnonymous = (bool) ($data['is_anonymous'] ?? false);

        return Review::create([
            'type'               => $data['type'],
            'subject_user_id'    => $data['subject_user_id'] ?? null,
            'subject_department' => $data['subject_department'] ?? null,
            'title'              => $data['title'],
            'message'            => $data['message'],
            'is_anonymous'       => $isAnonymous,
            // Never recorded for an anonymous post — there is nothing to
            // redact later, by design, not even for Super Admin.
            'posted_by'          => $isAnonymous ? null : $actor->id,
            // Returned once to the poster's own browser so they can find this
            // review again later (even an anonymous one) without the database
            // ever linking it back to their user_id.
            'poster_token'       => Str::random(48),
        ]);
    }

    /**
     * Reviews the caller's own browser is tracking by token — the only way
     * to retrieve an anonymous review, since posted_by is never stored for one.
     */
    public function findByTokens(array $tokens): \Illuminate\Support\Collection
    {
        $tokens = array_values(array_filter($tokens, fn ($t) => is_string($t) && $t !== ''));
        if (empty($tokens)) {
            return collect();
        }

        return Review::whereIn('poster_token', $tokens)->latest()->get();
    }
}
