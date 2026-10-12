<?php

namespace App\Services\Jobs\Connectors;

use App\Enums\SourceType;
use App\Models\JobSource;
use App\Services\Jobs\NormalizedJob;
use App\Support\Text;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Optional Adzuna connector. It stays UNCONFIGURED (and never calls Adzuna) until the owner
 * supplies their own app id/key AND explicitly confirms that their intended use is allowed by
 * Adzuna's terms and quota. We do not assume any free quota exists or will keep existing:
 * the UI shows how many requests this app has made, and the owner checks Adzuna's dashboard
 * for the real limits.
 */
class AdzunaConnector extends AbstractConnector
{
    public const COUNTRIES = ['gb', 'us', 'au', 'at', 'be', 'br', 'ca', 'ch', 'de', 'es', 'fr', 'in', 'it', 'mx', 'nl', 'nz', 'pl', 'sg', 'za'];

    private const BASE = 'https://api.adzuna.com/v1/api/jobs';

    public function type(): SourceType
    {
        return SourceType::Adzuna;
    }

    public function minIntervalMinutes(): int
    {
        return 360; // deliberately gentle: a few requests per day
    }

    public function validateConfig(array $input): array
    {
        $country = strtolower(trim((string) ($input['country'] ?? '')));
        $errors = [];
        if (! in_array($country, self::COUNTRIES, true)) {
            $errors['country'] = 'Choose one of the supported Adzuna country codes.';
        }
        $what = trim((string) ($input['what'] ?? ''));
        if ($what === '' || mb_strlen($what) > 120) {
            $errors['what'] = 'Enter search keywords (max 120 characters), e.g. "laravel developer".';
        }
        $appId = trim((string) ($input['app_id'] ?? '')) ?: (string) config('jobpilot.adzuna.app_id');
        $appKey = trim((string) ($input['app_key'] ?? '')) ?: (string) config('jobpilot.adzuna.app_key');
        if ($appId === '' || $appKey === '') {
            $errors['app_id'] = 'Adzuna app id and key are required (enter them here or set ADZUNA_APP_ID / ADZUNA_APP_KEY).';
        }
        if (! filter_var($input['usage_confirmed'] ?? false, FILTER_VALIDATE_BOOL)) {
            $errors['usage_confirmed'] = 'Please confirm that you have checked Adzuna\'s terms and quota for your intended use.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => trim((string) ($input['name'] ?? '')) ?: Str::limit('Adzuna '.strtoupper($country).': '.$what, 80, ''),
            'config' => [
                'country' => $country,
                'what' => $what,
                'where' => Str::limit(trim((string) ($input['where'] ?? '')), 80, ''),
                'remote_only' => filter_var($input['remote_only'] ?? true, FILTER_VALIDATE_BOOL),
                'max_pages' => max(1, min(3, (int) ($input['max_pages'] ?? 1))),
                'app_id' => $appId,
                'app_key' => $appKey,
                'usage_confirmed' => true,
            ],
        ];
    }

    public function healthCheck(JobSource $source): HealthResult
    {
        if (! $source->cfg('usage_confirmed') || ! $source->cfg('app_id') || ! $source->cfg('app_key')) {
            return HealthResult::unconfigured('Add your Adzuna credentials and confirm permitted usage to enable this source.');
        }

        return parent::healthCheck($source);
    }

    public function fetchJobs(JobSource $source): FetchResult
    {
        if (! $source->cfg('usage_confirmed') || ! $source->cfg('app_id') || ! $source->cfg('app_key')) {
            throw new SourceFetchException('Adzuna is not configured: credentials or usage confirmation missing.');
        }

        $country = (string) $source->cfg('country');
        if (! in_array($country, self::COUNTRIES, true)) {
            throw new SourceFetchException('Unsupported Adzuna country code.');
        }

        $what = trim((string) $source->cfg('what').($source->cfg('remote_only') ? ' remote' : ''));
        $jobs = [];
        $skipped = 0;
        $requests = 0;

        for ($page = 1; $page <= (int) $source->cfg('max_pages', 1); $page++) {
            if ($page > 1) {
                $this->pace();
            }
            $data = $this->getJson(self::BASE."/{$country}/search/{$page}", array_filter([
                'app_id' => $source->cfg('app_id'),
                'app_key' => $source->cfg('app_key'),
                'results_per_page' => 30,
                'what' => $what,
                'where' => $source->cfg('where'),
                'content-type' => 'application/json',
            ]));
            $requests++;

            $rows = $data['results'] ?? null;
            if (! is_array($rows)) {
                throw new SourceFetchException('Unexpected Adzuna response format.');
            }
            foreach ($rows as $raw) {
                $job = is_array($raw) ? $this->normalizeJob($raw, $source) : null;
                $job ? $jobs[] = $job : $skipped++;
            }
            if (count($rows) < 30) {
                break;
            }
        }

        return new FetchResult($jobs, meta: ['requests' => $requests], skipped: $skipped);
    }

    public function normalizeJob(array $raw, JobSource $source): ?NormalizedJob
    {
        $title = trim(strip_tags((string) ($raw['title'] ?? '')));
        $url = (string) ($raw['redirect_url'] ?? '');
        if (empty($raw['id']) || $title === '' || ! preg_match('~^https?://~i', $url)) {
            return null;
        }

        $predicted = (string) ($raw['salary_is_predicted'] ?? '0') === '1';
        $min = ! $predicted && ! empty($raw['salary_min']) ? (int) round($raw['salary_min']) : null;
        $max = ! $predicted && ! empty($raw['salary_max']) ? (int) round($raw['salary_max']) : null;

        $job = new NormalizedJob(
            title: $title,
            url: $url,
            externalId: (string) $raw['id'],
            company: trim((string) ($raw['company']['display_name'] ?? '')) ?: null,
            // Adzuna returns only a snippet; the full text lives on the destination page.
            description: Text::htmlToText((string) ($raw['description'] ?? '')),
            location: trim((string) ($raw['location']['display_name'] ?? '')) ?: null,
            employmentType: $this->employmentType(($raw['contract_time'] ?? '').' '.($raw['contract_type'] ?? '')),
            salaryMin: $min,
            salaryMax: $max,
            salaryCurrency: $min || $max ? $this->currency((string) $source->cfg('country')) : null,
            salaryPeriod: $min || $max ? 'year' : null,
            postedAt: $this->date($raw['created'] ?? null),
            meta: array_filter(['snippet_only' => true, 'category' => $raw['category']['label'] ?? null, 'attribution' => 'Adzuna']),
        );

        return $this->withLocation($job);
    }

    private function currency(string $country): string
    {
        return match ($country) {
            'gb' => 'GBP', 'us' => 'USD', 'au' => 'AUD', 'ca' => 'CAD', 'in' => 'INR', 'nz' => 'NZD', 'sg' => 'SGD', 'za' => 'ZAR', 'br' => 'BRL', 'mx' => 'MXN', 'pl' => 'PLN', 'ch' => 'CHF',
            default => 'EUR',
        };
    }
}
