<?php

namespace App\Services\Ai;

use App\Services\Matching\Geo;
use App\Services\Matching\SkillCatalog;
use App\Support\Text;
use Illuminate\Support\Str;

/**
 * Schema validation proves the SHAPE of a model answer; this class makes the CONTENT
 * safe to store and show: free text is reduced to plain text and shortened, skills are
 * normalised, and every evidence citation must point at something we actually sent.
 */
class AiResultSanitizer
{
    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public function requirements(array $r): array
    {
        $skills = fn (array $list) => collect($list)->map(fn ($s) => SkillCatalog::canonical($this->line((string) $s, 60)))
            ->filter()->unique(fn ($s) => Str::lower($s))->values()->take(25)->all();

        $countries = [];
        foreach ((array) ($r['eligible_countries'] ?? []) as $c) {
            $c = trim((string) $c);
            if (Geo::canonicalCountry($c)) {
                $countries[] = Geo::canonicalCountry($c);
            } elseif (Geo::regionMembers($c)) {
                $countries[] = strlen($c) <= 3 ? Str::upper($c) : Str::title($c);
            }
        }

        return [
            'required_skills' => $skills((array) ($r['required_skills'] ?? [])),
            'preferred_skills' => $skills((array) ($r['preferred_skills'] ?? [])),
            'seniority' => in_array($r['seniority'] ?? '', ['junior', 'mid', 'senior', 'lead', 'unknown'], true) ? $r['seniority'] : 'unknown',
            'years_required' => max(0, min(40, (int) ($r['years_required'] ?? 0))),
            'responsibilities' => collect((array) ($r['responsibilities'] ?? []))->map(fn ($s) => $this->line((string) $s, 200))->filter()->take(8)->values()->all(),
            'remote_scope' => in_array($r['remote_scope'] ?? '', ['worldwide', 'region_restricted', 'country_restricted', 'hybrid', 'onsite', 'unclear'], true) ? $r['remote_scope'] : 'unclear',
            'eligible_countries' => array_values(array_unique($countries)),
            'timezone_requirement' => $this->line((string) ($r['timezone_requirement'] ?? ''), 160),
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, string>  $evidenceIndex  ref => text actually sent to the model
     * @return array<string, mixed>
     */
    public function assessment(array $r, array $evidenceIndex): array
    {
        $strengths = [];
        foreach ((array) ($r['strengths'] ?? []) as $s) {
            $refs = array_values(array_filter((array) ($s['evidence_ids'] ?? []), fn ($id) => isset($evidenceIndex[$id])));
            if ($refs === []) {
                continue; // a claim without verifiable evidence is dropped, not shown
            }
            $strengths[] = [
                'requirement' => $this->line((string) ($s['requirement'] ?? ''), 160),
                'evidence' => array_map(fn ($id) => ['ref' => $id, 'text' => Text::limit($evidenceIndex[$id], 220)], $refs),
            ];
        }

        return [
            'duties_fit' => max(0, min(100, (int) ($r['duties_fit'] ?? 0))),
            'project_fit' => max(0, min(100, (int) ($r['project_fit'] ?? 0))),
            'summary' => $this->line((string) ($r['summary'] ?? ''), 600),
            'strengths' => array_slice($strengths, 0, 5),
            'gaps' => collect((array) ($r['gaps'] ?? []))->map(fn ($g) => $this->line((string) $g, 160))->filter()->take(5)->values()->all(),
        ];
    }

    /** Plain single-line text, no markup, bounded length. */
    public function line(string $s, int $max): string
    {
        $s = Text::htmlToText($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return Str::limit(trim($s), $max, '…');
    }
}
