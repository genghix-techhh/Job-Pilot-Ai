<?php

namespace App\Services\Jobs;

use App\Enums\SourceType;
use App\Models\JobPosting;
use App\Models\JobSource;
use App\Services\Matching\MatchService;
use App\Support\Text;
use Illuminate\Validation\ValidationException;

/**
 * Manual import / bookmark. The URL is only stored as a link: it is NEVER fetched or crawled.
 * Jobs saved with just a URL are marked "needs details" so the owner can fill in the rest.
 */
class ManualJobService
{
    public function __construct(
        private readonly JobUpserter $upserter,
        private readonly MatchService $matches,
    ) {}

    public static function source(): JobSource
    {
        return JobSource::firstOrCreate(
            ['type' => SourceType::Manual->value, 'name' => 'Manual import'],
            ['enabled' => true, 'health_status' => 'ok']
        );
    }

    /**
     * @param  array{url: string, title?: ?string, company?: ?string, description?: ?string, location?: ?string, kind?: ?string, remote?: mixed, salary_min?: mixed, salary_max?: mixed, salary_currency?: ?string}  $d
     * @return array{job: JobPosting, created: bool}
     */
    public function add(array $d): array
    {
        $url = trim((string) ($d['url'] ?? ''));
        $parts = parse_url($url);
        if (! $parts || empty($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['url' => 'Enter a valid http(s) link to the job posting.']);
        }

        $title = trim((string) ($d['title'] ?? ''));
        $description = trim((string) ($d['description'] ?? ''));
        $needsDetails = $title === '' || mb_strlen($description) < 40;
        if ($title === '') {
            $title = 'Saved link: '.preg_replace('/^www\./', '', strtolower($parts['host']));
        }

        $normalized = new NormalizedJob(
            title: $title,
            url: $url,
            externalId: null,
            company: trim((string) ($d['company'] ?? '')) ?: null,
            description: Text::htmlToText($description),
            location: trim((string) ($d['location'] ?? '')) ?: null,
            isRemote: filter_var($d['remote'] ?? false, FILTER_VALIDATE_BOOL),
            employmentType: ($d['kind'] ?? '') === 'freelance' ? 'contract' : 'unknown',
            kind: ($d['kind'] ?? '') === 'freelance' ? 'freelance' : 'employment',
            salaryMin: isset($d['salary_min']) && $d['salary_min'] !== '' ? (int) $d['salary_min'] : null,
            salaryMax: isset($d['salary_max']) && $d['salary_max'] !== '' ? (int) $d['salary_max'] : null,
            salaryCurrency: $d['salary_currency'] ?? null,
            salaryPeriod: ! empty($d['salary_min']) || ! empty($d['salary_max']) ? 'year' : null,
            postedAt: now(),
            meta: ['host' => strtolower($parts['host'])],
            needsDetails: $needsDetails,
            isManual: true,
        );

        [$job, $outcome] = $this->upserter->upsert(self::source(), $normalized);
        // Saving a link again brings back a posting the owner had hidden.
        if ($job->refresh()->user_status === 'hidden') {
            $job->forceFill(['user_status' => 'saved'])->save();
        }

        $this->matches->scoreMany([$job], notify: false);

        return ['job' => $job, 'created' => $outcome === JobUpserter::CREATED];
    }
}
