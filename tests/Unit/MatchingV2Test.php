<?php

namespace Tests\Unit;

use App\Enums\SourceType;
use App\Models\JobPosting;
use App\Services\Matching\ExclusionRules;
use App\Services\Matching\IdfIndex;
use App\Services\Matching\MatchScorer;
use App\Services\Matching\ProfileSnapshot;
use App\Services\Matching\RequirementSplitter;
use App\Services\Matching\TextOverlap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\JobPilotHelpers;
use Tests\TestCase;

class MatchingV2Test extends TestCase
{
    use JobPilotHelpers, RefreshDatabase;

    private function snap(array $over = []): ProfileSnapshot
    {
        return ProfileSnapshot::from($this->profile($this->owner(), $over, consent: false), settings());
    }

    // ---- required vs nice-to-have ----------------------------------------------------------------

    public function test_nice_to_have_section_is_split_from_requirements(): void
    {
        $desc = "About the role\nYou will build services in PHP and Laravel.\n\nRequirements\n- MySQL experience\n- Docker\n\nNice to have\n- Kubernetes\n- Terraform\n- Redis\n\nBenefits\nFree lunch, work with Git hooks and GitHub.";

        $r = RequirementSplitter::split('Backend Developer', $desc);

        $this->assertEqualsCanonicalizing(['PHP', 'Laravel', 'MySQL', 'Docker', 'Git'], $r['required']);
        $this->assertEqualsCanonicalizing(['Kubernetes', 'Terraform', 'Redis'], $r['preferred']);
        $this->assertSame([], $r['title']);
    }

    public function test_title_skills_are_detected_and_counted_as_required(): void
    {
        $r = RequirementSplitter::split('Senior Laravel Developer', 'We use PHP daily.');
        $this->assertSame(['Laravel'], $r['title']);
        $this->assertContains('Laravel', $r['required']);
    }

    public function test_postings_without_a_bonus_section_treat_everything_as_required(): void
    {
        $r = RequirementSplitter::split('Dev', 'PHP, Laravel, MySQL and a nice-to-have mention inline only.');
        $this->assertSame([], $r['preferred']);
        $this->assertContains('MySQL', $r['required']);
    }

    public function test_bonus_skills_count_for_less_than_required_skills(): void
    {
        $scorer = app(MatchScorer::class);
        $snap = $this->snap(['skills' => 'PHP, Laravel, MySQL, Docker, Redis, Vue.js']);
        $req = $this->job(null, ['externalId' => 'a', 'title' => 'Developer', 'description' => "Needs PHP, Laravel and MySQL.\nNice to have\nKubernetes, Terraform, Ansible, Go"]);
        $bonusHeavy = $this->job(null, ['externalId' => 'b', 'title' => 'Developer', 'description' => "Needs Kubernetes, Terraform and Go.\nNice to have\nPHP, Laravel, MySQL"]);

        $a = $scorer->score($req, $snap)['factors']['skills']['pct'];
        $b = $scorer->score($bonusHeavy, $snap)['factors']['skills']['pct'];

        $this->assertGreaterThan($b, $a, 'owning the REQUIRED skills must beat owning only the bonus ones');
    }

    public function test_a_skill_in_the_title_counts_double(): void
    {
        $snap = $this->snap(['skills' => 'Laravel']);
        $scorer = app(MatchScorer::class);
        $inTitle = $this->job(null, ['externalId' => 't', 'title' => 'Laravel Developer', 'description' => 'Build APIs with Laravel, Kubernetes and Terraform.']);
        $inBody = $this->job(null, ['externalId' => 'u', 'title' => 'Software Developer', 'description' => 'Build APIs with Laravel, Kubernetes and Terraform.']);

        $this->assertGreaterThan(
            $scorer->score($inBody, $snap)['factors']['skills']['pct'],
            $scorer->score($inTitle, $snap)['factors']['skills']['pct']
        );
    }

    // ---- TF-IDF ----------------------------------------------------------------------------------

    private function seedCorpus(int $n = 40): void
    {
        $source = $this->source();
        $boiler = 'We are a fast growing remote team looking for an experienced engineer to join our team and help build great products with our customers. ';
        for ($i = 0; $i < $n; $i++) {
            $this->job($source, ['externalId' => "c$i", 'title' => "Engineer $i", 'description' => $boiler.($i % 2 ? 'Daily work in Java and Spring services.' : 'Daily work with Excel dashboards and reporting.')]);
        }
        Cache::forget('matching.idf.v1');
    }

