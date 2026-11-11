<?php

namespace App\Services\MaterialList;

/** A problem with an uploaded material list. The message is safe to show to the client. */
class ExtractionException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'failed')
    {
        parent::__construct($message);
    }
}
