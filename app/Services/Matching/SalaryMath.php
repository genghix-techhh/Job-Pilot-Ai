<?php

namespace App\Services\Matching;

use App\Models\JobPosting;

/** Annualising salaries for comparison. Currencies are NEVER converted. */
final class SalaryMath
{
    /** Top of the stated range as a yearly figure, or null if it cannot be compared. */
    public static function annualTop(JobPosting $job): ?int
    {
        $top = $job->salary_max ?: $job->salary_min;
        if (! $top || ! $job->salary_period || $job->salary_period === 'project') {
            return null;
        }

        return (int) match ($job->salary_period) {
            'month' => $top * 12, 'week' => $top * 52, 'day' => $top * 260, 'hour' => $top * 2080, default => $top,
        };
    }

    /** Why a comparison is impossible (null when it is possible). */
    public static function incomparableReason(JobPosting $job, ProfileSnapshot $p): ?string
    {
        if (! $p->salaryMinAnnual) {
            return 'No salary preference set in your profile.';
        }
        if (! $job->hasSalary()) {
            return 'This posting does not state a salary.';
        }
        if (! $job->salary_period || $job->salary_period === 'project') {
            return 'Salary is stated per project, which cannot be compared with a yearly preference.';
        }
        if ($job->salary_currency !== $p->salaryCurrency) {
            return "Salary is in {$job->salary_currency}; your preference is in {$p->salaryCurrency}. Currencies are not converted.";
        }

        return null;
    }
}
