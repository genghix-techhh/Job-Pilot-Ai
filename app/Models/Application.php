<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'screening_answers' => 'array',
            'artifacts' => 'array',
            'approved_hashes' => 'array',
            'approved_at' => 'datetime',
            'submitted_at' => 'datetime',
            'next_task_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class, 'job_id');
    }

    public function resume(): BelongsTo
    {
        return $this->belongsTo(Resume::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApplicationEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InboundMessage::class);
    }

    /** SHA-256 fingerprints of the documents the owner is approving. */
    public function documentHashes(): array
    {
        return [
            'cover_letter' => hash('sha256', (string) $this->cover_letter),
            'tailored_resume' => hash('sha256', (string) $this->tailored_resume),
            'screening_answers' => hash('sha256', json_encode($this->screening_answers ?? [])),
        ];
    }

    /** True when the approved documents are exactly what is stored now. */
    public function approvalIsCurrent(): bool
    {
        return $this->approved_at !== null
            && is_array($this->approved_hashes)
            && $this->approved_hashes === $this->documentHashes()
            && $this->approved_url === $this->job?->url;
    }
}
