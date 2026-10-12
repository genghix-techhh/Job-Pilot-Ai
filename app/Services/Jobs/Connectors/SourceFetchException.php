<?php

namespace App\Services\Jobs\Connectors;

use RuntimeException;

/** A source could not be read. The message is safe to show in the UI and store. */
class SourceFetchException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
