<?php

namespace App\Services\Jobs\Connectors;

final class HealthResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?int $httpStatus = null,
        public readonly string $status = 'ok', // ok|error|unconfigured
    ) {}

    public static function ok(string $message = 'Source reachable.', ?int $http = 200): self
    {
        return new self(true, $message, $http, 'ok');
    }

    public static function error(string $message, ?int $http = null): self
    {
        return new self(false, $message, $http, 'error');
    }

    public static function unconfigured(string $message): self
    {
        return new self(false, $message, null, 'unconfigured');
    }
}
