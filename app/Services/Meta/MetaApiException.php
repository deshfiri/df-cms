<?php

namespace App\Services\Meta;

use RuntimeException;

/**
 * A Meta API failure, classified enough for the caller to decide what to do.
 *
 * The raw message stays for the log; `userMessage()` is what a person is shown,
 * so token contents and internal traces never reach the screen.
 */
class MetaApiException extends RuntimeException
{
    public const KIND_TOKEN_EXPIRED = 'token_expired';
    public const KIND_PERMISSION    = 'permission';
    public const KIND_RATE_LIMITED  = 'rate_limited';
    public const KIND_NOT_FOUND     = 'not_found';
    public const KIND_UNAVAILABLE   = 'unavailable';
    public const KIND_UNKNOWN       = 'unknown';

    public function __construct(
        string $message,
        public readonly string $kind = self::KIND_UNKNOWN,
        public readonly ?int $metaCode = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Build from a Meta error payload.
     *
     * Codes per Meta's documented error reference: 190 is an invalid/expired
     * token, 4/17/32/613 are throttling, 10 and 200-series are permissions.
     */
    public static function fromResponse(array $error, ?int $httpStatus = null): self
    {
        $code    = (int) ($error['code'] ?? 0);
        $sub     = (int) ($error['error_subcode'] ?? 0);
        $message = (string) ($error['message'] ?? 'Unknown Meta API error');

        $kind = match (true) {
            $code === 190 || $sub === 463 || $sub === 467 => self::KIND_TOKEN_EXPIRED,
            in_array($code, [4, 17, 32, 613], true)       => self::KIND_RATE_LIMITED,
            $code === 10 || ($code >= 200 && $code < 300)  => self::KIND_PERMISSION,
            $code === 803 || $httpStatus === 404           => self::KIND_NOT_FOUND,
            $httpStatus !== null && $httpStatus >= 500     => self::KIND_UNAVAILABLE,
            default                                        => self::KIND_UNKNOWN,
        };

        return new self($message, $kind, $code ?: null, $httpStatus);
    }

    /** Worth trying again later rather than bothering a human. */
    public function isRetryable(): bool
    {
        return in_array($this->kind, [self::KIND_RATE_LIMITED, self::KIND_UNAVAILABLE], true);
    }

    /** Needs a person to reconnect the account. */
    public function needsReconnect(): bool
    {
        return in_array($this->kind, [self::KIND_TOKEN_EXPIRED, self::KIND_PERMISSION], true);
    }

    public function userMessage(): string
    {
        return match ($this->kind) {
            self::KIND_TOKEN_EXPIRED => 'The Meta connection has expired. Reconnect the account to resume syncing.',
            self::KIND_PERMISSION    => 'Meta refused access to this data. Check the permissions granted when connecting.',
            self::KIND_RATE_LIMITED  => 'Meta is rate limiting requests. The next scheduled sync will pick up where this stopped.',
            self::KIND_NOT_FOUND     => 'That resource no longer exists on Meta.',
            self::KIND_UNAVAILABLE   => 'Meta is temporarily unavailable. The next scheduled sync will retry.',
            default                  => 'Meta could not complete the request. The technical details are in the sync log.',
        };
    }
}
