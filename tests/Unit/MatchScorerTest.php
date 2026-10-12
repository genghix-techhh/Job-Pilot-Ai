<?php

namespace Tests\Unit;

use App\Enums\EligibilityStatus;
use App\Enums\MatchTier;
use App\Models\CandidateProfile;
use App\Models\JobPosting;
use App\Services\Matching\MatchScorer;
use App\Services\Matching\ProfileSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\JobPilotHelpers;
use Tests\TestCase;

class MatchScorerTest extends TestCase
{
    use JobPilotHelpers, RefreshDatabase;

    private function score(JobPosting $job, array $profileOver = [], ?array $assessment = null): array
    {
        $profile = $this->profile($this->owner(), $profileOver, consent: false);

        return app(MatchScorer::class)->score($job, ProfileSnapshot::from($profile, settings()), null, $assessment);
    }

    public function test_good_fit_scores_high_with_transparent_factors(): void
    {
        $r = $this->score($this->job());

        $this->assertGreaterThanOrEqual(65, $r['score']);
        $this->assertSame(['skills', 'duties', 'seniority', 'projects', 'salary', 'freshness'], array_keys($r['factors']));
        $this->assertContains('Laravel', $r['matched_skills']);
        $this->assertContains('PHP', $r['matched_skills']);
        foreach ($r['factors'] as $f) {
            $this->assertArrayHasKey('detail', $f);
            $this->assertNotSame('', $f['detail']);
        }
        $this->assertEqualsWithDelta($r['score'], array_sum(array_column($r['factors'], 'points')), 0.2);
    }

    public function test_default_weights_are_30_25_15_15_10_5(): void
    {
        $this->assertSame(['skills' => 30.0, 'duties' => 25.0, 'seniority' => 15.0, 'projects' => 15.0, 'salary' => 10.0, 'freshness' => 5.0], settings()->weights());
    }

    public function test_salary_weight_is_redistributed_not_zeroed_when_no_preference_is_set(): void
    {
        $r = $this->score($this->job(['salaryMin' => 100000, 'salaryMax' => 120000, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'year']));

        $this->assertFalse($r['factors']['salary']['applicable']);
        $this->assertSame(0.0, $r['factors']['salary']['points']);
        // The remaining five factors share 100 points: 30+25+15+15+5 = 90 -> maxima scale by 100/90.
        $this->assertEqualsWithDelta(100, array_sum(array_column($r['factors'], 'max')), 0.5);
        $this->assertEqualsWithDelta(33.3, $r['factors']['skills']['max'], 0.2);
    }

    public function test_salary_factor_applies_when_preference_and_job_salary_are_comparable(): void
    {
        $profile = ['salary_min' => 100000, 'salary_currency' => 'USD', 'salary_period' => 'year'];
        $high = $this->score($this->job(['salaryMin' => 110000, 'salaryMax' => 130000, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'year']), $profile);
        $this->assertTrue($high['factors']['salary']['applicable']);
        $this->assertSame(1.0, $high['factors']['salary']['pct']);
    }

    public function test_salary_in_another_currency_is_not_compared_or_converted(): void
    {
        $r = $this->score($this->job(['salaryMin' => 50000, 'salaryMax' => 60000, 'salaryCurrency' => 'EUR', 'salaryPeriod' => 'year']),
            ['salary_min' => 100000, 'salary_currency' => 'USD', 'salary_period' => 'year']);
        $this->assertFalse($r['factors']['salary']['applicable']);
        $this->assertStringContainsString('not converted', $r['factors']['salary']['detail']);
    }

    public function test_custom_weights_change_the_score_and_tiers_follow_thresholds(): void
    {
        $job = $this->job();
        $base = $this->score($job);

        settings()->set('weights', ['skills' => 100, 'duties' => 0, 'seniority' => 0, 'projects' => 0, 'salary' => 0, 'freshness' => 0]);
        settings()->flush();
        $skillsOnly = app(MatchScorer::class)->score($job, ProfileSnapshot::from(CandidateProfile::first(), settings()));

        $this->assertNotSame($base['score'], $skillsOnly['score']);
        $this->assertSame(100.0, $skillsOnly['factors']['skills']['max']);

        settings()->setMany(['threshold_strong' => 99, 'threshold_potential' => 98]);
        $this->assertSame(MatchTier::Low, app(MatchScorer::class)->tier(97.0));
        $this->assertSame(MatchTier::Potential, app(MatchScorer::class)->tier(98.0));
        $this->assertSame(MatchTier::Strong, app(MatchScorer::class)->tier(99.0));
    }

