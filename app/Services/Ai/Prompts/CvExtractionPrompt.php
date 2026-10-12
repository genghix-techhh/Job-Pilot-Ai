<?php

namespace App\Services\Ai\Prompts;

use App\Services\Ai\PromptSpec;

/** Task 1: structure an already-redacted CV into profile fields. */
final class CvExtractionPrompt
{
    public const VERSION = 'cv-extract-1';

    /** @param string $redactedCv CV text with contact details, links, IDs and the candidate's name removed */
    public static function build(string $redactedCv): PromptSpec
    {
        $user = "Extract a structured candidate profile from the CV text below.\n"
            ."- skills: technical skills explicitly present (max 60). Normalise names (e.g. \"ReactJS\" -> \"React\").\n"
            ."- roles: up to 6 job titles the candidate has held or targets, taken from the text.\n"
            ."- years_experience: total professional years if stated or clearly derivable from dated roles, otherwise 0.\n"
            ."- work_history: most recent first; bullets must be copied or lightly shortened from the text, never invented. Use \"\" for unknown dates.\n"
            ."- projects, education, certifications: only items present in the text.\n\n"
            .PromptSpec::untrusted('cv', $redactedCv, 14000);

        return new PromptSpec('cv_extraction', self::VERSION, PromptSpec::GUARD_RULES, $user, self::schema(), null, 4096);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $str = ['type' => 'string'];
        $strList = fn (int $max) => ['type' => 'array', 'items' => $str, 'maxItems' => $max];

        return [
            'type' => 'object',
            'properties' => [
                'headline' => $str,
                'summary' => $str,
                'years_experience' => ['type' => 'number', 'minimum' => 0, 'maximum' => 60],
                'skills' => $strList(60),
                'roles' => $strList(6),
                'work_history' => [
                    'type' => 'array',
                    'maxItems' => 12,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => $str, 'company' => $str, 'start' => $str, 'end' => $str,
                            'current' => ['type' => 'boolean'],
                            'bullets' => $strList(10),
                        ],
                        'required' => ['title', 'bullets'],
                    ],
                ],
                'projects' => [
                    'type' => 'array',
                    'maxItems' => 12,
                    'items' => [
                        'type' => 'object',
                        'properties' => ['name' => $str, 'description' => $str, 'technologies' => $strList(15)],
                        'required' => ['name'],
                    ],
                ],
                'education' => [
                    'type' => 'array',
                    'maxItems' => 6,
                    'items' => [
                        'type' => 'object',
                        'properties' => ['degree' => $str, 'school' => $str, 'year' => $str],
                        'required' => ['degree'],
                    ],
                ],
                'certifications' => [
                    'type' => 'array',
                    'maxItems' => 12,
                    'items' => [
                        'type' => 'object',
                        'properties' => ['name' => $str, 'issuer' => $str, 'year' => $str],
                        'required' => ['name'],
                    ],
                ],
            ],
            'required' => ['skills', 'work_history'],
        ];
    }
}
