<?php

namespace App\Services\Applications;

use App\Enums\JobKind;
use App\Models\CandidateProfile;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Services\Matching\SkillCatalog;
use App\Services\Matching\TextOverlap;
use Illuminate\Support\Str;

/**
 * Builds an application draft with NO AI, using only text the owner wrote in their reviewed
 * profile. Every factual sentence is copied from the profile and carries an evidence ref
 * (SK, W1.2, P1 ...), so nothing can be invented. This is also the fallback when Gemini is off.
 */
class LocalDraftBuilder
{
    /**
     * @return array{cover_letter: string, tailored_resume: string, paragraphs: list<array<string, mixed>>, warnings: list<string>, highlighted_skills: list<string>}
     */
    public function build(CandidateProfile $profile, JobPosting $job, ?JobMatch $match = null, ?array $freelanceUnknowns = null): array
    {
        $s = $profile->structured ?? [];
        $jobSkills = array_map(fn ($x) => SkillCatalog::canonical((string) $x), (array) ($job->skills ?? []));
        $mySkills = $profile->skillList();
        $matched = array_values(array_filter($mySkills, fn ($k) => in_array(Str::lower($k), array_map('strtolower', $jobSkills), true)));
        $missing = array_values(array_filter($jobSkills, fn ($k) => ! in_array(Str::lower($k), array_map('strtolower', $mySkills), true)));

        $jobTerms = TextOverlap::coverage($job->title."\n".$job->description, '')['terms'];
        $termSet = array_flip($jobTerms);
        $hits = fn (string $t) => count(array_intersect_key($termSet, array_flip(TextOverlap::tokens($t))));

        // Rank work bullets and projects by how much they overlap with this job.
        $bullets = [];
        foreach (($s['work_history'] ?? []) as $wi => $w) {
            foreach (($w['bullets'] ?? []) as $bi => $b) {
                $bullets[] = ['ref' => 'W'.($wi + 1).'.'.($bi + 1), 'title' => (string) ($w['title'] ?? ''), 'text' => (string) $b, 'score' => $hits((string) $b)];
            }
        }
        usort($bullets, fn ($a, $b) => $b['score'] <=> $a['score']);
        $topBullets = array_slice(array_filter($bullets, fn ($b) => $b['score'] > 0), 0, 2);

        $projects = $this->rankProjects($profile, $job);
        $bestProject = ($projects[0]['score'] ?? 0) > 0 ? $projects[0] : null;

        $paragraphs = $this->paragraphs($profile, $job, $matched, $topBullets, $bestProject, $freelanceUnknowns);

        $warnings = [];
        if ($missing) {
            $warnings[] = 'The posting mentions '.implode(', ', array_slice($missing, 0, 6)).', which is not on your profile. The draft does not claim it — add it to your profile only if it is true.';
        }
        if (! $matched) {
            $warnings[] = 'No overlap was found between your listed skills and this posting; the draft is generic. Consider whether this role is a good fit.';
        }
        if ($job->kind !== JobKind::Freelance && ! ($s['work_history'] ?? [])) {
            $warnings[] = 'Your profile has no work history, so the letter cannot cite experience.';
        }

        $highlighted = array_values(array_unique(array_merge($matched, $mySkills)));

        return [
            'cover_letter' => $this->composeCoverLetter($paragraphs, (string) ($s['name'] ?? ''), $job->kind === JobKind::Freelance),
            'tailored_resume' => $this->composeResume($profile, $highlighted, $bullets, $projects, $s['summary'] ?? ''),
            'paragraphs' => $paragraphs,
            'warnings' => $warnings,
            'highlighted_skills' => array_slice($highlighted, 0, 20),
        ];
    }

