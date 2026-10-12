<?php

namespace App\Services\Jobs\Connectors;

use App\Services\Jobs\NormalizedJob;

final class FetchResult
{
    /**
     * @param  list<NormalizedJob>  $jobs
     * @param  array<string, mixed>  $meta  e.g. http_cache validators, feed ttl, request count
     */
    public function __construct(
        public readonly array $jobs,
        public readonly bool $notModified = false,
        public readonly ?int $httpStatus = 200,
        public readonly array $meta = [],
        public readonly int $skipped = 0,
    ) {}
}
