<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus as S;
use App\Enums\SourceType;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\User;
use App\Services\Applications\ApplicationService;
use App\Services\Applications\DraftService;
use App\Services\Insights\InsightsService;
use App\Services\Matching\MatchService;
use App\Services\Profile\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\JobPilotHelpers;
use Tests\TestCase;

class InsightsTest extends TestCase
{
    use JobPilotHelpers, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jobpilot.gemini.api_key' => null]);
        $this->owner = $this->owner();
    }

    private function funnelCount(array $data, string $key): int
    {
        return collect($data['funnel'])->firstWhere('key', $key)['count'];
    }

    private function applied(string $title, int $daysAgo, ?S $then = null, $source = null): Application
    {
        $job = $this->job($source, ['externalId' => 'i'.random_int(1, 999999), 'title' => $title]);
        app(MatchService::class)->scoreMany([$job], notify: false);
        $svc = app(ApplicationService::class);
        $app = app(DraftService::class)->create($this->owner, $job);
        $svc->approve($app, $this->owner, ['reviewed' => 1, 'truthful' => 1]);
        $svc->markSubmittedManually($app->refresh(), $this->owner);
        Application::whereKey($app->id)->update(['submitted_at' => now()->subDays($daysAgo), 'created_at' => now()->subDays($daysAgo + 1)]);
        if ($then) {
            $svc->updateStatus($app->refresh(), $then);
            ApplicationEvent::where('application_id', $app->id)->where('to_status', $then->value)->update(['occurred_at' => now()->subDays($daysAgo)->addDays(2)]);
        }

        return $app->refresh();
    }

    public function test_page_renders_an_empty_state_for_a_fresh_install(): void
    {
        app(ProfileService::class)->setConsent(app(ProfileService::class)->forUser($this->owner), false);
        $this->actingAs($this->owner)->get(route('insights.index'))->assertOk()->assertSee('Nothing to analyse yet')->assertSee('Search funnel');
    }

    public function test_funnel_response_rate_and_median_days_are_computed_from_real_applications(): void
    {
        $this->profile($this->owner);
        $this->applied('Laravel Dev One', 20, S::Interview);            // heard back after 2 days
        $this->applied('Laravel Dev Two', 18, S::Rejected);             // heard back after 2 days
        $this->applied('Laravel Dev Three', 10);                         // silence
        $this->applied('Laravel Dev Four', 8, S::Offer);                 // heard back after 2 days
        $this->applied('Laravel Dev Five', 5);                           // silence

        $data = app(InsightsService::class)->build($this->owner, 90);

        $this->assertSame(5, $this->funnelCount($data, 'drafts'));
        $this->assertSame(5, $this->funnelCount($data, 'approved'));
        $this->assertSame(5, $this->funnelCount($data, 'submitted'));
        $this->assertSame(3, $this->funnelCount($data, 'responded'));
        $this->assertSame(2, $this->funnelCount($data, 'interviews'));   // interview + offer
        $this->assertSame(1, $this->funnelCount($data, 'offers'));
        $this->assertSame(5, $data['response']['submitted']);
        $this->assertSame(3, $data['response']['responded']);
        $this->assertSame(60, $data['response']['rate']);
        $this->assertSame(2, $data['response']['pending']);
        $this->assertEqualsWithDelta(2.0, $data['response']['median_days'], 0.1);

        $respondedPct = collect($data['funnel'])->firstWhere('key', 'responded')['pct'];
        $this->assertSame(60, $respondedPct, 'conversion is relative to the previous step');
    }

    public function test_period_filter_limits_the_cohort(): void
    {
        $this->profile($this->owner);
        $this->applied('Old Laravel Application', 100);
        $this->applied('New Laravel Application', 5);

        $this->assertSame(1, app(InsightsService::class)->build($this->owner, 30)['response']['submitted']);
        $this->assertSame(2, app(InsightsService::class)->build($this->owner, 180)['response']['submitted']);
    }

    public function test_by_source_table_shows_jobs_matches_and_response_rate(): void
    {
        $this->profile($this->owner);
        $a = $this->source(SourceType::Greenhouse, ['board_token' => 'a'], 'Source A');
        $b = $this->source(SourceType::Lever, ['site' => 'b'], 'Source B');
        $this->applied('Laravel From A', 10, S::ResponseReceived, $a);
        $this->applied('Laravel From A Too', 9, null, $a);
        $this->job($b, ['externalId' => 'only-b', 'title' => 'Chef', 'description' => str_repeat('Cook meals in a kitchen. ', 8), 'location' => 'Rome', 'isRemote' => false]);

        $rows = collect(app(InsightsService::class)->build($this->owner, 90)['bySource'])->keyBy('name');

        $this->assertSame(2, $rows['Source A']['submitted']);
        $this->assertSame(1, $rows['Source A']['responded']);
        $this->assertSame(50, $rows['Source A']['rate']);
        $this->assertSame(0, $rows['Source B']['applications']);
        $this->assertSame(1, $rows['Source B']['jobs']);
        $this->assertNull($rows['Source B']['rate']);
    }

    public function test_score_histogram_counts_every_active_scored_job(): void
    {
        $this->profile($this->owner);
        foreach (range(1, 4) as $i) {
            $job = $this->job(null, ['externalId' => "h$i"]);
            app(MatchService::class)->scoreMany([$job], notify: false);
        }
        $hist = app(InsightsService::class)->build($this->owner)['histogram'];

        $this->assertCount(10, $hist);
        $this->assertSame(4, array_sum(array_column($hist, 'count')));
    }

    public function test_skill_demand_and_gaps_reflect_the_market_and_your_profile(): void
    {
        $this->profile($this->owner, ['skills' => 'PHP, Laravel, MySQL, Vue.js, Docker']);
        settings()->set('threshold_potential', 10);
        foreach (range(1, 6) as $i) {
            $job = $this->job(null, ['externalId' => "g$i", 'title' => 'Laravel Developer', 'description' => 'Laravel and PHP APIs with Kubernetes and Terraform. Fully remote, work from anywhere in the world.']);
            app(MatchService::class)->scoreMany([$job], notify: false);
        }

        $skills = app(InsightsService::class)->build($this->owner)['skills'];

        $demand = collect($skills['demand'])->keyBy('skill');
        $this->assertTrue($demand['Laravel']['have']);
        $this->assertFalse($demand['Kubernetes']['have']);
        $this->assertSame(100, $demand['Laravel']['pct']);
        $this->assertSame(6, $skills['jobs']);
        $gaps = collect($skills['gaps'])->pluck('count', 'skill');
        $this->assertSame(6, $gaps['Kubernetes']);
        $this->assertSame(6, $gaps['Terraform']);
        $this->assertArrayNotHasKey('Laravel', $gaps->all(), 'skills you have are not gaps');
        $this->assertGreaterThan(0, $skills['coverage']);
    }

    public function test_gaps_ignore_excluded_and_restricted_jobs(): void
    {
        $this->profile($this->owner, ['skills' => 'PHP, Laravel']);
        settings()->setMany(['threshold_potential' => 10, 'home_country' => 'Pakistan', 'blocked_companies' => ['Blocked']]);
        $this->scoreJob(['externalId' => 'e1', 'company' => 'Blocked Inc', 'description' => 'Laravel with Kubernetes. Remote worldwide, work from anywhere.']);
        $this->scoreJob(['externalId' => 'e2', 'location' => 'Remote - US', 'remoteScope' => 'country', 'eligibleCountries' => ['United States'], 'description' => 'Laravel with Terraform.']);

        $gaps = collect(app(InsightsService::class)->build($this->owner)['skills']['gaps'])->pluck('skill')->all();

        $this->assertNotContains('Kubernetes', $gaps);
        $this->assertNotContains('Terraform', $gaps);
    }

    private function scoreJob(array $over): void
    {
        $job = $this->job(null, $over + ['title' => 'Laravel Developer']);
        app(MatchService::class)->scoreMany([$job], notify: false);
    }

    public function test_salary_insight_needs_enough_samples_and_never_mixes_currencies(): void
    {
        $this->profile($this->owner);
        $this->assertNull(app(InsightsService::class)->build($this->owner)['salary'], 'no data, no insight');

        foreach ([60, 80, 100, 120, 140] as $i => $k) {
            $this->job(null, ['externalId' => "s$i", 'salaryMin' => $k * 1000 - 10000, 'salaryMax' => $k * 1000, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'year']);
        }
        $this->job(null, ['externalId' => 'eur', 'salaryMin' => 1, 'salaryMax' => 999999, 'salaryCurrency' => 'EUR', 'salaryPeriod' => 'year']);

        $s = app(InsightsService::class)->build($this->owner)['salary'];

        $this->assertSame('USD', $s['currency']);
        $this->assertSame(5, $s['n']);
        $this->assertSame(100000, $s['median']);
        $this->assertSame(80000, $s['p25']);
        $this->assertSame(120000, $s['p75']);
    }

    public function test_eligibility_mix_and_weekly_series(): void
    {
        $this->profile($this->owner);
        settings()->set('home_country', 'Pakistan');
        $this->scoreJob(['externalId' => 'm1', 'location' => 'Remote - Worldwide', 'remoteScope' => 'worldwide']);
        $this->scoreJob(['externalId' => 'm2', 'location' => 'Remote - US', 'remoteScope' => 'country', 'eligibleCountries' => ['United States']]);

        $data = app(InsightsService::class)->build($this->owner);

        $this->assertSame(['eligible' => 1, 'unclear' => 0, 'restricted' => 1], $data['eligibility']);
        $this->assertCount(8, $data['weekly']);
        $this->assertSame(2, array_sum(array_column($data['weekly'], 'jobs')));
    }

    public function test_page_renders_with_data_and_validates_the_period(): void
    {
        $this->profile($this->owner);
        $this->applied('Laravel Dev Page', 10, S::Interview);

        $this->actingAs($this->owner)->get(route('insights.index'))->assertOk()
            ->assertSee('Search funnel')->assertSee('Which sources pay off')->assertSee('Skills the market asks for')->assertSee('Your biggest skill gaps')->assertSee('Score distribution');
        $this->actingAs($this->owner)->get(route('insights.index', ['days' => 30]))->assertOk();
        $this->actingAs($this->owner)->get(route('insights.index', ['days' => 7]))->assertSessionHasErrors('days');
        auth()->logout();
        $this->get(route('insights.index'))->assertRedirect(route('login'));
    }
}
