<?php

namespace App\Services\Jobs\Connectors;

use App\Enums\SourceType;
use App\Models\JobSource;
use App\Services\Jobs\NormalizedJob;
use App\Support\Text;
use Illuminate\Support\Str;

/**
 * Remote OK public feed (https://remoteok.com/api).
 *
 * Remote OK's terms require linking back to the listing on Remote OK and naming it
 * as the source. We therefore keep the Remote OK listing URL as the job's primary link,
 * display "Remote OK" as the source everywhere, cache results (>= 2h between fetches)
 * and never re-publish the data.
 */
class RemoteOkConnector extends AbstractConnector
{
    private const ENDPOINT = 'https://remoteok.com/api';

    public function type(): SourceType
    {
        return SourceType::RemoteOk;
    }

    public function minIntervalMinutes(): int
    {
        return 120;
    }

    public function validateConfig(array $input): array
    {
        $raw = $input['keywords'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[,\n]+/', $raw) ?: [];
        }
        $keywords = collect($raw)->map(fn ($k) => Str::lower(trim((string) $k)))->filter()
            ->map(fn ($k) => Str::limit($k, 40, ''))->unique()->values()->take(20)->all();

        return [
            'name' => trim((string) ($input['name'] ?? '')) ?: 'Remote OK',
            'config' => ['keywords' => $keywords],
        ];
    }

    public function fetchJobs(JobSource $source): FetchResult
    {
        $data = $this->getJson(self::ENDPOINT);
        if (! array_is_list($data)) {
            throw new SourceFetchException('Unexpected Remote OK response format.');
        }

        $keywords = (array) $source->cfg('keywords', []);
        $jobs = [];
        $skipped = 0;
        foreach ($data as $raw) {
            // Element 0 is the legal/terms notice, not a job.
            if (! is_array($raw) || isset($raw['legal']) || empty($raw['id'])) {
                continue;
            }
            $haystack = Str::lower(($raw['position'] ?? '').' '.implode(' ', (array) ($raw['tags'] ?? [])));
            if ($keywords !== [] && ! collect($keywords)->contains(fn ($k) => str_contains($haystack, $k))) {
                $skipped++;

                continue;
            }
            $job = $this->normalizeJob($raw, $source);
            $job ? $jobs[] = $job : $skipped++;
        }

        return new FetchResult(array_slice($jobs, 0, (int) config('jobpilot.max_jobs_per_source', 500)), skipped: $skipped);
    }

    public function normalizeJob(array $raw, JobSource $source): ?NormalizedJob
    {
        $title = trim((string) ($raw['position'] ?? ''));
        $listing = (string) ($raw['url'] ?? '');
        if ($title === '' || empty($raw['id'])) {
            return null;
        }
        // Attribution rule: the primary link must be the Remote OK listing.
        if (! preg_match('~^https?://(www\.)?remoteok\.(com|io)/~i', $listing)) {
            $slug = (string) ($raw['slug'] ?? '');
            if ($slug === '') {
                return null;
            }
            $listing = 'https://remoteok.com/remote-jobs/'.rawurlencode($slug);
        }

        $tags = array_values(array_filter(array_map('strval', (array) ($raw['tags'] ?? []))));
        $min = (int) ($raw['salary_min'] ?? 0);
        $max = (int) ($raw['salary_max'] ?? 0);
        $description = Text::htmlToText((string) ($raw['description'] ?? ''));

        $job = new NormalizedJob(
            title: $title,
            url: $listing,
            externalId: (string) $raw['id'],
            company: trim((string) ($raw['company'] ?? '')) ?: null,
            description: $description,
            location: trim((string) ($raw['location'] ?? '')) ?: null,
            isRemote: true,
            employmentType: $this->employmentType(implode(' ', $tags).' '.$title),
            salaryMin: $min > 0 ? $min : null,
            salaryMax: $max > 0 ? $max : null,
            salaryCurrency: $min > 0 || $max > 0 ? 'USD' : null,
            salaryPeriod: $min > 0 || $max > 0 ? 'year' : null,
            postedAt: $this->date($raw['date'] ?? $raw['epoch'] ?? null),
            meta: array_filter([
                'tags' => array_slice($tags, 0, 15),
                'apply_url' => $raw['apply_url'] ?? null,
                'attribution' => 'Remote OK',
            ]),
        );

        $job = $this->withLocation($job, 'remote');
        $job->isRemote = true;

        return $job;
    }
}
