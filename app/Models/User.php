<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The single owner of this installation. There is no registration flow:
 * the owner is created once via `php artisan app:create-owner` (or the
 * token-protected /setup page).
 */
class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'password', 'timezone', 'notification_email'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(CandidateProfile::class);
    }

    public function resumes(): HasMany
    {
        return $this->hasMany(Resume::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /** Where email alerts go: the explicit notification address, else the login email. */
    public function alertEmail(): string
    {
        return $this->notification_email ?: $this->email;
    }

    public function tz(): string
    {
        return in_array($this->timezone, timezone_identifiers_list(), true)
            ? $this->timezone
            : config('jobpilot.timezone', 'UTC');
    }
}