    public function test_idf_index_needs_a_minimum_corpus_and_downweights_boilerplate(): void
    {
        $idx = new IdfIndex;
        $this->assertFalse($idx->usable(), 'empty corpus is not usable');

        $this->seedCorpus();
        $idx->invalidate();
        $this->assertTrue($idx->usable());
        $everywhere = TextOverlap::tokens('engineer')[0];   // appears in every seeded posting (after stemming)
        $distinctive = TextOverlap::tokens('java')[0];     // appears in half of them
        $this->assertLessThan($idx->idf($distinctive), $idx->idf($everywhere), 'a word in every posting must weigh less than a distinctive one');
        $this->assertGreaterThan(0, $idx->idf('neverseenterm'), 'unseen terms get the maximum weight');
    }

    public function test_similarity_rewards_distinctive_overlap_over_boilerplate_overlap(): void
    {
        $this->seedCorpus();
        $idx = new IdfIndex;
        $idx->invalidate();

        $cv = 'Built Java and Spring services for payments. Led the team.';
        $distinctive = TextOverlap::tfidfSimilarity('Senior engineer: Java and Spring services, payments platform', $cv, $idx);
        $boilerplateOnly = TextOverlap::tfidfSimilarity('We are a fast growing remote team looking for an experienced engineer to help build great products', $cv, $idx);

        $this->assertGreaterThan($boilerplateOnly, $distinctive);
        $this->assertSame(0.0, TextOverlap::tfidfSimilarity('', $cv, $idx));
        $this->assertGreaterThanOrEqual(0.0, $boilerplateOnly);
        $this->assertLessThanOrEqual(1.0, $distinctive);
    }

    public function test_scoring_falls_back_to_plain_overlap_with_a_tiny_corpus_and_says_so(): void
    {
        Cache::forget('matching.idf.v1');
        $job = $this->job();
        $r = app(MatchScorer::class)->score($job, $this->snap());
        $this->assertStringContainsString('too few collected jobs', $r['factors']['duties']['detail']);
    }

    public function test_scoring_uses_tfidf_once_enough_jobs_exist_and_ranks_relevant_work_higher(): void
    {
        $this->seedCorpus();
        $snap = $this->snap();
        $relevant = $this->job(null, ['externalId' => 'r1', 'title' => 'Full-stack Developer', 'description' => 'Build REST APIs in Laravel and MySQL, create Vue.js dashboards for internal reporting, containerise services with Docker.']);
        $unrelated = $this->job(null, ['externalId' => 'r2', 'title' => 'Excel Reporting Analyst', 'description' => 'Daily work with Excel dashboards and reporting for finance stakeholders.']);

        $scorer = app(MatchScorer::class);
        $a = $scorer->score($relevant, $snap);
        $b = $scorer->score($unrelated, $snap);

        $this->assertStringContainsString('TF-IDF', $a['factors']['duties']['detail']);
        $this->assertGreaterThan($b['factors']['duties']['pct'], $a['factors']['duties']['pct']);
        $this->assertGreaterThan($b['score'], $a['score']);
    }

    public function test_title_resembling_past_roles_lifts_duties(): void
    {
        $snap = $this->snap();   // past title: "Full-stack Developer"
        $scorer = app(MatchScorer::class);
        $near = $this->job(null, ['externalId' => 'n1', 'title' => 'Senior Full Stack Developer', 'description' => 'Generic text about a company and a role.']);
        $far = $this->job(null, ['externalId' => 'n2', 'title' => 'Warehouse Supervisor', 'description' => 'Generic text about a company and a role.']);

        $this->assertGreaterThan($scorer->score($far, $snap)['factors']['duties']['pct'], $scorer->score($near, $snap)['factors']['duties']['pct']);
    }

    // ---- exclusion rules -----------------------------------------------------------------------------

    private function exclusion(array $jobOver, array $settings, array $profileOver = []): ?string
    {
        settings()->setMany($settings);
        $job = $this->job(null, $jobOver + ['externalId' => 'x'.random_int(1, 99999)]);

        return app(ExclusionRules::class)->check($job, $this->snap($profileOver));
    }

