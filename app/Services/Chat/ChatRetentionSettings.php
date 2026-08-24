<?php

namespace App\Services\Chat;

use App\Models\Setting;

/**
 * How long chat attachments are kept.
 *
 * Off by default, and deliberately so: switching this on deletes files, and that
 * is not a decision to make on someone's behalf by shipping a default.
 *
 * Follows the same settings contract as the storage and integration screens —
 * one service owns the keys, validates the values, and is the only thing the
 * rest of the application asks.
 */
class ChatRetentionSettings
{
    public const KEY_ENABLED = 'chat_attachment_retention_enabled';
    public const KEY_DAYS    = 'chat_attachment_retention_days';

    /** A month, as the most common answer to "how long?". */
    public const DEFAULT_DAYS = 30;

    /**
     * Anything shorter than a day risks deleting a file while the conversation
     * about it is still happening; anything past a few years is indistinguishable
     * from keeping it forever, which is what leaving this off already does.
     */
    public const MIN_DAYS = 1;
    public const MAX_DAYS = 3650;

    public function enabled(): bool
    {
        return (bool) Setting::get(self::KEY_ENABLED, false);
    }

    public function days(): int
    {
        $days = (int) Setting::get(self::KEY_DAYS, self::DEFAULT_DAYS);

        // A stored value outside the range would silently widen or narrow the
        // policy; clamp rather than trust it.
        return max(self::MIN_DAYS, min(self::MAX_DAYS, $days ?: self::DEFAULT_DAYS));
    }

    /** The moment before which an attachment is eligible for deletion. */
    public function cutoff(): \Illuminate\Support\Carbon
    {
        return now()->subDays($this->days());
    }

    public function put(bool $enabled, ?int $days): void
    {
        Setting::set(self::KEY_ENABLED, $enabled ? '1' : '0');

        if ($days !== null) {
            Setting::set(self::KEY_DAYS, (string) max(self::MIN_DAYS, min(self::MAX_DAYS, $days)));
        }
    }

    /** Plain-language summary of the current policy, for the UI. */
    public function summary(): string
    {
        if (!$this->enabled()) {
            return 'Chat attachments are kept indefinitely.';
        }

        $days = $this->days();

        return 'Chat attachments are deleted ' . $days . ' ' . ($days === 1 ? 'day' : 'days')
            . ' after they were sent. The messages themselves are kept.';
    }
}
