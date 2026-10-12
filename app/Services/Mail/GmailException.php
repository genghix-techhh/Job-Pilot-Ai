<?php

namespace App\Services\Mail;

use RuntimeException;

/** Gmail/OAuth problem with a message that is safe to show (never contains tokens). */
class GmailException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $reauthRequired = false)
    {
        parent::__construct($message);
    }
}
