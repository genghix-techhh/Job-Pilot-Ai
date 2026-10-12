<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CandidateProfile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'skills' => 'array',
            'experience_levels' => 'array',
            'remote_preferences' => 'array',
            'salary_preferences' => 'array',
            'employment_types' => 'array',
            'structured' => 'array',
            'pending_extraction' => 'array',
            'years_experience' => 'float',
            'reviewed_at' => 'datetime',
            'ai_consent' => 'boolean',
            'privacy_consent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CandidateProfileVersion::class);
    }

    /** @return list<string> */
    public function skillList(): array
    {
        return array_values(array_filter(array_map('strval', $this->skills ?? [])));
    }

    public function hasContent(): bool
    {
        return count($this->skillList()) > 0 || ! empty($this->structured['work_history'] ?? []);
    }

    /** True when the owner has given current-version consent to free-tier AI processing. */
    public function aiConsentGiven(): bool
    {
        return $this->ai_consent === true
            && $this->privacy_consent_version === config('jobpilot.privacy_version');
    }
}
