<?php

namespace App\Services\Jobs\Connectors;

use App\Models\JobSource;
use App\Services\Ai\Redactor;
use App\Services\Http\SafeHttp;
use App\Services\Http\SafeResponse;
use App\Services\Http\UnsafeUrlException;
use App\Services\Jobs\LocationParser;
use App\Services\Jobs\NormalizedJob;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

abstract class AbstractConnector implements SourceConnector
{
    public function __construct(
        protected readonly SafeHttp $http,
        protected readonly LocationParser $locations,
    ) {}

    public function minIntervalMinutes(): int
    {
        return 30;
    }

    public function isFullListing(): bool
    {
        return false;
    }

    public function healthCheck(JobSource $source): HealthResult
    {
        try {
            $result = $this->fetchJobs($source);

            return HealthResult::ok($result->notModified
                ? 'Source reachable (no changes since last fetch).'
                : count($result->jobs).' job(s) readable.', $result->httpStatus);
        } catch (SourceFetchException $e) {
            return HealthResult::error($e->getMessage(), $e->httpStatus);
        }
    }

    /**
     * GET with uniform error mapping. The returned exception messages never contain
     * query strings (which may hold credentials).
     *
     * @param  array<string, scalar|list<scalar>>  $query
     * @param  array<string, string>  $headers
     */
    protected function get(string $url, array $query = [], array $headers = [], ?int $maxBytes = null): SafeResponse
    {
        try {
            $response = $this->http->get($url, $headers, $query, $maxBytes);
        } catch (UnsafeUrlException $e) {
            throw new SourceFetchException($e->getMessage());
        } catch (ConnectionException $e) {
            throw new SourceFetchException('Could not connect: '.Redactor::scrubSecrets(Str::before($e->getMessage(), ' for http')));
        } catch (Throwable $e) {
            throw new SourceFetchException('Unexpected network error ('.class_basename($e).').');
        }

        $this->assertSuccess($response);

        return $response;
    }

    protected function assertSuccess(SafeResponse $r): void
    {
        if ($r->status === 304 || $r->ok()) {
            return;
        }
        throw new SourceFetchException(match (true) {
            $r->status === 404 => 'Not found (HTTP 404). Check the identifier you entered.',
            in_array($r->status, [401, 403], true) => "Access refused (HTTP {$r->status}). The source may not allow this access.",
            $r->status === 429 => 'The source is rate limiting requests (HTTP 429). Will retry later.',
            $r->status >= 500 => "The source had a server problem (HTTP {$r->status}).",
            default => "Unexpected response (HTTP {$r->status}).",
        }, $r->status);
    }

    /** @return array<string, mixed>|list<mixed> */
    protected function getJson(string $url, array $query = [], array $headers = []): array
    {
        $r = $this->get($url, $query, array_merge(['Accept' => 'application/json'], $headers));
        $data = $r->json();
        if ($data === null) {
            throw new SourceFetchException('The source did not return valid JSON.', $r->status);
        }

        return $data;
    }

    protected function pace(): void
    {
        $ms = (int) config('jobpilot.source_request_delay_ms', 400);
        if ($ms > 0 && ! app()->runningUnitTests()) {
            usleep($ms * 1000);
        }
    }

    protected function humanize(string $slug): string
    {
        return Str::title(str_replace(['-', '_'], ' ', $slug));
    }

    protected function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }
        try {
            if (is_numeric($value)) {
                $n = (int) $value;

                return Carbon::createFromTimestampUTC($n > 20_000_000_000 ? intdiv($n, 1000) : $n);
            }

            return Carbon::parse((string) $value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** Detect employment type words in free text. */
    protected function employmentType(string $text): string
    {
        $t = Str::lower($text);

        return match (true) {
            (bool) preg_match('/intern(ship)?\b/', $t) => 'internship',
            (bool) preg_match('/freelance|contractor|contract\b|temporary/', $t) => 'contract',
            (bool) preg_match('/part[- ]?time/', $t) => 'part_time',
            (bool) preg_match('/full[- ]?time|permanent/', $t) => 'full_time',
            default => 'unknown',
        };
    }

    /** Apply the location parser to a normalised job. */
    protected function withLocation(NormalizedJob $job, ?string $declaredWorkplace = null): NormalizedJob
    {
        $p = $this->locations->parse($job->location, $job->description, $declaredWorkplace);
        $job->isRemote = $job->isRemote || $p['is_remote'];
        $job->remoteScope = $p['scope'];
        $job->eligibleCountries = $p['countries'];

        return $job;
    }

    protected function slugRule(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{1,79}$/', $value);
    }
}
