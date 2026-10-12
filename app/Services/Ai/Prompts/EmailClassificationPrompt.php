<?php

namespace App\Services\Ai\Prompts;

use App\Services\Ai\PromptSpec;

/** Task 5: classify a recruiter e-mail (minimal, redacted excerpt only) and pull out scheduling details. */
final class EmailClassificationPrompt
{
    public const VERSION = 'email-1';

    /**
     * @param  list<array{id: int, company: string, title: string}>  $applications  candidates for matching
     */
    public static function build(string $senderDomain, string $subject, string $redactedExcerpt, array $applications, int $messageId): PromptSpec
    {
        $user = "Classify this e-mail received by a job seeker and extract scheduling details.\n"
            ."- classification: recruiter_reply, interview_invite, assessment, rejection, offer, info_request, unrelated or uncertain. Use uncertain when unsure.\n"
            ."- confidence: 0 to 1.\n"
            ."- matched_application_id: the id of the ONE application below it clearly refers to, else 0. Never guess between several.\n"
            ."- proposed_times: any date/time options offered, ISO 8601 (include the UTC offset if stated, else omit it) with the time zone name or abbreviation given in the text (\"\" if none). tentative=true unless the sender states it is already fixed AND the recipient does not need to choose or confirm.\n"
            ."- deadline_iso: a due date for an assessment/task, else \"\".\n"
            ."- requested_action: what the sender wants the recipient to do, max 160 characters, else \"\".\n\n"
            ."Sender domain: {$senderDomain}\nSubject: ".mb_substr(trim(preg_replace('/\s+/', ' ', $subject) ?? ''), 0, 200)."\n\n"
            ."<known_applications>\n".json_encode($applications, JSON_UNESCAPED_UNICODE)."\n</known_applications>\n\n"
            .PromptSpec::untrusted('email_excerpt', $redactedExcerpt, 2500);

        return new PromptSpec('email_classification', self::VERSION, PromptSpec::GUARD_RULES, $user, self::schema(), $messageId, 1024);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $str = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'classification' => ['type' => 'string', 'enum' => ['recruiter_reply', 'interview_invite', 'assessment', 'rejection', 'offer', 'info_request', 'unrelated', 'uncertain']],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'matched_application_id' => ['type' => 'integer', 'minimum' => 0],
                'proposed_times' => [
                    'type' => 'array',
                    'maxItems' => 6,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'start_iso' => $str,
                            'end_iso' => $str,
                            'timezone' => $str,
                            'tentative' => ['type' => 'boolean'],
                        ],
                        'required' => ['start_iso', 'timezone', 'tentative'],
                    ],
                ],
                'deadline_iso' => $str,
                'requested_action' => $str,
            ],
            'required' => ['classification', 'confidence', 'matched_application_id', 'proposed_times'],
        ];
    }
}
