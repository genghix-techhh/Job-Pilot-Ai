<?php

namespace App\Services\Matching;

/**
 * Splits a posting into "required" and "nice-to-have" parts using the headings recruiters
 * actually write ("Nice to have", "Preferred qualifications", "Bonus points" ...), so a skill
 * that is only a bonus counts for less than one the job demands. Works without AI.
 */
class RequirementSplitter
{
    private const PREFERRED_HEADING = '/^[\s\-*•·#>]*(?:nice[\s-]*to[\s-]*haves?|preferred(?:\s+(?:qualifications|skills|experience|requirements))?|bonus(?:\s+points)?|good\s+to\s+have|desirable|extra\s+credit|it\s+is\s+a\s+plus|a\s+plus|pluses|would\s+be\s+(?:a\s+)?(?:plus|great|nice))\b[^\n]{0,60}$/imu';

    private const SECTION_END = '/^[\s\-*•·#>]*(?:benefits|what\s+we\s+offer|perks|about\s+(?:us|the\s+(?:company|team))|why\s+(?:join|you.ll\s+love)|compensation|how\s+to\s+apply|our\s+(?:values|culture)|equal\s+opportunity)\b[^\n]{0,60}$/imu';

    /**
     * @return array{required: list<string>, preferred: list<string>, title: list<string>}
     */
    public static function split(string $title, string $description): array
    {
        $requiredText = $description;
        $preferredText = '';

        if (preg_match(self::PREFERRED_HEADING, $description, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            $afterHeading = $start + strlen($m[0][0]);
            $end = strlen($description);
            if (preg_match(self::SECTION_END, $description, $e, PREG_OFFSET_CAPTURE, $afterHeading)) {
                $end = $e[0][1];
            }
            $preferredText = substr($description, $afterHeading, $end - $afterHeading);
            // Everything outside the "preferred" block still counts as required.
            $requiredText = substr($description, 0, $start).substr($description, $end);
        }

        $titleSkills = SkillCatalog::extract($title);
        $required = array_values(array_unique(array_merge($titleSkills, SkillCatalog::extract($requiredText))));
        $preferred = array_values(array_diff(SkillCatalog::extract($preferredText), $required));

        return ['required' => $required, 'preferred' => $preferred, 'title' => $titleSkills];
    }
}