    /**
     * Profile projects ordered by relevance to the job (refs match AiProfilePayload: P1, P2 ...).
     *
     * @return list<array{ref: string, p: array<string, mixed>, score: int, shared: list<string>}>
     */
    public function rankProjects(CandidateProfile $profile, JobPosting $job): array
    {
        $jobSkills = array_map(fn ($x) => strtolower(SkillCatalog::canonical((string) $x)), (array) ($job->skills ?? []));
        $termSet = array_flip(TextOverlap::coverage($job->title."\n".$job->description, '')['terms']);
        $projects = [];
        foreach (($profile->structured['projects'] ?? []) as $pi => $p) {
            $text = ($p['name'] ?? '').' '.($p['description'] ?? '').' '.implode(' ', $p['technologies'] ?? []);
            $shared = array_values(array_filter($p['technologies'] ?? [], fn ($t) => in_array(strtolower((string) $t), $jobSkills, true)));
            $hits = count(array_intersect_key($termSet, array_flip(TextOverlap::tokens($text))));
            $projects[] = ['ref' => 'P'.($pi + 1), 'p' => $p, 'score' => count($shared) * 3 + $hits, 'shared' => $shared];
        }
        usort($projects, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $projects;
    }

    /** @return list<array<string, mixed>> */
    private function paragraphs(CandidateProfile $profile, JobPosting $job, array $matched, array $topBullets, ?array $bestProject, ?array $freelanceUnknowns): array
    {
        $s = $profile->structured ?? [];
        $freelance = $job->kind === JobKind::Freelance;
        $company = $job->company ? " at {$job->company}" : '';
        $out = [];

        $latest = ($s['work_history'][0]['title'] ?? '') ?: ($s['headline'] ?? '');
        $intro = $freelance
            ? "I would like to propose my help with \"{$job->title}\"."
            : "I am applying for the {$job->title} position{$company}.";
        $ev = [];
        if ($latest !== '') {
            $intro .= $freelance ? " I work as a {$latest}." : " I currently work as a {$latest}.";
            $ev[] = ['ref' => ! empty($s['work_history'][0]['title']) ? 'W1' : 'HEADLINE', 'text' => $latest];
        }
        $out[] = ['kind' => 'intro', 'text' => $intro, 'evidence' => $ev, 'verified' => true];

        if ($matched) {
            $list = $this->humanList(array_slice($matched, 0, 6));
            $out[] = [
                'kind' => 'body',
                'text' => "The posting asks for experience with technologies I list on my CV, including {$list}.",
                'evidence' => [['ref' => 'SK', 'text' => 'Skills: '.implode(', ', array_slice($matched, 0, 6))]],
                'verified' => true,
            ];
        }

        if ($topBullets) {
            $sentences = [];
            $ev = [];
            foreach ($topBullets as $b) {
                $sentences[] = rtrim(trim($b['text']), '.;').'.';
                $ev[] = ['ref' => $b['ref'], 'text' => $b['text']];
            }
            $out[] = [
                'kind' => 'body',
                'text' => 'From my recent work: '.implode(' ', $sentences),
                'evidence' => $ev,
                'verified' => true,
            ];
        }

        if ($bestProject) {
            $p = $bestProject['p'];
            $desc = Str::of((string) ($p['description'] ?? ''))->before('. ')->trim()->rtrim('.')->value();
            $text = 'A relevant project of mine is '.($p['name'] ?? 'a personal project').($desc ? " — {$desc}" : '').'.';
            if ($bestProject['shared']) {
                $text .= ' It used '.$this->humanList(array_slice($bestProject['shared'], 0, 5)).'.';
            }
            $out[] = ['kind' => 'body', 'text' => $text, 'evidence' => [['ref' => $bestProject['ref'], 'text' => trim(($p['name'] ?? '').' — '.($p['description'] ?? ''))]], 'verified' => true];
        }

        if ($freelance && $freelanceUnknowns) {
            $out[] = ['kind' => 'body', 'text' => 'Before starting I would like to confirm: '.implode(' ', array_map(fn ($u) => rtrim($u, '.').'.', array_slice($freelanceUnknowns, 0, 3))), 'evidence' => [], 'verified' => true];
        }

        $out[] = [
            'kind' => 'closing',
            'text' => $freelance
                ? 'I would be glad to discuss scope, timeline and budget. Thank you for considering my proposal.'
                : 'I would welcome the chance to discuss how my background fits the role. Thank you for your time and consideration.',
            'evidence' => [], 'verified' => true,
        ];

        return $out;
    }

    /** @param list<array<string, mixed>> $paragraphs */
    public function composeCoverLetter(array $paragraphs, string $name, bool $freelance = false): string
    {
        $body = collect($paragraphs)->pluck('text')->map(fn ($t) => trim((string) $t))->filter()->implode("\n\n");

        return ($freelance ? "Hello,\n\n" : "Dear Hiring Team,\n\n").$body."\n\n".($freelance ? 'Best regards,' : 'Kind regards,').($name !== '' ? "\n{$name}" : '');
    }

    /**
     * @param  list<string>  $skills  ordered, most relevant first
     * @param  list<array<string, mixed>>  $bullets  already ranked by relevance (used for ordering only)
     * @param  list<array<string, mixed>>  $projects  already ranked
     */
    public function composeResume(CandidateProfile $profile, array $skills, array $bullets, array $projects, string $summary): string
    {
        $s = $profile->structured ?? [];
        $rank = [];
        foreach ($bullets as $i => $b) {
            $rank[$b['ref']] = $i;
        }

        $lines = [];
        if (! empty($s['name'])) {
            $lines[] = $s['name'];
        }
        if (! empty($s['headline'])) {
            $lines[] = $s['headline'];
        }
        $contact = array_filter([$s['email'] ?? '', $s['phone'] ?? '', $s['location'] ?? '']);
        if ($contact) {
            $lines[] = implode(' | ', $contact);
        }
        $links = array_filter(array_merge(array_map(fn ($l) => $l['url'] ?? '', $s['links'] ?? []), $s['portfolio_urls'] ?? []));
        if ($links) {
            $lines[] = implode(' | ', array_unique($links));
        }

        if (trim($summary) !== '') {
            array_push($lines, '', 'SUMMARY', trim($summary));
        }
        if ($skills) {
            array_push($lines, '', 'SKILLS', implode(', ', $skills));
        }

        if (! empty($s['work_history'])) {
            $lines[] = '';
            $lines[] = 'EXPERIENCE';
            foreach ($s['work_history'] as $wi => $w) {
                $dates = trim(($w['start'] ?? '').' – '.(($w['current'] ?? false) ? 'Present' : ($w['end'] ?? '')), ' –');
                $head = trim(($w['title'] ?? '').(($w['company'] ?? '') ? ' — '.$w['company'] : '').($dates ? " ({$dates})" : ''));
                $lines[] = $head;
                $mine = [];
                foreach (($w['bullets'] ?? []) as $bi => $b) {
                    $mine[] = ['ref' => 'W'.($wi + 1).'.'.($bi + 1), 'text' => $b];
                }
                usort($mine, fn ($a, $b) => ($rank[$a['ref']] ?? 999) <=> ($rank[$b['ref']] ?? 999));
                foreach (array_slice($mine, 0, 6) as $b) {
                    $lines[] = '- '.$b['text'];
                }
                $lines[] = '';
            }
        }

        if ($projects) {
            $lines[] = 'PROJECTS';
            foreach (array_slice($projects, 0, 4) as $pr) {
                $p = $pr['p'];
                $lines[] = ($p['name'] ?? '').(! empty($p['technologies']) ? ' ['.implode(', ', $p['technologies']).']' : '');
                if (! empty($p['description'])) {
                    $lines[] = '- '.$p['description'];
                }
            }
            $lines[] = '';
        }

        if (! empty($s['education'])) {
            $lines[] = 'EDUCATION';
            foreach ($s['education'] as $e) {
                $lines[] = trim(($e['degree'] ?? '').(($e['school'] ?? '') ? ', '.$e['school'] : '').(($e['year'] ?? '') ? ' ('.$e['year'].')' : ''));
            }
            $lines[] = '';
        }
        if (! empty($s['certifications'])) {
            $lines[] = 'CERTIFICATIONS';
            foreach ($s['certifications'] as $c) {
                $lines[] = trim(($c['name'] ?? '').(($c['issuer'] ?? '') ? ', '.$c['issuer'] : '').(($c['year'] ?? '') ? ' ('.$c['year'].')' : ''));
            }
        }

        return trim(implode("\n", $lines));
    }

    /** @param list<string> $items */
    private function humanList(array $items): string
    {
        $n = count($items);

        return match (true) {
            $n === 0 => '',
            $n === 1 => $items[0],
            $n === 2 => $items[0].' and '.$items[1],
            default => implode(', ', array_slice($items, 0, -1)).' and '.end($items),
        };
    }
}
