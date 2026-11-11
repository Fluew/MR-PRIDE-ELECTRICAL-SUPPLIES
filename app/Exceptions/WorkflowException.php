<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business rule stopped an action (wrong status, not enough stock, duplicate reference…).
 * The message is written for staff and is safe to show; nothing was saved.
 */
class WorkflowException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'error')
    {
        parent::__construct($message);
    }
}
