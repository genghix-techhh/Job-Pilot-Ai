<?php

namespace App\Services\Jobs;

use App\Models\JobPosting;
use App\Models\JobSource;
use App\Services\Matching\SkillCatalog;
use App\Support\Text;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Saves normalised jobs without creating duplicates.
 *
 * Identity, in order:
 *   1. (source_id, external_id) when the source provides a stable id;
 *   2. otherwise (source_id, fingerprint) where fingerprint = sha256(canonical URL | company | title).
 * Title alone is never used. The same posting reached through two different sources is linked
 * with `duplicate_of` and hidden from the feed rather than deleted.
 */
class JobUpserter
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    /** @return array{0: JobPosting, 1: string} the job and what happened to it */
    public function upsert(JobSource $source, NormalizedJob $n): array
    {
        $attrs = $this->attributes($n);
        $now = now();

        $existing = $this->find($source, $n->externalId, $attrs['fingerprint']);

        if ($existing) {
            [$job, $changed] = $this->refresh($existing, $attrs, $now);

            return [$job, $changed ? self::UPDATED : self::UNCHANGED];
        }

        try {
            $job = JobPosting::create($attrs + [
                'source_id' => $source->id,
                'external_id' => $n->externalId,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'state' => $attrs['expires_at'] && $attrs['expires_at']->isPast() ? 'expired' : 'active',
                'user_status' => 'new',
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent sync inserted the same job between our lookup and insert.
            $existing = $this->find($source, $n->externalId, $attrs['fingerprint']);
            if (! $existing) {
                throw new \RuntimeException('Job insert conflict could not be resolved.');
            }

            [$job] = $this->refresh($existing, $attrs, $now);

            return [$job, self::UNCHANGED];
        }

        $this->linkDuplicates($job);

        return [$job, self::CREATED];
    }

    private function find(JobSource $source, ?string $externalId, string $fingerprint): ?JobPosting
    {
        $q = JobPosting::query()->where('source_id', $source->id);

        if ($externalId !== null && $externalId !== '') {
            return $q->where('external_id', $externalId)->first();
        }

        return $q->whereNull('external_id')->where('fingerprint', $fingerprint)->first();
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array{0: JobPosting, 1: bool} the job and whether its content changed
     */
    private function refresh(JobPosting $job, array $attrs, Carbon $now): array
    {
        $changed = $job->description_checksum !== $attrs['description_checksum'];

        // Freshness bookkeeping happens on every sighting.
        $job->last_seen_at = $now;
        if ($job->state === 'expired' && (! $attrs['expires_at'] || $attrs['expires_at']->isFuture())) {
            $job->state = 'active';
            $changed = true;
        }
        if ($changed) {
            $job->fill($attrs);
        }
        $job->save();

        if ($changed) {
            $this->linkDuplicates($job);
        }

        return [$job, $changed];
    }

    private function linkDuplicates(JobPosting $job): void
    {
        $original = JobPosting::query()
            ->where('fingerprint', $job->fingerprint)
            ->where('source_id', '!=', $job->source_id)
            ->whereNull('duplicate_of')
            ->where('id', '<', $job->id)
            ->orderBy('id')
            ->first();

        if ($original && $job->duplicate_of !== $original->id) {
            $job->forceFill(['duplicate_of' => $original->id])->save();
        }
    }

    /** @return array<string, mixed> */
    public function attributes(NormalizedJob $n): array
    {
        $title = Str::limit(Text::normalizeWhitespace(strip_tags($n->title)), 250, '');
        $company = $n->company !== null ? Str::limit(trim(Text::repairMojibake($n->company)), 250, '') : null;
        // Defence in depth: whatever a connector passes in, only plain text is ever stored.
        $looksLikeHtml = (bool) preg_match('/<\/?[a-z][a-z0-9]*(\s[^>]*)?>/i', $n->description);
        $description = Text::limit($looksLikeHtml ? Text::htmlToText($n->description) : Text::normalizeWhitespace($n->description), 100_000);
        $canonical = Text::canonicalUrl($n->url) ?? $n->url;

        $min = $n->salaryMin;
        $max = $n->salaryMax;
        if ($min && $max && $max < $min) {
            [$min, $max] = [$max, $min];
        }

        $skills = SkillCatalog::extract($title."\n".$description);

        return [
            'kind' => $n->kind === 'freelance' ? 'freelance' : 'employment',
            'url' => Str::limit($n->url, 2000, ''),
            'canonical_url' => Str::limit($canonical, 2000, ''),
            'company' => $company ?: null,
            'title' => $title,
            'description' => $description,
            'description_checksum' => hash('sha256', implode('|', [$title, $company, $description, $n->location, $min, $max, $n->salaryCurrency, $n->salaryPeriod])),
            'location' => $n->location ? Str::limit(Text::repairMojibake($n->location), 250, '') : null,
            'is_remote' => $n->isRemote,
            'remote_scope' => $n->remoteScope,
            'eligible_countries' => $n->eligibleCountries ?: null,
            'employment_type' => $n->employmentType,
            'salary_min' => $min,
            'salary_max' => $max,
            'salary_currency' => $n->salaryCurrency ? strtoupper(substr($n->salaryCurrency, 0, 3)) : null,
            'salary_period' => $n->salaryPeriod,
            'posted_at' => $n->postedAt,
            'source_updated_at' => $n->updatedAt,
            'expires_at' => $n->expiresAt,
            'fingerprint' => hash('sha256', $canonical.'|'.Text::normalizeKey($company).'|'.Text::normalizeKey($title)),
            'skills' => $skills ?: null,
            'meta' => $n->meta ?: null,
            'needs_details' => $n->needsDetails,
            'is_manual' => $n->isManual,
        ];
    }
}
