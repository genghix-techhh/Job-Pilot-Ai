<?php

namespace App\Services\Jobs;

use Carbon\CarbonInterface;

/** Source-independent shape every connector produces. */
final class NormalizedJob
{
    /**
     * @param  list<string>  $eligibleCountries
     * @param  array<string, mixed>  $meta  small, allowed extras only (tags, department ...)
     */
    public function __construct(
        public string $title,
        public string $url,
        public ?string $externalId = null,
        public ?string $company = null,
        public string $description = '',
        public ?string $location = null,
        public bool $isRemote = false,
        public string $remoteScope = 'unclear',
        public array $eligibleCountries = [],
        public string $employmentType = 'unknown',
        public string $kind = 'employment',
        public ?int $salaryMin = null,
        public ?int $salaryMax = null,
        public ?string $salaryCurrency = null,
        public ?string $salaryPeriod = null,
        public ?CarbonInterface $postedAt = null,
        public ?CarbonInterface $updatedAt = null,
        public ?CarbonInterface $expiresAt = null,
        public array $meta = [],
        public bool $needsDetails = false,
        public bool $isManual = false,
    ) {}
}
