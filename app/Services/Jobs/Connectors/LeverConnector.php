<?php

namespace App\Services\Jobs\Connectors;

use App\Enums\SourceType;
use App\Models\JobSource;
use App\Services\Jobs\NormalizedJob;
use App\Services\Matching\Geo;
use App\Support\Text;
use Illuminate\Validation\ValidationException;

/**
 * Lever public Postings API (read-only). Global: api.lever.co, EU: api.eu.lever.co.
 * Applying through Lever's API requires the employer's API key, so this connector
 * never submits anything — you apply on the hosted Lever page.
 */
class LeverConnector extends AbstractConnector
{
    private const PAGE = 100;

    private const MAX_PAGES = 6;

    public function type(): SourceType
    {
        return SourceType::Lever;
    }

    public function isFullListing(): bool
    {
        return true;
    }

    public function validateConfig(array $input): array
    {
        $site = trim((string) ($input['site'] ?? ''));
        $region = ($input['region'] ?? 'global') === 'eu' ? 'eu' : 'global';
        if (! $this->slugRule($site)) {
            throw ValidationException::withMessages(['site' => 'Enter the Lever site name (letters, numbers, - and _), e.g. "netflix". It is the last part of jobs.lever.co/<site>.']);
        }

        return ['name' => trim((string) ($input['name'] ?? '')) ?: $this->humanize($site).' (Lever)', 'config' => ['site' => $site, 'region' => $region]];
    }

    public function fetchJobs(JobSource $source): FetchResult
    {
        $site = (string) $source->cfg('site');
        if (! $this->slugRule($site)) {
            throw new SourceFetchException('This source has no valid Lever site name.');
        }
        $base = $source->cfg('region') === 'eu' ? 'https://api.eu.lever.co/v0/postings/' : 'https://api.lever.co/v0/postings/';
        $company = $this->humanize($site);

        $jobs = [];
        $skipped = 0;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            if ($page > 0) {
                $this->pace();
            }
            $rows = $this->getJson($base.$site, ['mode' => 'json', 'limit' => self::PAGE, 'skip' => $page * self::PAGE]);
            if (! array_is_list($rows)) {
                throw new SourceFetchException('Unexpected Lever response format.');
            }
            foreach ($rows as $raw) {
                $raw['_company'] = $company;
                $job = is_array($raw) ? $this->normalizeJob($raw, $source) : null;
                $job ? $jobs[] = $job : $skipped++;
            }
            if (count($rows) < self::PAGE) {
                break;
            }
        }

        return new FetchResult(array_slice($jobs, 0, (int) config('jobpilot.max_jobs_per_source', 500)), skipped: $skipped);
    }

    public function normalizeJob(array $raw, JobSource $source): ?NormalizedJob
    {
        $id = $raw['id'] ?? null;
        $title = trim((string) ($raw['text'] ?? ''));
        $url = (string) ($raw['hostedUrl'] ?? '');
        if ($id === null || $title === '' || ! preg_match('~^https?://~i', $url)) {
            return null;
        }

        // Combine the plain-text parts (opening, body, requirement lists, closing).
        $parts = [(string) ($raw['descriptionPlain'] ?? Text::htmlToText($raw['description'] ?? ''))];
        foreach ($raw['lists'] ?? [] as $list) {
            $parts[] = trim(($list['text'] ?? '')."\n".Text::htmlToText($list['content'] ?? ''));
        }
        $parts[] = (string) ($raw['additionalPlain'] ?? Text::htmlToText($raw['additional'] ?? ''));
        $description = Text::normalizeWhitespace(implode("\n\n", array_filter($parts)));

        $cat = $raw['categories'] ?? [];
        $location = trim((string) ($cat['location'] ?? '')) ?: implode(', ', array_slice((array) ($cat['allLocations'] ?? []), 0, 4));
        $workplace = (string) ($raw['workplaceType'] ?? '');

        $job = new NormalizedJob(
            title: $title,
            url: $url,
            externalId: (string) $id,
            company: $raw['_company'] ?? null,
            description: $description,
            location: $location ?: null,
            employmentType: $this->employmentType((string) ($cat['commitment'] ?? '').' '.$title),
            postedAt: $this->date($raw['createdAt'] ?? null),
            meta: array_filter([
                'team' => $cat['team'] ?? null,
                'department' => $cat['department'] ?? null,
                'apply_url' => $raw['applyUrl'] ?? null,
                'workplace_type' => $workplace ?: null,
            ]),
        );

        if (is_array($sr = $raw['salaryRange'] ?? null) && (! empty($sr['min']) || ! empty($sr['max']))) {
            $job->salaryMin = isset($sr['min']) ? (int) $sr['min'] : null;
            $job->salaryMax = isset($sr['max']) ? (int) $sr['max'] : null;
            $job->salaryCurrency = isset($sr['currency']) ? strtoupper(substr((string) $sr['currency'], 0, 3)) : null;
            $job->salaryPeriod = $this->interval((string) ($sr['interval'] ?? ''));
        }

        $job = $this->withLocation($job, $workplace);

        // Lever gives an ISO country for the posting; use it when the text named none.
        if ($job->eligibleCountries === [] && ($raw['country'] ?? null) && Geo::fromIso($raw['country'])) {
            $job->eligibleCountries = [Geo::fromIso($raw['country'])];
            $job->remoteScope = $job->remoteScope === 'unclear' ? 'country' : $job->remoteScope;
        }

        return $job;
    }

    private function interval(string $s): ?string
    {
        $s = strtolower($s);

        return match (true) {
            str_contains($s, 'year') || str_contains($s, 'annual') => 'year',
            str_contains($s, 'month') => 'month',
            str_contains($s, 'week') => 'week',
            str_contains($s, 'day') => 'day',
            str_contains($s, 'hour') => 'hour',
            default => null,
        };
    }
}
