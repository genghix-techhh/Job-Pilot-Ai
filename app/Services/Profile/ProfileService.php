<?php

namespace App\Services\Profile;

use App\Models\CandidateProfile;
use App\Models\CandidateProfileVersion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Matching\SkillCatalog;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProfileService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function forUser(User $user): CandidateProfile
    {
        if ($existing = CandidateProfile::query()->where('user_id', $user->id)->first()) {
            $user->setRelation('profile', $existing);

            return $existing;
        }

        $profile = CandidateProfile::create([
            'user_id' => $user->id,
            'roles' => $this->settings->get('target_roles'),
            'skills' => [],
            'experience_levels' => $this->settings->get('experience_levels'),
            'remote_preferences' => ['location' => $this->settings->get('location_preference'), 'countries' => [], 'timezones' => []],
            'salary_preferences' => ['min' => null, 'currency' => 'USD', 'period' => 'year'],
            'employment_types' => ['full_time', 'contract', 'freelance'],
            'structured' => $this->emptyStructured(),
            'profile_version' => 0,
        ]);
        $user->setRelation('profile', $profile);

        return $profile;
    }

    /** @return array<string, mixed> */
    public function emptyStructured(): array
    {
        return [
            'name' => '', 'headline' => '', 'email' => '', 'phone' => '', 'location' => '',
            'links' => [], 'summary' => '',
            'work_history' => [], 'projects' => [], 'education' => [], 'certifications' => [],
            'portfolio_urls' => [],
        ];
    }

    /** What a profile version contains (everything that influences matching or drafts). */
    public function snapshot(CandidateProfile $p): array
    {
        return [
            'roles' => $p->roles ?? [],
            'skills' => $p->skills ?? [],
            'years_experience' => $p->years_experience,
            'experience_levels' => $p->experience_levels ?? [],
            'remote_preferences' => $p->remote_preferences ?? [],
            'salary_preferences' => $p->salary_preferences ?? [],
            'employment_types' => $p->employment_types ?? [],
            'structured' => $p->structured ?? [],
        ];
    }

    /**
     * Persist the owner's reviewed profile. Creates a new immutable version only
     * when something actually changed; matching caches are keyed on that version.
     *
     * @param  array<string, mixed>  $data  validated form data
     * @return bool whether a new profile version was created
     */
    public function save(CandidateProfile $profile, array $data, string $reason = 'Owner review'): bool
    {
        return DB::transaction(function () use ($profile, $data, $reason): bool {
            $before = hash('sha256', json_encode($this->snapshot($profile)));

            $profile->fill([
                'roles' => $this->cleanList($data['roles'] ?? $profile->roles ?? []),
                'skills' => $this->cleanSkills($data['skills'] ?? $profile->skills ?? []),
                'years_experience' => isset($data['years_experience']) && $data['years_experience'] !== '' ? (float) $data['years_experience'] : null,
                'experience_levels' => array_values(array_intersect(['junior', 'mid', 'senior'], (array) ($data['experience_levels'] ?? $profile->experience_levels ?? []))),
                'remote_preferences' => [
                    'location' => trim((string) ($data['remote_location'] ?? ($profile->remote_preferences['location'] ?? ''))),
                    'countries' => $this->cleanList($data['preferred_countries'] ?? ($profile->remote_preferences['countries'] ?? [])),
                    'timezones' => $this->cleanList($data['preferred_timezones'] ?? ($profile->remote_preferences['timezones'] ?? [])),
                ],
                'salary_preferences' => [
                    'min' => isset($data['salary_min']) && $data['salary_min'] !== '' ? (int) $data['salary_min'] : null,
                    'currency' => strtoupper(substr(trim((string) ($data['salary_currency'] ?? 'USD')), 0, 3)) ?: 'USD',
                    'period' => in_array($data['salary_period'] ?? 'year', ['year', 'month', 'hour'], true) ? ($data['salary_period'] ?? 'year') : 'year',
                ],
                'employment_types' => array_values(array_intersect(['full_time', 'part_time', 'contract', 'freelance', 'internship'], (array) ($data['employment_types'] ?? $profile->employment_types ?? []))),
                'structured' => $this->cleanStructured($data['structured'] ?? $profile->structured ?? []),
            ]);

            $after = hash('sha256', json_encode($this->snapshot($profile)));
            $changed = $before !== $after || $profile->profile_version === 0;

            if ($changed) {
                $profile->profile_version = $profile->profile_version + 1;
                $profile->save();
                CandidateProfileVersion::create([
                    'candidate_profile_id' => $profile->id,
                    'version' => $profile->profile_version,
                    'data' => $this->snapshot($profile),
                    'reason' => Str::limit($reason, 120, ''),
                ]);
            }

            $profile->pending_extraction = null;
            $profile->reviewed_at = now();
            $profile->save();

            $this->audit->record('profile.saved', $profile, ['version' => $profile->profile_version, 'changed' => $changed]);

            return $changed;
        });
    }

    public function setConsent(CandidateProfile $profile, bool $granted): void
    {
        $profile->forceFill([
            'ai_consent' => $granted,
            'privacy_consent_at' => now(),
            'privacy_consent_version' => config('jobpilot.privacy_version'),
        ])->save();
        $this->audit->record($granted ? 'privacy.consent_granted' : 'privacy.consent_declined', $profile, [
            'version' => config('jobpilot.privacy_version'),
        ]);
    }

    /** @return list<string> */
    private function cleanList(mixed $items): array
    {
        if (is_string($items)) {
            $items = preg_split('/[\n,;]+/', $items) ?: [];
        }

        return collect((array) $items)
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->map(fn ($v) => Str::limit($v, 100, ''))
            ->unique(fn ($v) => Str::lower($v))
            ->values()->take(80)->all();
    }

    /** @return list<string> */
    private function cleanSkills(mixed $items): array
    {
        return collect($this->cleanList($items))
            ->map(fn ($s) => SkillCatalog::canonical($s))
            ->unique(fn ($v) => Str::lower($v))
            ->values()->take(80)->all();
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function cleanStructured(array $s): array
    {
        $str = fn ($v, int $max = 300) => Str::limit(trim((string) $v), $max, '');
        $rows = fn (array $list, callable $map, int $cap) => collect($list)
            ->filter(fn ($r) => is_array($r))->map($map)
            ->filter(fn ($r) => collect($r)->filter(fn ($v) => $v !== '' && $v !== [] && $v !== false)->isNotEmpty())
            ->values()->take($cap)->all();

        return [
            'name' => $str($s['name'] ?? '', 120),
            'headline' => $str($s['headline'] ?? '', 160),
            'email' => $str($s['email'] ?? '', 160),
            'phone' => $str($s['phone'] ?? '', 40),
            'location' => $str($s['location'] ?? '', 120),
            'summary' => $str($s['summary'] ?? '', 1500),
            'links' => $rows((array) ($s['links'] ?? []), fn ($l) => ['label' => $str($l['label'] ?? '', 40), 'url' => $this->url($l['url'] ?? '')], 10),
            'portfolio_urls' => collect((array) ($s['portfolio_urls'] ?? []))->map(fn ($u) => $this->url($u))->filter()->unique()->values()->take(10)->all(),
            'work_history' => $rows((array) ($s['work_history'] ?? []), fn ($w) => [
                'title' => $str($w['title'] ?? '', 120),
                'company' => $str($w['company'] ?? '', 120),
                'start' => $str($w['start'] ?? '', 30),
                'end' => $str($w['end'] ?? '', 30),
                'current' => filter_var($w['current'] ?? false, FILTER_VALIDATE_BOOL),
                'bullets' => collect(is_string($w['bullets'] ?? null) ? explode("\n", $w['bullets']) : (array) ($w['bullets'] ?? []))
                    ->map(fn ($b) => $str($b, 400))->filter()->values()->take(12)->all(),
            ], 15),
            'projects' => $rows((array) ($s['projects'] ?? []), fn ($p) => [
                'name' => $str($p['name'] ?? '', 120),
                'description' => $str($p['description'] ?? '', 800),
                'technologies' => $this->cleanSkills($p['technologies'] ?? []),
                'url' => $this->url($p['url'] ?? ''),
            ], 15),
            'education' => $rows((array) ($s['education'] ?? []), fn ($e) => [
                'degree' => $str($e['degree'] ?? '', 160),
                'school' => $str($e['school'] ?? '', 160),
                'year' => $str($e['year'] ?? '', 20),
            ], 8),
            'certifications' => $rows((array) ($s['certifications'] ?? []), fn ($c) => [
                'name' => $str($c['name'] ?? '', 160),
                'issuer' => $str($c['issuer'] ?? '', 120),
                'year' => $str($c['year'] ?? '', 20),
            ], 15),
        ];
    }

    private function url(mixed $url): string
    {
        $url = trim((string) $url);

        return preg_match('~^https?://[^\s]+$~i', $url) ? Str::limit($url, 300, '') : '';
    }
}
