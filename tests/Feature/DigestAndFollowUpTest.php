<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus as S;
use App\Jobs\RecalculateMatches;
use App\Mail\AlertMail;
use App\Models\Application;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\NotificationLog;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\Applications\ApplicationService;
use App\Services\Applications\DraftService;
use App\Services\Applications\FollowUpService;
use App\Services\Matching\MatchService;
use App\Services\Notifications\DigestService;
use App\Services\Profile\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\JobPilotHelpers;
use Tests\TestCase;

class DigestAndFollowUpTest extends TestCase
{
    use JobPilotHelpers, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jobpilot.gemini.api_key' => null]);
        $this->owner = $this->owner();
        $this->profile($this->owner);
        settings()->setMany(['home_country' => 'Pakistan', 'threshold_potential' => 20, 'threshold_strong' => 90]);
    }

    private function scored(array $over = []): JobPosting
    {
        $job = $this->job(null, $over + ['externalId' => 'd'.random_int(1, 999999)]);
        app(MatchService::class)->scoreMany([$job], notify: false);

        return $job->refresh();
    }

    // ---- saved searches -------------------------------------------------------------------------

    public function test_saved_searches_store_only_real_filters_and_open_the_feed_with_them(): void
    {
        $this->actingAs($this->owner)->post(route('searches.store'), ['name' => 'Laravel remote'])->assertSessionHas('error');   // nothing to save
        $this->assertSame(0, SavedSearch::count());

        $this->actingAs($this->owner)->post(route('searches.store'), ['name' => 'Laravel remote', 'q' => 'laravel', 'remote' => 1, 'min_score' => 40, 'sort' => 'date', 'view' => 'list'])->assertSessionHas('status');
        $s = SavedSearch::first();
        $this->assertEquals(['q' => 'laravel', 'remote' => 1, 'min_score' => 40], $s->filters, 'view/sort are presentation, not part of a search');
        $this->assertTrue($s->digest);

        $job = $this->scored(['title' => 'Laravel Developer']);
        $this->actingAs($this->owner)->get(route('jobs.index', $s->filters))->assertOk()->assertSee('Laravel Developer');
        $this->actingAs($this->owner)->get(route('jobs.index'))->assertSee('Laravel remote')->assertSee('Saved searches');

        $this->actingAs($this->owner)->patch(route('searches.update', $s));
        $this->assertFalse($s->refresh()->digest);
        $this->actingAs($this->owner)->delete(route('searches.destroy', $s));
        $this->assertSame(0, SavedSearch::count());
        $this->assertNotNull($job);
    }

    public function test_saved_searches_are_private_to_their_owner(): void
    {
        $s = SavedSearch::create(['user_id' => $this->owner->id, 'name' => 'x', 'filters' => ['q' => 'php']]);
        $other = User::create(['name' => 'O', 'email' => 'o@example.test', 'password' => 'another-long-password', 'timezone' => 'UTC']);
        app(ProfileService::class)->setConsent(app(ProfileService::class)->forUser($other), false);

        $this->actingAs($other)->delete(route('searches.destroy', $s))->assertForbidden();
        $this->actingAs($other)->patch(route('searches.update', $s))->assertForbidden();
    }

    // ---- bulk actions + exclusions in the feed -----------------------------------------------------

    public function test_bulk_save_hide_and_restore(): void
    {
        $a = $this->scored(['title' => 'Alpha Laravel Dev']);
        $b = $this->scored(['title' => 'Beta Laravel Dev']);
        $c = $this->scored(['title' => 'Gamma Laravel Dev']);

        $this->actingAs($this->owner)->post(route('jobs.bulk'), ['action' => 'hide', 'ids' => [$a->id, $b->id]])->assertSessionHas('status');
        $this->assertSame(['hidden', 'hidden', 'new'], [$a->refresh()->user_status, $b->refresh()->user_status, $c->refresh()->user_status]);
        $this->actingAs($this->owner)->get(route('jobs.index'))->assertDontSee('Alpha Laravel Dev')->assertSee('Gamma Laravel Dev');

        $this->actingAs($this->owner)->post(route('jobs.bulk'), ['action' => 'unhide', 'ids' => [$a->id]]);
        $this->actingAs($this->owner)->post(route('jobs.bulk'), ['action' => 'save', 'ids' => [$c->id]]);
        $this->assertSame(['new', 'saved'], [$a->refresh()->user_status, $c->refresh()->user_status]);

        $this->actingAs($this->owner)->post(route('jobs.bulk'), ['action' => 'hide', 'ids' => []])->assertSessionHasErrors('ids');
        $this->actingAs($this->owner)->post(route('jobs.bulk'), ['action' => 'delete', 'ids' => [$a->id]])->assertSessionHasErrors('action');
    }

    public function test_jobs_excluded_by_rules_are_hidden_but_reachable_and_get_no_ai_call(): void
    {
        settings()->set('blocked_companies', ['Shadycorp']);
        $ok = $this->scored(['title' => 'Good Laravel Role', 'company' => 'Fine Inc']);
        $bad = $this->scored(['title' => 'Excluded Laravel Role', 'company' => 'Shadycorp Ltd']);

        $match = JobMatch::where('job_id', $bad->id)->first();
        $this->assertStringContainsString('Blocked company', $match->exclusion);
        $this->assertSame('skipped', $match->ai_status);
        $this->assertStringContainsString('Excluded by your rules', $match->ai_note);

        $feed = $this->actingAs($this->owner)->get(route('jobs.index'))->assertOk();
        $feed->assertSee('Good Laravel Role')->assertDontSee('Excluded Laravel Role')->assertSee('1 excluded by your rules');
        $this->actingAs($this->owner)->get(route('jobs.index', ['show_excluded' => 1]))->assertSee('Excluded Laravel Role')->assertSee('Excluded by your rules');
        $this->actingAs($this->owner)->get(route('jobs.show', $bad))->assertOk()->assertSee('Excluded by your rules:')->assertSee('Blocked company');
        $this->assertNotNull($ok);

        $stats = $this->actingAs($this->owner)->get(route('dashboard'))->viewData('topMatches');
        $this->assertSame(['Good Laravel Role'], $stats->pluck('job.title')->all());
    }

    public function test_excluded_jobs_never_trigger_strong_match_alerts(): void
    {
        Mail::fake();
        settings()->setMany(['threshold_strong' => 20, 'threshold_potential' => 10, 'blocked_companies' => ['Shadycorp']]);
        $job = $this->job(null, ['company' => 'Shadycorp', 'externalId' => 'x1']);
        app(MatchService::class)->scoreMany([$job]);
        Mail::assertNothingSent();
    }

    public function test_changing_exclusion_rules_in_settings_rescoring_is_triggered(): void
    {
        Queue::fake();
        $payload = [
            'target_roles' => 'Backend Developer', 'location_preference' => 'Remote worldwide', 'experience_levels' => ['mid'],
            'weights' => ['skills' => 30, 'duties' => 25, 'seniority' => 15, 'projects' => 15, 'salary' => 10, 'freshness' => 5],
            'threshold_strong' => 80, 'threshold_potential' => 65, 'threshold_ai_prefilter' => 40, 'scan_frequency_minutes' => 60,
            'digest_frequency' => 'weekly', 'digest_hour' => 7, 'digest_max_items' => 5, 'followup_days' => 10,
            'blocked_companies' => "Acme\n Evil Corp \nacme", 'exclude_keywords' => 'unpaid, commission only', 'exclude_below_min_salary' => 1,
        ];
        $this->actingAs($this->owner)->put(route('settings.update'), $payload)->assertSessionHas('status');

        $this->assertSame(['Acme', 'Evil Corp'], settings()->get('blocked_companies'), 'trimmed and de-duplicated case-insensitively');
        $this->assertSame(['unpaid', 'commission only'], settings()->get('exclude_keywords'));
        $this->assertTrue(settings()->get('exclude_below_min_salary'));
        $this->assertSame('weekly', settings()->get('digest_frequency'));
        $this->assertSame(10, settings()->get('followup_days'));
        Queue::assertPushed(RecalculateMatches::class);
    }

    // ---- digest ------------------------------------------------------------------------------------

    public function test_digest_lists_new_best_matches_excluding_restricted_and_excluded_jobs(): void
    {
        Mail::fake();
        $this->scored(['title' => 'Open Laravel Role', 'externalId' => 'o1', 'company' => 'OpenCo']);
        $this->scored(['title' => 'US Only Laravel Role', 'externalId' => 'o2', 'location' => 'Remote - US', 'remoteScope' => 'country', 'eligibleCountries' => ['United States']]);
        settings()->set('blocked_companies', ['Blocked']);
        $this->scored(['title' => 'Blocked Laravel Role', 'externalId' => 'o3', 'company' => 'Blocked Co']);
        $old = $this->scored(['title' => 'Old Laravel Role', 'externalId' => 'o4']);
        $old->update(['first_seen_at' => now()->subDays(5)]);

        $data = app(DigestService::class)->collect($this->owner);
        $titles = array_column($data['items'], 'title');

        $this->assertSame(['Open Laravel Role — OpenCo'], $titles);
        $this->assertStringContainsString('/100', $data['items'][0]['meta']);
        $this->assertStringContainsString('/jobs/', $data['items'][0]['url']);
    }

    public function test_digest_includes_saved_search_matches_once(): void
    {
        $this->scored(['title' => 'Plain Laravel Role', 'externalId' => 's1']);
        $hit = $this->scored(['title' => 'Freelance Laravel Gig', 'externalId' => 's2', 'kind' => 'freelance']);
        SavedSearch::create(['user_id' => $this->owner->id, 'name' => 'Gigs', 'filters' => ['kind' => 'freelance', 'show_low' => 1], 'digest' => true]);
        SavedSearch::create(['user_id' => $this->owner->id, 'name' => 'Muted', 'filters' => ['q' => 'Plain', 'show_low' => 1], 'digest' => false]);

        $items = app(DigestService::class)->collect($this->owner)['items'];

        $gig = collect($items)->filter(fn ($i) => str_contains($i['title'], 'Gig'));
        $this->assertCount(1, $gig, 'a job found by the main list and a saved search is listed once');
        $this->assertNotNull($hit);
    }

    public function test_due_logic_respects_frequency_hour_timezone_and_last_sent(): void
    {
        $svc = app(DigestService::class);
        settings()->setMany(['digest_frequency' => 'daily', 'digest_hour' => 8]);

        $this->assertFalse($svc->isDue($this->owner, now('UTC')->setDate(2026, 10, 14)->setTime(2, 0)), '07:00 in Karachi is before 08:00');
        $this->assertTrue($svc->isDue($this->owner, now('UTC')->setDate(2026, 10, 14)->setTime(3, 30)), '08:30 in Karachi');

        settings()->set('digest_last_at', now('UTC')->setDate(2026, 10, 14)->setTime(3, 5)->toIso8601String());
        $this->assertFalse($svc->isDue($this->owner, now('UTC')->setDate(2026, 10, 14)->setTime(15, 0)), 'already sent today');
        $this->assertTrue($svc->isDue($this->owner, now('UTC')->setDate(2026, 10, 15)->setTime(3, 30)), 'next day');

        settings()->setMany(['digest_frequency' => 'weekly', 'digest_last_at' => null]);
        $this->assertFalse($svc->isDue($this->owner, now('UTC')->setDate(2026, 10, 14)->setTime(5, 0)), 'Wednesday');
        $this->assertTrue($svc->isDue($this->owner, now('UTC')->setDate(2026, 10, 12)->setTime(5, 0)), 'Monday');

        settings()->set('digest_frequency', 'off');
        $this->assertFalse($svc->isDue($this->owner, now('UTC')->setDate(2026, 10, 12)->setTime(5, 0)));
    }

    public function test_digest_is_sent_once_per_day_and_not_at_all_when_nothing_is_new(): void
    {
        Mail::fake();
        settings()->setMany(['digest_frequency' => 'daily', 'digest_hour' => 0]);
        $this->scored(['title' => 'Fresh Laravel Role']);
        $svc = app(DigestService::class);

        $first = $svc->send($this->owner);
        $second = $svc->send($this->owner);          // same day: not due any more

        $this->assertNotNull($first);
        $this->assertNull($second);
        Mail::assertSent(AlertMail::class, 1);
        Mail::assertSent(AlertMail::class, fn (AlertMail $m) => str_contains($m->heading, 'Daily digest: 1 new match') && count($m->items) === 1 && str_contains($m->render(), 'Fresh Laravel Role'));

        $this->travel(1)->days();
        $this->assertNull($svc->send($this->owner), 'nothing new since the last digest, so no e-mail');
        Mail::assertSent(AlertMail::class, 1);
    }

    public function test_digest_command_and_manual_send_button(): void
    {
        Mail::fake();
        $this->artisan('app:send-digest')->assertSuccessful()->expectsOutputToContain('Nothing to send');
        $this->actingAs($this->owner)->post(route('settings.send-digest'))->assertSessionHas('error');

        $this->scored(['title' => 'Manual Digest Role']);
        $this->actingAs($this->owner)->post(route('settings.send-digest'))->assertSessionHas('status');
        Mail::assertSent(AlertMail::class, fn (AlertMail $m) => str_contains($m->render(), 'Manual Digest Role'));
        $this->artisan('app:send-digest', ['--force' => true])->assertSuccessful();
    }

    public function test_digest_email_escapes_hostile_titles(): void
    {
        Mail::fake();
        $this->scored(['title' => '<script>alert(1)</script> Laravel', 'company' => '<b>Evil</b>']);
        app(DigestService::class)->send($this->owner, force: true, since: now()->subDay());
        Mail::assertSent(AlertMail::class, fn (AlertMail $m) => ! str_contains($m->render(), '<script>alert(1)</script>') && ! str_contains($m->render(), '<b>Evil</b>'));
    }

    // ---- follow-ups ----------------------------------------------------------------------------------

    private function submittedApp(int $daysAgo): Application
    {
        $job = $this->job(null, ['externalId' => 'f'.random_int(1, 999999), 'title' => 'Laravel Role '.random_int(1, 999), 'company' => 'Acme']);
        app(MatchService::class)->scoreMany([$job], notify: false);
        $svc = app(ApplicationService::class);
        $app = app(DraftService::class)->create($this->owner, $job);
        $svc->approve($app, $this->owner, ['reviewed' => 1, 'truthful' => 1]);
        $svc->markSubmittedManually($app->refresh(), $this->owner);
        Application::whereKey($app->id)->update(['submitted_at' => now()->subDays($daysAgo)]);

        return $app->refresh();
    }

    public function test_only_quiet_submitted_applications_are_due_for_follow_up(): void
    {
        settings()->set('followup_days', 7);
        $quiet = $this->submittedApp(9);
        $recent = $this->submittedApp(3);
        $answered = $this->submittedApp(20);
        app(ApplicationService::class)->updateStatus($answered, S::ResponseReceived);
        $planned = $this->submittedApp(12);
        $planned->update(['next_task' => 'Already on it']);

        $due = app(FollowUpService::class)->due($this->owner)->pluck('id')->all();

        $this->assertSame([$quiet->id], $due);
        $this->assertNotContains($recent->id, $due);

        settings()->set('followup_days', 0);
        $this->assertCount(0, app(FollowUpService::class)->due($this->owner), '0 turns the feature off');
    }

    public function test_follow_up_message_is_truthful_and_never_sent(): void
    {
        Mail::fake();
        $app = $this->submittedApp(8);
        $msg = app(FollowUpService::class)->message($app->load('user.profile', 'job'));

        $this->assertStringContainsString('Following up on my application', $msg);
        $this->assertStringContainsString($app->job->title, $msg);
        $this->assertStringContainsString('Ayesha Khan', $msg);
        $this->assertStringContainsString('Acme', $msg);
        $this->assertStringNotContainsString('{', $msg);
        Mail::assertNothingSent();
    }

    public function test_plan_and_dismiss_follow_up(): void
    {
        $app = $this->submittedApp(9);

        $this->actingAs($this->owner)->post(route('applications.followup.plan', $app))->assertSessionHas('status');
        $this->assertStringContainsString('Send follow-up to Acme', $app->refresh()->next_task);
        $this->assertCount(0, app(FollowUpService::class)->due($this->owner), 'a planned task replaces the nudge');
        $this->assertTrue($app->events()->where('type', 'followup_planned')->exists());

        $other = $this->submittedApp(10);
        $this->actingAs($this->owner)->post(route('applications.followup.dismiss', $other))->assertSessionHas('status');
        $this->assertNotNull($other->refresh()->artifacts['followup_dismissed_at']);
        $this->assertCount(0, app(FollowUpService::class)->due($this->owner));
    }

    public function test_follow_up_nudges_are_emailed_a_bounded_number_of_times(): void
    {
        Mail::fake();
        settings()->set('followup_days', 7);
        $app = $this->submittedApp(8);
        $svc = app(FollowUpService::class);

        $this->assertSame(1, $svc->notifyDue());
        $this->assertSame(0, $svc->notifyDue(), 'same bucket, no repeat');
        Mail::assertSent(AlertMail::class, 1);

        Application::whereKey($app->id)->update(['submitted_at' => now()->subDays(16)]);
        $this->assertSame(1, $svc->notifyDue(), 'a second, later nudge');
        Application::whereKey($app->id)->update(['submitted_at' => now()->subDays(60)]);
        $svc->notifyDue();
        $svc->notifyDue();
        $this->assertLessThanOrEqual(3, NotificationLog::where('type', 'followup')->count());

        settings()->set('notify_followups', false);
        Application::whereKey($app->id)->update(['submitted_at' => now()->subDays(100)]);
        $this->assertSame(0, $svc->notifyDue());
    }

    public function test_follow_up_ui_on_dashboard_and_studio(): void
    {
        $app = $this->submittedApp(9);

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->assertSee('Time to follow up')->assertSee('no reply for 9 days');
        $this->actingAs($this->owner)->get(route('applications.show', $app))->assertOk()
            ->assertSee('Message to copy')->assertSee('JobPilot never sends it')->assertSee('Plan this as my next task');

        $draft = app(DraftService::class)->create($this->owner, $this->job(null, ['externalId' => 'zz9']));
        $this->actingAs($this->owner)->get(route('applications.show', $draft))->assertOk()->assertDontSee('Message to copy');
    }

    public function test_reminders_command_includes_follow_up_nudges(): void
    {
        Mail::fake();
        $this->submittedApp(9);
        $this->artisan('app:send-reminders')->assertSuccessful()->expectsOutputToContain('Follow-up nudges: 1');
    }
}
