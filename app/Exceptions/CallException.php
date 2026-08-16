<?php

namespace App\Exceptions;

use Exception;

/** A call action that cannot proceed — surfaced to the caller as a 422/409. */
class CallException extends Exception
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
