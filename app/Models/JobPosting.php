<?php

namespace App\Models;

use App\Enums\JobKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A normalised job or freelance opportunity. Named JobPosting (table job_postings)
 * to avoid clashing with Laravel's queue `jobs` table.
 */
class JobPosting extends Model
{
    protected $table = 'job_postings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => JobKind::class,
            'eligible_countries' => 'array',
            'skills' => 'array',
            'meta' => 'array',
            'ai_requirements' => 'array',
            'is_remote' => 'boolean',
            'needs_details' => 'boolean',
            'is_manual' => 'boolean',
            'posted_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'expires_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'salary_min' => 'integer',
            'salary_max' => 'integer',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(JobSource::class, 'source_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(JobMatch::class, 'job_id');
    }

    public function application(): HasOne
    {
        return $this->hasOne(Application::class, 'job_id');
    }

    public function isExpired(): bool
    {
        return $this->state === 'expired';
    }

    public function hasSalary(): bool
    {
        return $this->salary_min || $this->salary_max;
    }

    public function salaryLabel(): ?string
    {
        if (! $this->hasSalary()) {
            return null;
        }
        $cur = $this->salary_currency ?: '';
        $fmt = fn (?int $n) => $n ? number_format($n) : null;
        $range = collect([$fmt($this->salary_min), $fmt($this->salary_max)])->filter()->unique()->implode(' – ');
        $period = $this->salary_period ? ' / '.$this->salary_period : '';

        return trim($cur.' '.$range).$period;
    }

    /** The latest posting date we know of, falling back to when we first saw it. */
    public function freshnessDate(): Carbon
    {
        return $this->posted_at ?? $this->source_updated_at ?? $this->first_seen_at;
    }
}
