<?php

namespace App\Services\Jobs\Connectors;

use App\Enums\SourceType;
use App\Models\JobSource;
use App\Services\Jobs\NormalizedJob;
use App\Services\Jobs\SalaryParser;
use App\Support\Text;
use Illuminate\Validation\ValidationException;

/**
 * Greenhouse public Job Board API (read-only, no authentication for GET).
 * https://boards-api.greenhouse.io/v1/boards/{token}/jobs?content=true
 *
 * The owner enters one board token per company. Submitting applications to Greenhouse
 * needs the employer's own API key, so this connector can never apply on your behalf.
 */
class GreenhouseConnector extends AbstractConnector
{
    private const BASE = 'https://boards-api.greenhouse.io/v1/boards';

    public function type(): SourceType
    {
        return SourceType::Greenhouse;
    }

    public function minIntervalMinutes(): int
    {
        return 30;
    }

    public function isFullListing(): bool
    {
        return true;
    }

    public function validateConfig(array $input): array
    {
        $token = trim((string) ($input['board_token'] ?? ''));
        if (! $this->slugRule($token)) {
            throw ValidationException::withMessages(['board_token' => 'Enter the company board token (letters, numbers, - and _), e.g. "stripe". It is the last part of boards.greenhouse.io/<token>.']);
        }

        return ['name' => trim((string) ($input['name'] ?? '')) ?: $this->humanize($token).' (Greenhouse)', 'config' => ['board_token' => $token]];
    }

    public function fetchJobs(JobSource $source): FetchResult
    {
        $token = (string) $source->cfg('board_token');
        if (! $this->slugRule($token)) {
            throw new SourceFetchException('This source has no valid board token.');
        }

        $company = $this->boardName($token) ?? $this->humanize($token);
        $data = $this->getJson(self::BASE."/{$token}/jobs", ['content' => 'true']);
        $rows = $data['jobs'] ?? null;
        if (! is_array($rows)) {
            throw new SourceFetchException('Unexpected Greenhouse response format.');
        }

        $jobs = [];
        $skipped = 0;
        foreach (array_slice($rows, 0, (int) config('jobpilot.max_jobs_per_source', 500)) as $raw) {
            $raw['_company'] = $company;
            $job = $this->normalizeJob($raw, $source);
            $job ? $jobs[] = $job : $skipped++;
        }

        return new FetchResult($jobs, skipped: $skipped);
    }

    public function normalizeJob(array $raw, JobSource $source): ?NormalizedJob
    {
        $id = $raw['id'] ?? null;
        $title = trim((string) ($raw['title'] ?? ''));
        $url = (string) ($raw['absolute_url'] ?? '');
        if ($id === null || $title === '' || ! preg_match('~^https?://~i', $url)) {
            return null;
        }

        $description = Text::htmlToText((string) ($raw['content'] ?? ''));
        $location = trim((string) ($raw['location']['name'] ?? ''));

        $job = new NormalizedJob(
            title: $title,
            url: $url,
            externalId: (string) $id,
            company: $raw['company_name'] ?? $raw['_company'] ?? null,
            description: $description,
            location: $location ?: null,
            employmentType: $this->employmentType($title.' '.$description),
            updatedAt: $this->date($raw['updated_at'] ?? null),
            postedAt: $this->date($raw['first_published'] ?? null),
            meta: array_filter([
                'departments' => array_values(array_filter(array_map(fn ($d) => $d['name'] ?? null, $raw['departments'] ?? []))),
                'requisition_id' => $raw['requisition_id'] ?? null,
            ]),
        );

        if ($pay = SalaryParser::fromText($description)) {
            [$job->salaryMin, $job->salaryMax, $job->salaryCurrency, $job->salaryPeriod] = [$pay['min'], $pay['max'], $pay['currency'], $pay['period']];
        }

        return $this->withLocation($job);
    }

    /** Company display name from the board endpoint (fails soft). */
    private function boardName(string $token): ?string
    {
        try {
            $this->pace();
            $name = $this->getJson(self::BASE."/{$token}")['name'] ?? null;

            return is_string($name) && trim($name) !== '' ? trim($name) : null;
        } catch (SourceFetchException $e) {
            if ($e->httpStatus === 404) {
                throw $e; // wrong token: report it instead of silently continuing
            }

            return null;
        }
    }
}
