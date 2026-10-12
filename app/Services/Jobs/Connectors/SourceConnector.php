<?php

namespace App\Services\Jobs\Connectors;

use App\Enums\SourceType;
use App\Models\JobSource;
use App\Services\Jobs\NormalizedJob;
use Illuminate\Validation\ValidationException;

/**
 * Contract every job-source adapter implements. Adapters only READ from sources that
 * permit it; none of them can submit an application.
 */
interface SourceConnector
{
    public function type(): SourceType;

    /**
     * Validate and normalise what the owner typed into the "add source" form.
     *
     * @param  array<string, mixed>  $input
     * @return array{name: string, config: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function validateConfig(array $input): array;

    /** Fetch the raw listing and normalise it. Must throw SourceFetchException on failure. */
    public function fetchJobs(JobSource $source): FetchResult;

    /**
     * Map one raw record to the common shape (null = skip the record).
     *
     * @param  array<string, mixed>  $raw
     */
    public function normalizeJob(array $raw, JobSource $source): ?NormalizedJob;

    /** Lightweight "is this source reachable and configured correctly?" check. */
    public function healthCheck(JobSource $source): HealthResult;

    /** Never poll more often than this, whatever the global scan interval is. */
    public function minIntervalMinutes(): int;

    /**
     * True when one successful fetch returns the source's entire current list, so jobs
     * missing from it can be treated as closed.
     */
    public function isFullListing(): bool;
}
