<?php

namespace App\Services\Ai\Prompts;

use App\Services\Ai\PromptSpec;

/** Task 2: pull requirements out of an (untrusted, public) job description. */
final class JobRequirementsPrompt
{
    public const VERSION = 'job-req-1';

    public static function build(string $title, string $description, string $location, int $jobId): PromptSpec
    {
        $user = "Extract the hiring requirements from this job posting.\n"
            ."- required_skills / preferred_skills: concrete technologies or methods (max 25 each). Do not include soft skills.\n"
            ."- seniority: junior, mid, senior, lead or unknown, judged from the title and stated requirements.\n"
            ."- years_required: the minimum years of experience explicitly asked for, else 0.\n"
            ."- responsibilities: the main duties, one short line each (max 8).\n"
            ."- remote_scope: worldwide ONLY if the posting explicitly says anywhere/worldwide/global; region_restricted or country_restricted if it names regions/countries/time zones; otherwise unclear.\n"
            ."- eligible_countries: countries or regions explicitly named as allowed (empty if none stated).\n"
            ."- timezone_requirement: any stated working-hours or time zone overlap requirement, else \"\".\n\n"
            .'Job title: '.self::oneLine($title)."\nLocation field: ".self::oneLine($location)."\n"
            .PromptSpec::untrusted('job_posting', $description, 6500);

        return new PromptSpec('job_requirements', self::VERSION, PromptSpec::GUARD_RULES, $user, self::schema(), $jobId, 1536);
    }

    private static function oneLine(string $s): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $s) ?? ''), 0, 200);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $strList = fn (int $max) => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => $max];

        return [
            'type' => 'object',
            'properties' => [
                'required_skills' => $strList(25),
                'preferred_skills' => $strList(25),
                'seniority' => ['type' => 'string', 'enum' => ['junior', 'mid', 'senior', 'lead', 'unknown']],
                'years_required' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 40],
                'responsibilities' => $strList(8),
                'remote_scope' => ['type' => 'string', 'enum' => ['worldwide', 'region_restricted', 'country_restricted', 'hybrid', 'onsite', 'unclear']],
                'eligible_countries' => $strList(40),
                'timezone_requirement' => ['type' => 'string'],
            ],
            'required' => ['required_skills', 'preferred_skills', 'seniority', 'years_required', 'responsibilities', 'remote_scope', 'eligible_countries'],
        ];
    }
}
