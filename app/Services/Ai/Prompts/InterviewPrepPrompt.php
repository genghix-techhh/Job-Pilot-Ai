<?php

namespace App\Services\Ai\Prompts;

use App\Services\Ai\PromptSpec;

/** Interview preparation: likely questions with answer outlines built ONLY from the candidate's evidence. */
final class InterviewPrepPrompt
{
    public const VERSION = 'prep-1';

    /**
     * @param  array<string, mixed>  $profilePayload  from AiProfilePayload::build() (redacted, with evidence refs)
     * @param  array<string, mixed>  $job  title, seniority, required_skills, responsibilities (public job data)
     */
    public static function build(array $profilePayload, array $job, int $applicationId): PromptSpec
    {
        $user = "Prepare the candidate for an interview for this job.\n"
            ."- questions: 8 to 12 likely interview questions (technical, behavioural, role, logistics), most important first.\n"
            ."- why_asked: one short sentence on what the interviewer is checking.\n"
            .'- answer_outline: a short outline of how THIS candidate could answer, built ONLY from the evidence refs provided (e.g. W1.2, P1, SK). '
            ."If the evidence does not support an answer, leave answer_outline empty and evidence_ids empty — never invent experience, employers, metrics or skills.\n"
            ."- gap_plan: up to 5 skills the job needs that the evidence does not show, with an honest, practical way to prepare (study plan, small project) — do not suggest claiming experience the candidate lacks.\n"
            ."- questions_to_ask: up to 6 thoughtful questions the candidate can ask the interviewer.\n\n"
            ."<candidate_evidence>\n".json_encode($profilePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n</candidate_evidence>\n\n"
            .PromptSpec::untrusted('job_summary', json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 5000);

        return new PromptSpec('interview_prep', self::VERSION, PromptSpec::GUARD_RULES, $user, self::schema(), $applicationId, 3072);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $str = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'questions' => [
                    'type' => 'array', 'minItems' => 1, 'maxItems' => 14,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string', 'enum' => ['technical', 'behavioural', 'role', 'logistics']],
                            'question' => $str,
                            'why_asked' => $str,
                            'answer_outline' => $str,
                            'evidence_ids' => ['type' => 'array', 'items' => $str, 'maxItems' => 6],
                        ],
                        'required' => ['category', 'question', 'why_asked', 'answer_outline', 'evidence_ids'],
                    ],
                ],
                'gap_plan' => [
                    'type' => 'array', 'maxItems' => 6,
                    'items' => ['type' => 'object', 'properties' => ['skill' => $str, 'how_to_prepare' => $str], 'required' => ['skill', 'how_to_prepare']],
                ],
                'questions_to_ask' => ['type' => 'array', 'items' => $str, 'maxItems' => 8],
            ],
            'required' => ['questions', 'gap_plan', 'questions_to_ask'],
        ];
    }
}
