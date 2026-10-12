<?php

namespace App\Services\Matching;

use App\Models\CandidateProfile;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/** Immutable, pre-processed view of the candidate used by the scorer. */
final class ProfileSnapshot
{
    /**
     * @param  array<string, string>  $skills  lower-case key => display name
     * @param  list<string>  $roles  target role families
     * @param  list<string>  $levels  accepted seniority levels
     * @param  array<string, string>  $projectSkills  lower-case key => display name
     */
    public function __construct(
        public readonly int $version,
        public readonly array $skills,
        public readonly array $roles,
        public readonly ?float $years,
        public readonly array $levels,
        public readonly string $corpus,
        public readonly array $projectSkills,
        public readonly string $projectText,
        public readonly int $projectCount,
        public readonly ?int $salaryMinAnnual,
        public readonly ?string $salaryCurrency,
        public readonly ?string $homeCountry,
        public readonly array $titles,
        /** @var list<array{name: string, skills: list<string>}> */
        public readonly array $projects = [],
    ) {}

    public static function from(CandidateProfile $p, SettingsService $settings): self
    {
        $s = $p->structured ?? [];

        $skills = [];
        foreach ($p->skillList() as $name) {
            $skills[Str::lower($name)] = $name;
        }

        $corpus = [(string) ($s['summary'] ?? ''), (string) ($s['headline'] ?? '')];
        $titles = array_filter([(string) ($s['headline'] ?? '')]);
        foreach ($s['work_history'] ?? [] as $w) {
            $titles[] = (string) ($w['title'] ?? '');
            $corpus[] = (string) ($w['title'] ?? '');
            foreach ($w['bullets'] ?? [] as $b) {
                $corpus[] = (string) $b;
            }
        }

        $projectText = [];
        $projectSkills = [];
        $projects = [];
        foreach ($s['projects'] ?? [] as $proj) {
            $text = ($proj['name'] ?? '').' '.($proj['description'] ?? '').' '.implode(' ', $proj['technologies'] ?? []);
            $projectText[] = $text;
            $own = [];
            foreach (array_merge($proj['technologies'] ?? [], SkillCatalog::extract($text)) as $t) {
                $name = SkillCatalog::canonical((string) $t);
                $projectSkills[Str::lower($name)] = $name;
                $own[Str::lower($name)] = $name;
            }
            $projects[] = ['name' => (string) ($proj['name'] ?? 'Untitled project'), 'skills' => array_values($own)];
        }
        $corpus = array_merge($corpus, $projectText);

        $pref = $p->salary_preferences ?? [];
        $annual = null;
        if (! empty($pref['min'])) {
            $annual = (int) round(match ($pref['period'] ?? 'year') {
                'month' => $pref['min'] * 12,
                'hour' => $pref['min'] * 2080,
                default => $pref['min'],
            });
        }

        $roles = array_values(array_unique(array_merge(
            (array) ($settings->get('target_roles') ?? []),
            (array) ($p->roles ?? []),
        )));

        return new self(
            version: (int) $p->profile_version,
            skills: $skills,
            roles: $roles,
            years: $p->years_experience,
            levels: (array) ($settings->get('experience_levels') ?: $p->experience_levels ?: []),
            corpus: Str::lower(implode("\n", $corpus)),
            projectSkills: $projectSkills,
            projectText: Str::lower(implode("\n", $projectText)),
            projectCount: count($projectText),
            salaryMinAnnual: $annual,
            salaryCurrency: $annual ? strtoupper((string) ($pref['currency'] ?? 'USD')) : null,
            homeCountry: Geo::canonicalCountry((string) $settings->get('home_country')),
            titles: array_values(array_filter($titles)),
            projects: $projects,
        );
    }

    public function hasSkill(string $name): bool
    {
        return isset($this->skills[Str::lower($name)]);
    }

    public function hasExperienceText(): bool
    {
        return mb_strlen(trim($this->corpus)) > 40;
    }
}
