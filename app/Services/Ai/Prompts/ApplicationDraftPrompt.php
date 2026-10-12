<?php

namespace App\Services\Ai\Prompts;

use App\Services\Ai\PromptSpec;

/** Task 4: draft application text strictly from the candidate's verified evidence. */
final class ApplicationDraftPrompt
{
    public const VERSION = 'draft-1';

    /**
     * @param  array<string, mixed>  $profilePayload  from AiProfilePayload::build() (redacted, with evidence refs)
     * @param  array<string, mixed>  $job  title, company, responsibilities, skills (public job data)
     * @param  list<string>  $questions  screening questions the owner typed in (may be empty)
     */
    public static function build(array $profilePayload, array $job, array $questions, int $jobId, string $kind = 'employment'): PromptSpec
    {
        $what = $kind === 'freelance' ? 'a freelance project proposal' : 'a job application cover letter';
        $user = "Write {$what} for the candidate using ONLY the candidate evidence. Rules:\n"
            ."- cover_letter: 3 or 4 paragraphs. Do not include a greeting, sign-off or the candidate's name (the tool adds those). Every paragraph with kind=\"body\" must cite evidence_ids from the evidence refs (e.g. W1.2, P1, SK); kind is one of intro, body, closing.\n"
            ."- Never claim a skill, employer, metric, certification, degree, location or number of years that is not in the evidence. If something would help but is not evidenced, put it in open_questions instead.\n"
            ."- tailored_summary: 2 sentences, cites evidence_ids.\n"
            ."- highlighted_skills: choose only from the candidate's listed skills, ordered by relevance to the job (max 15).\n"
            ."- highlighted_evidence_ids: refs of the work bullets/projects most relevant to this job, best first (max 12).\n"
            ."- answers: one entry per screening question. Answer only from evidence; if the question asks about work authorisation, visa, citizenship, relocation, salary expectations, notice period, criminal record, or anything personal, set needs_confirmation=true and leave answer empty. Set needs_confirmation=true whenever evidence is thin.\n\n"
            ."<candidate_evidence>\n".json_encode($profilePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n</candidate_evidence>\n\n"
            .PromptSpec::untrusted('job_summary', json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 5000)."\n\n"
            .PromptSpec::untrusted('screening_questions', json_encode(array_values($questions), JSON_UNESCAPED_UNICODE), 3000);

        return new PromptSpec('application_draft', self::VERSION, PromptSpec::GUARD_RULES, $user, self::schema(), $jobId, 3072);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $str = ['type' => 'string'];
        $ids = ['type' => 'array', 'items' => $str, 'maxItems' => 8];

        return [
            'type' => 'object',
            'properties' => [
                'cover_letter' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 5,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'kind' => ['type' => 'string', 'enum' => ['intro', 'body', 'closing']],
                            'text' => $str,
                            'evidence_ids' => $ids,
                        ],
                        'required' => ['kind', 'text', 'evidence_ids'],
                    ],
                ],
                'tailored_summary' => $str,
                'summary_evidence_ids' => $ids,
                'highlighted_skills' => ['type' => 'array', 'items' => $str, 'maxItems' => 15],
                'highlighted_evidence_ids' => ['type' => 'array', 'items' => $str, 'maxItems' => 12],
                'answers' => [
                    'type' => 'array',
                    'maxItems' => 12,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => $str,
                            'answer' => $str,
                            'evidence_ids' => $ids,
                            'needs_confirmation' => ['type' => 'boolean'],
                        ],
                        'required' => ['question', 'answer', 'evidence_ids', 'needs_confirmation'],
                    ],
                ],
                'open_questions' => ['type' => 'array', 'items' => $str, 'maxItems' => 8],
            ],
            'required' => ['cover_letter', 'tailored_summary', 'highlighted_skills', 'highlighted_evidence_ids', 'answers', 'open_questions'],
        ];
    }
}
