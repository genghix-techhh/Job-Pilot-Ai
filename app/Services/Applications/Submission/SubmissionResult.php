<?php

namespace App\Services\Applications\Submission;

final class SubmissionResult
{
    private function __construct(
        public readonly bool $verified,
        public readonly ?string $reference,
        public readonly ?string $error,
    ) {}

    /** Only for a response that positively confirms receipt (e.g. 2xx with an application id). */
    public static function confirmed(string $reference): self
    {
        return new self(true, $reference, null);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
