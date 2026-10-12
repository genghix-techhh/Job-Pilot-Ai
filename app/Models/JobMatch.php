<?php

namespace App\Models;

use App\Enums\EligibilityStatus;
use App\Enums\MatchTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobMatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'tier' => MatchTier::class,
            'eligibility_status' => EligibilityStatus::class,
            'factors' => 'array',
            'matched_skills' => 'array',
            'missing_skills' => 'array',
            'ai_analysis' => 'array',
            'freelance_fit' => 'array',
            'cached_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class, 'job_id');
    }
}