    public function test_unrelated_job_scores_low(): void
    {
        $r = $this->score($this->job(null, ['title' => 'Head Chef', 'description' => 'Cook meals in our restaurant kitchen. Knife skills required.', 'location' => 'Rome']));
        $this->assertLessThan(50, $r['score']);
        $this->assertSame([], $r['matched_skills']);
    }

    public function test_ai_assessment_is_blended_not_trusted_blindly(): void
    {
        $job = $this->job();
        $without = $this->score($job);
        $low = $this->score($job, [], ['duties_fit' => 0, 'project_fit' => 0]);
        $high = $this->score($job, [], ['duties_fit' => 100, 'project_fit' => 100]);

        $this->assertLessThan($without['score'], $low['score']);
        $this->assertGreaterThan($without['score'], $high['score']);
        $this->assertLessThan(100, $high['score'] - $low['score'], 'AI can move duties/projects only by half their weight');
    }

    public function test_missing_skills_are_reported(): void
    {
        $r = $this->score($this->job(null, ['description' => 'We use PHP, Laravel, Kubernetes and Terraform every day.']));
        $this->assertContains('Kubernetes', $r['missing_skills']);
        $this->assertContains('Terraform', $r['missing_skills']);
    }

    // ---- eligibility: never guessed ------------------------------------------------------

    private function elig(array $jobOver, string $home = ''): EligibilityStatus
    {
        settings()->set('home_country', $home);
        $job = $this->job(null, $jobOver);

        return $this->score($job)['eligibility_status'];
    }

    public function test_bare_remote_is_unclear_not_eligible(): void
    {
        $this->assertSame(EligibilityStatus::Unclear, $this->elig(['location' => 'Remote', 'remoteScope' => 'unclear', 'description' => 'Join us. PHP role.', 'isRemote' => true], 'Pakistan'));
    }

    public function test_explicit_worldwide_is_eligible(): void
    {
        $this->assertSame(EligibilityStatus::Eligible, $this->elig(['location' => 'Remote - Worldwide', 'remoteScope' => 'worldwide', 'isRemote' => true], 'Pakistan'));
    }

    public function test_named_country_list_without_home_country_set_is_unclear(): void
    {
        $this->assertSame(EligibilityStatus::Unclear, $this->elig(['location' => 'Remote - US', 'remoteScope' => 'country', 'eligibleCountries' => ['United States'], 'isRemote' => true], ''));
    }

    public function test_country_list_excluding_home_country_is_restricted(): void
    {
        $this->assertSame(EligibilityStatus::Restricted, $this->elig(['location' => 'Remote - US', 'remoteScope' => 'country', 'eligibleCountries' => ['United States'], 'isRemote' => true], 'Pakistan'));
    }

    public function test_country_list_including_home_country_is_eligible(): void
    {
        $this->assertSame(EligibilityStatus::Eligible, $this->elig(['location' => 'Remote - Pakistan, India', 'remoteScope' => 'region', 'eligibleCountries' => ['Pakistan', 'India'], 'isRemote' => true], 'Pakistan'));
    }

    public function test_vague_regions_stay_unclear_even_with_home_country(): void
    {
        $this->assertSame(EligibilityStatus::Unclear, $this->elig(['location' => 'Remote - APAC', 'remoteScope' => 'unclear', 'isRemote' => true, 'description' => 'PHP developer role.'], 'Pakistan'));
    }

    public function test_eu_region_is_decided_by_membership(): void
    {
        $this->assertSame(EligibilityStatus::Restricted, $this->elig(['location' => 'Remote - EU', 'remoteScope' => 'region', 'eligibleCountries' => ['EU'], 'isRemote' => true], 'Pakistan'));
        $this->assertSame(EligibilityStatus::Eligible, $this->elig(['location' => 'Remote - EU', 'remoteScope' => 'region', 'eligibleCountries' => ['EU'], 'isRemote' => true], 'Germany'));
    }

    public function test_onsite_and_hybrid_are_flagged_restricted(): void
    {
        $this->assertSame(EligibilityStatus::Restricted, $this->elig(['location' => 'Berlin (hybrid)', 'remoteScope' => 'hybrid', 'isRemote' => false], 'Germany'));
    }

    public function test_eligibility_is_not_part_of_the_numeric_score(): void
    {
        settings()->set('home_country', 'Pakistan');
        $open = $this->score($this->job(null, ['externalId' => 'e1', 'location' => 'Remote - Worldwide', 'remoteScope' => 'worldwide']));
        $closed = $this->score($this->job(null, ['externalId' => 'e2', 'location' => 'Remote - US', 'remoteScope' => 'country', 'eligibleCountries' => ['United States']]));
        $this->assertSame($open['score'], $closed['score']);
    }
}
