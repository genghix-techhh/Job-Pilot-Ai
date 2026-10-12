<?php

namespace App\Services\Matching;

use App\Models\JobPosting;
use App\Services\SettingsService;
use App\Support\Text;

/**
 * Owner-defined "never show me this" rules. A job that trips one still gets a score (so you can see
 * what it is), but it is hidden from the feed, dashboard and alerts unless you ask to see excluded jobs.
 */
class ExclusionRules
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return string|null human-readable reason, or null when the job is allowed */
    public function check(JobPosting $job, ProfileSnapshot $p): ?string
    {
        $company = Text::normalizeKey($job->company);
        if ($company !== '') {
            foreach ((array) $this->settings->get('blocked_companies', []) as $blocked) {
                $b = Text::normalizeKey((string) $blocked);
                if ($b !== '' && preg_match('/(^| )'.preg_quote($b, '/').'( |$)/u', $company)) {
                    return "Blocked company: {$blocked}";
                }
            }
        }

        // "unpaid" matches title or description; "title:manager" matches the job title only.
        foreach ((array) $this->settings->get('exclude_keywords', []) as $kw) {
            $kw = trim((string) $kw);
            $titleOnly = false;
            if (preg_match('/^title\s*:\s*(.+)$/i', $kw, $m)) {
                $titleOnly = true;
                $kw = trim($m[1]);
            }
            if ($kw === '') {
                continue;
            }
            $haystack = $titleOnly ? (string) $job->title : $job->title."\n".$job->description;
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($kw, '/').'(?![\p{L}\p{N}])/iu', $haystack)) {
                return ($titleOnly ? 'Title contains excluded keyword: ' : 'Contains excluded keyword: ').$kw;
            }
        }

        if ($this->settings->get('exclude_below_min_salary') && SalaryMath::incomparableReason($job, $p) === null) {
            $top = SalaryMath::annualTop($job);
            if ($top !== null && $top < $p->salaryMinAnnual) {
                return 'Pays below your minimum ('.number_format($top).' < '.number_format($p->salaryMinAnnual).' '.$p->salaryCurrency.'/year)';
            }
        }

        return null;
    }
}
