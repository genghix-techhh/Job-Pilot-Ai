<?php

namespace App\Services\Profile;

use App\Models\CandidateProfile;
use App\Services\Ai\Redactor;
use App\Support\Text;

/**
 * Builds the ONLY view of the candidate that is ever sent to Gemini.
 *
 * Included : skills, years of experience, role titles, experience bullets, project
 *            descriptions/technologies, certification names (all redacted).
 * Excluded : name, e-mail, phone, address, links, birth date, IDs, employer names,
 *            school names, dates and anything else from the structured profile.
 *
 * Every item gets a stable "ref" (SK, W1.2, P1, C1) so the model can cite evidence and
 * we can verify those citations afterwards.
 */
class AiProfilePayload
{
    public function __construct(private readonly Redactor $redactor) {}

    /** @return list<string> names that must never appear in a prompt */
    public function names(CandidateProfile $profile): array
    {
        $names = array_filter([
            $profile->structured['name'] ?? null,
            $profile->user?->name,
        ]);

        return array_values(array_unique(array_map('strval', $names)));
    }

    /** @return array<string, mixed> */
    public function build(CandidateProfile $profile): array
    {
        $names = $this->names($profile);
        $clean = fn (?string $t, int $max = 300): string => Text::limit($this->redactor->redactCv((string) $t, $names), $max);
        $s = $profile->structured ?? [];

        $experience = [];
        foreach (array_slice($s['work_history'] ?? [], 0, 8) as $i => $job) {
            $w = $i + 1;
            $bullets = [];
            foreach (array_slice($job['bullets'] ?? [], 0, 8) as $j => $b) {
                $bullets[] = ['ref' => "W{$w}.".($j + 1), 'text' => $clean($b)];
            }
            $experience[] = ['ref' => "W{$w}", 'title' => $clean($job['title'] ?? '', 80), 'bullets' => $bullets];
        }

        $projects = [];
        foreach (array_slice($s['projects'] ?? [], 0, 8) as $i => $p) {
            $projects[] = [
                'ref' => 'P'.($i + 1),
                'name' => $clean($p['name'] ?? '', 80),
                'description' => $clean($p['description'] ?? '', 400),
                'technologies' => array_values(array_map('strval', array_slice($p['technologies'] ?? [], 0, 15))),
            ];
        }

        $certs = [];
        foreach (array_slice($s['certifications'] ?? [], 0, 8) as $i => $c) {
            $certs[] = ['ref' => 'C'.($i + 1), 'name' => $clean($c['name'] ?? '', 100)];
        }

        return [
            'skills' => ['ref' => 'SK', 'items' => array_slice($profile->skillList(), 0, 60)],
            'years_experience' => $profile->years_experience,
            'target_roles' => array_values(array_map('strval', $profile->roles ?? [])),
            'experience' => $experience,
            'projects' => $projects,
            'certifications' => $certs,
        ];
    }

    /**
     * ref => the exact (redacted) text it stands for. Used to verify AI citations and
     * to show the owner what each generated statement is based on.
     *
     * @param  array<string, mixed>  $payload  output of build()
     * @return array<string, string>
     */
    public function evidenceIndex(array $payload): array
    {
        $idx = ['SK' => 'Skills: '.implode(', ', $payload['skills']['items'] ?? [])];
        foreach ($payload['experience'] ?? [] as $job) {
            $idx[$job['ref']] = $job['title'];
            foreach ($job['bullets'] as $b) {
                $idx[$b['ref']] = trim($job['title'].': '.$b['text']);
            }
        }
        foreach ($payload['projects'] ?? [] as $p) {
            $idx[$p['ref']] = trim($p['name'].' — '.$p['description'].' ['.implode(', ', $p['technologies']).']');
        }
        foreach ($payload['certifications'] ?? [] as $c) {
            $idx[$c['ref']] = 'Certification: '.$c['name'];
        }

        return $idx;
    }
}
