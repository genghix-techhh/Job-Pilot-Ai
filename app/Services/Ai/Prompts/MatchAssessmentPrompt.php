<?php

namespace App\Services\Ai\Prompts;

use App\Services\Ai\PromptSpec;

/** Task 3: judge duties/project fit and explain it, citing evidence refs from the redacted profile. */
final class MatchAssessmentPrompt
{
    public const VERSION = 'match-1';

    /**
     * @param  array<string, mixed>  $profilePayload  from AiProfilePayload::build()
     * @param  array<string, mixed>  $job  title, responsibilities, skills, seniority (no company, no contact data)
     */
    public static function build(array $profilePayload, array $job, int $jobId): PromptSpec
    {
        $user = "Assess how well the candidate evidence fits the job. Judge only from the evidence refs provided.\n"
            ."- duties_fit: 0-100, how closely the candidate's past duties match the job's responsibilities.\n"
            ."- project_fit: 0-100, how relevant the candidate's projects are to what this job builds. Use 0 if there are no projects.\n"
            ."- summary: at most 3 sentences, plain and honest, no hype.\n"
            ."- strengths: up to 5 items; each names a job requirement and the evidence_ids (refs such as W1.2, P1, SK) that support it. Omit anything you cannot support with refs.\n"
            ."- gaps: up to 5 requirements the evidence does not cover.\n\n"
            ."<candidate_evidence>\n".json_encode($profilePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n</candidate_evidence>\n\n"
            .PromptSpec::untrusted('job_summary', json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 5000);

        return new PromptSpec('match_assessment', self::VERSION, PromptSpec::GUARD_RULES, $user, self::schema(), $jobId, 1536);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $str = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'duties_fit' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'project_fit' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'summary' => $str,
                'strengths' => [
                    'type' => 'array',
                    'maxItems' => 5,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'requirement' => $str,
                            'evidence_ids' => ['type' => 'array', 'items' => $str, 'maxItems' => 6],
                        ],
                        'required' => ['requirement', 'evidence_ids'],
                    ],
                ],
                'gaps' => ['type' => 'array', 'items' => $str, 'maxItems' => 5],
            ],
            'required' => ['duties_fit', 'project_fit', 'summary', 'strengths', 'gaps'],
        ];
    }
}
