<?php

namespace App\Services\WhatsApp;

use RuntimeException;
use Throwable;

/**
 * Meta refused a WhatsApp API call.
 *
 * Carries two messages on purpose: {@see userMessage()} is fit to show an agent,
 * while getMessage() keeps the provider detail for the log. Neither ever contains
 * a token — the client strips credentials before constructing this.
 */
class WhatsAppApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?string $userMessage = null,
        private readonly ?int $errorCode = null,
        private readonly ?int $errorSubcode = null,
        /** Whether retrying the same call could plausibly succeed. */
        private readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function userMessage(): string
    {
        return $this->userMessage ?: 'WhatsApp could not process that request. Please try again.';
    }

    public function errorCode(): ?int
    {
        return $this->errorCode;
    }

    public function errorSubcode(): ?int
    {
        return $this->errorSubcode;
    }

    /**
     * Retrying a permanent error just burns the queue and, for rate limits,
     * makes the situation worse — so this is what the send job consults before
     * releasing a message back.
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
