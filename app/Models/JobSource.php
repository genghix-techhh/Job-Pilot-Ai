<?php

namespace App\Models;

use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobSource extends Model
{
    protected $guarded = ['id'];

    /** Config can contain credentials (e.g. Adzuna), so it is encrypted at rest and never serialised. */
    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'config' => 'encrypted:array',
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'next_sync_at' => 'datetime',
            'last_stats' => 'array',
        ];
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(JobPosting::class, 'source_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SourceSyncRun::class);
    }

    public function cfg(string $key, mixed $default = null): mixed
    {
        return ($this->config ?? [])[$key] ?? $default;
    }

    /** Short, secret-free description of what this source points at. */
    public function target(): string
    {
        $c = $this->config ?? [];

        return match ($this->type) {
            SourceType::Greenhouse => (string) ($c['board_token'] ?? ''),
            SourceType::Lever => ($c['site'] ?? '').(($c['region'] ?? 'global') === 'eu' ? ' (EU)' : ''),
            SourceType::Rss => (string) ($c['url'] ?? ''),
            SourceType::Adzuna => trim(($c['country'] ?? '').' '.($c['what'] ?? '')),
            SourceType::RemoteOk => ! empty($c['tags']) ? implode(', ', (array) $c['tags']) : 'all remote jobs',
            default => '',
        };
    }

    public function isDue(): bool
    {
        return $this->enabled
            && $this->type->isSyncable()
            && ($this->next_sync_at === null || $this->next_sync_at->isPast());
    }
}