    public function test_blocked_companies_match_whole_names_only(): void
    {
        $this->assertStringContainsString('Blocked company', (string) $this->exclusion(['company' => 'Acme Corp'], ['blocked_companies' => ['Acme']]));
        $this->assertNull($this->exclusion(['company' => 'Acmeology'], ['blocked_companies' => ['Acme']]));
        $this->assertStringContainsString('Blocked', (string) $this->exclusion(['company' => 'The ACME Group'], ['blocked_companies' => ['acme']]));
        $this->assertNull($this->exclusion(['company' => 'Other'], ['blocked_companies' => ['Acme']]));
    }

    public function test_excluded_keywords_are_whole_words_in_title_or_description(): void
    {
        $s = ['exclude_keywords' => ['unpaid', 'commission only']];
        $this->assertStringContainsString('unpaid', (string) $this->exclusion(['title' => 'Unpaid Intern Developer'], $s));
        $this->assertStringContainsString('commission only', (string) $this->exclusion(['description' => 'This is a commission only role. PHP.'], $s));
        $this->assertNull($this->exclusion(['description' => 'Fully paid work with Laravel.'], $s), '"unpaid" must not match "paid"');
        $this->assertNull($this->exclusion(['description' => 'You will be underpaid nowhere.'], $s));
    }

    public function test_title_prefixed_keywords_only_look_at_the_title(): void
    {
        $s = ['exclude_keywords' => ['title:manager']];
        $this->assertStringContainsString('Title contains', (string) $this->exclusion(['title' => 'Engineering Manager'], $s));
        $this->assertNull($this->exclusion(['title' => 'Backend Developer', 'description' => 'You will work with the product manager.'], $s), 'description mentions must not trigger a title-only rule');
        $this->assertNull($this->exclusion(['title' => 'Backend Developer'], ['exclude_keywords' => ['title:']]), 'an empty title rule is ignored');
        $this->assertStringContainsString('excluded keyword', (string) $this->exclusion(['title' => 'Backend Developer', 'description' => 'work with the product manager'], ['exclude_keywords' => ['manager']]), 'a plain keyword still matches the description');
    }

    public function test_salary_floor_exclusion_only_applies_when_comparable_and_enabled(): void
    {
        $profile = ['salary_min' => 100000, 'salary_currency' => 'USD', 'salary_period' => 'year'];
        $low = ['salaryMin' => 40000, 'salaryMax' => 60000, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'year'];

        $this->assertNull($this->exclusion($low, ['exclude_below_min_salary' => false], $profile), 'off by default');
        $this->assertStringContainsString('below your minimum', (string) $this->exclusion($low, ['exclude_below_min_salary' => true], $profile));
        $this->assertNull($this->exclusion(['salaryMin' => 40000, 'salaryMax' => 60000, 'salaryCurrency' => 'EUR', 'salaryPeriod' => 'year'], ['exclude_below_min_salary' => true], $profile), 'currencies are not converted');
        $this->assertNull($this->exclusion([], ['exclude_below_min_salary' => true], $profile), 'unknown salary is never an exclusion');
        $this->assertNull($this->exclusion(['salaryMin' => 90000, 'salaryMax' => 120000, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'year'], ['exclude_below_min_salary' => true], $profile));
    }

    public function test_scorer_reports_the_exclusion_and_changing_rules_changes_the_scoring_hash(): void
    {
        settings()->set('blocked_companies', ['Acme']);
        $job = $this->job(null, ['company' => 'Acme']);
        $this->assertNotNull(app(MatchScorer::class)->score($job, $this->snap())['exclusion']);

        $before = settings()->scoringHash();
        settings()->set('exclude_keywords', ['intern']);
        $this->assertNotSame($before, settings()->scoringHash(), 'new rules must trigger re-scoring');
    }

    public function test_job_model_is_untouched_by_scoring(): void
    {
        $job = $this->job();
        $copy = $job->only(['title', 'description', 'skills']);
        app(MatchScorer::class)->score($job, $this->snap());
        $this->assertSame($copy, $job->refresh()->only(['title', 'description', 'skills']));
        $this->assertInstanceOf(JobPosting::class, $job);
        $this->assertSame(SourceType::Greenhouse, $job->source->type);
    }
}
