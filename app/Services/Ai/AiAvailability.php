<?php

namespace App\Services\Ai;

use Illuminate\Support\Carbon;

final class AiAvailability
{
    public function __construct(
        public readonly bool $available,
        public readonly string $state,           // ready|disabled|not_configured|no_consent|budget_exhausted|paused
        public readonly string $message,
        public readonly ?Carbon $retryAt = null,
    ) {}

    public static function ready(): self
    {
        return new self(true, 'ready', 'Gemini is ready.');
    }

    public static function from(AiException $e): self
    {
        return new self(false, $e->reason, $e->getMessage(), $e->retryAt);
    }
}
