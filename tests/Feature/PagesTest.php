<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Jobs\RecalculateMatches;
use App\Jobs\SyncJobSource;
use App\Models\AiUsageLog;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\CandidateProfile;
use App\Models\CandidateProfileVersion;
use App\Models\ConnectedAccount;
use App\Models\InboundMessage;
use App\Models\Interview;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\NotificationLog;
use App\Models\Resume;
use App\Models\User;
use App\Services\Ai\AiBudget;
use App\Services\Ai\Redactor;
use App\Services\Applications\ApplicationService;
use App\Services\Applications\DraftService;
use App\Services\Interviews\InterviewService;
use App\Services\Matching\MatchService;
use App\Services\Profile\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\JobPilotHelpers;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use JobPilotHelpers, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jobpilot.gemini.api_key' => null]);
        $this->fakeDns();
        $this->owner = $this->owner();
    }

    private function asOwner()
    {
        return $this->actingAs($this->owner);
    }

    private function decided(): void
    {
        $this->profile($this->owner, consent: false);
        app(ProfileService::class)->setConsent($this->owner->profile, false);
    }

    // ---- dashboard --------------------------------------------------------------------------------

    public function test_first_run_dashboard_has_a_helpful_empty_state_and_no_fake_data(): void
    {
        app(ProfileService::class)->setConsent(app(ProfileService::class)->forUser($this->owner), false);

        $r = $this->asOwner()->get(route('dashboard'))->assertOk();
        $r->assertSee('Get started')->assertSee('Upload your CV')->assertSee('Add a job source')->assertSee('Review your profile to see scores');
        $this->assertSame(0, JobPosting::count());
        $this->assertSame(0, JobSource::count());
        $r->assertDontSee('Acme');
    }

    public function test_dashboard_numbers_come_from_real_data(): void
    {
        $this->profile($this->owner);
        settings()->setMany(['threshold_strong' => 20, 'threshold_potential' => 10]);
        $job = $this->job();
        app(MatchService::class)->scoreMany([$job], notify: false);
        $app = app(DraftService::class)->create($this->owner, $job);

        $r = $this->asOwner()->get(route('dashboard'))->assertOk();
        $r->assertSee('Senior Laravel Developer')->assertSee('Drafts to review');
        $stats = $r->viewData('stats');
        $this->assertSame(1, $stats['new']);
        $this->assertSame(1, $stats['strong']);
        $this->assertSame(1, $stats['drafts']);
        $this->assertSame(0, $stats['submitted']);
        $this->assertSame($job->company, $app->job->company);
    }

    public function test_dashboard_warns_when_the_queue_is_stalled(): void
    {
        $this->decided();
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time() - 3600]);
        $this->asOwner()->get(route('dashboard'))->assertOk()->assertSee('no queue worker seems to be running');
    }

    // ---- job feed -----------------------------------------------------------------------------------

    private function feedData(): void
    {
        $this->profile($this->owner);
        $s1 = $this->source(SourceType::Greenhouse, ['board_token' => 'acme'], 'Acme Board');
        $s2 = $this->source(SourceType::Lever, ['site' => 'beta'], 'Beta Lever');
        $this->job($s1, ['title' => 'Laravel Backend Engineer', 'externalId' => 'j1', 'company' => 'Acme', 'location' => 'Remote - Worldwide', 'remoteScope' => 'worldwide', 'postedAt' => now()->subHours(2)]);
        $this->job($s2, ['title' => 'Vue.js Frontend Developer', 'externalId' => 'j2', 'company' => 'Beta', 'location' => 'Remote - US', 'remoteScope' => 'country', 'eligibleCountries' => ['United States'], 'salaryMin' => 90000, 'salaryMax' => 120000, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'year']);
        $this->job($s2, ['title' => 'Head Chef', 'externalId' => 'j3', 'company' => 'Foodco', 'description' => str_repeat('Cook meals in a restaurant kitchen. ', 6), 'location' => 'Rome', 'isRemote' => false, 'remoteScope' => 'unclear', 'postedAt' => now()->subDays(5)]);
        $this->job($s1, ['title' => 'Freelance PHP Project', 'externalId' => 'j4', 'kind' => 'freelance', 'company' => 'Client X', 'description' => 'Build a Laravel invoicing tool. Budget: $2,000. Deliverables:\n- API\n- Admin UI. Remote.']);
        settings()->set('home_country', 'Pakistan');
        app(MatchService::class)->scoreMany(JobPosting::all(), notify: false);
    }

    public function test_feed_hides_low_matches_by_default_and_can_show_them(): void
    {
        $this->feedData();
        $this->asOwner()->get(route('jobs.index'))->assertOk()->assertSee('Laravel Backend Engineer')->assertDontSee('Head Chef')->assertSee('low match(es) hidden');
        $this->asOwner()->get(route('jobs.index', ['show_low' => 1]))->assertOk()->assertSee('Head Chef');
    }

    public function test_feed_filters(): void
    {
        $this->feedData();
        $get = fn (array $q) => $this->asOwner()->get(route('jobs.index', $q + ['show_low' => 1]));

        $get(['q' => 'Backend'])->assertSee('Laravel Backend Engineer')->assertDontSee('Vue.js Frontend Developer');
        $get(['q' => 'Foodco'])->assertSee('Head Chef')->assertDontSee('Laravel Backend Engineer');   // company search
        $get(['source' => JobSource::where('name', 'Acme Board')->value('id')])->assertSee('Laravel Backend Engineer')->assertDontSee('Vue.js Frontend');
        $get(['role' => 'Frontend Developer'])->assertSee('Vue.js Frontend Developer')->assertDontSee('Laravel Backend Engineer');
        $get(['role' => 'Freelance Projects'])->assertSee('Freelance PHP Project')->assertDontSee('Laravel Backend');
        $get(['kind' => 'freelance'])->assertSee('Freelance PHP Project')->assertDontSee('Head Chef');
        $get(['salary' => 1])->assertSee('Vue.js Frontend Developer')->assertDontSee('Laravel Backend Engineer');
        $get(['eligibility' => 'restricted'])->assertSee('Vue.js Frontend Developer')->assertDontSee('Laravel Backend Engineer');
        $get(['eligibility' => 'eligible'])->assertSee('Laravel Backend Engineer')->assertDontSee('Vue.js Frontend');
        $get(['posted' => 1])->assertSee('Laravel Backend Engineer')->assertDontSee('Head Chef');   // posted 2h ago vs 5 days ago
        $get(['min_score' => 99])->assertDontSee('Laravel Backend Engineer');
        $get(['q' => '%'])->assertOk();                     // LIKE wildcards are escaped, not interpreted
    }

    public function test_feed_sorting_and_views_and_pagination(): void
    {
        $this->feedData();
        $r = $this->asOwner()->get(route('jobs.index', ['show_low' => 1, 'sort' => 'score']))->assertOk();
        $scores = collect($r->viewData('jobs')->items())->pluck('match_score')->all();
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores, 'best match first');

        $this->asOwner()->get(route('jobs.index', ['view' => 'list', 'show_low' => 1]))->assertOk()->assertSee('<table', false);

        $src = JobSource::first();
        for ($i = 0; $i < 25; $i++) {
            $this->job($src, ['externalId' => "bulk-$i", 'title' => "Laravel Developer $i"]);
        }
        $this->asOwner()->get(route('jobs.index', ['sort' => 'date']))->assertOk()->assertSee('page=2', false);
    }

    public function test_hidden_jobs_leave_the_feed_and_can_be_found_again(): void
    {
        $this->feedData();
        $job = JobPosting::where('external_id', 'j1')->first();
        $this->asOwner()->patch(route('jobs.status', $job), ['action' => 'hide'])->assertSessionHas('status');
        $this->asOwner()->get(route('jobs.index'))->assertDontSee('Laravel Backend Engineer');
        $this->asOwner()->get(route('jobs.index', ['status' => 'hidden']))->assertSee('Laravel Backend Engineer');
        $this->asOwner()->patch(route('jobs.status', $job), ['action' => 'save']);
        $this->asOwner()->get(route('jobs.index', ['status' => 'saved']))->assertSee('Laravel Backend Engineer');
    }

    public function test_job_detail_shows_source_score_breakdown_eligibility_and_missing_skills(): void
    {
        $this->feedData();
        $job = JobPosting::where('external_id', 'j2')->first();
        $this->asOwner()->get(route('jobs.show', $job))->assertOk()
            ->assertSee('Vue.js Frontend Developer')->assertSee('Beta Lever')->assertSee('Open source website')
            ->assertSee('Score breakdown')->assertSee('Relevant technical skills')->assertSee('Salary preference')
            ->assertSee('Location restricted')->assertSee('Create draft')->assertSee('Nothing is sent to the employer');

        $free = JobPosting::where('external_id', 'j4')->first();
        $this->asOwner()->get(route('jobs.show', $free))->assertOk()->assertSee('Freelance fit')->assertSee('Unknowns');
    }

    public function test_job_detail_escapes_hostile_content(): void
    {
        $this->decided();
        $job = $this->job(null, ['title' => '<script>alert(1)</script> Dev', 'company' => '<img src=x onerror=alert(2)>', 'description' => 'Plain <b onmouseover=alert(3)>text</b> here ... PHP']);
        $html = $this->asOwner()->get(route('jobs.show', $job))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b onmouseover', $html);
    }

    public function test_manual_import_saves_a_link_without_fetching_it(): void
    {
        $this->decided();
        Http::fake();

        $this->asOwner()->post(route('jobs.store'), ['url' => 'https://www.linkedin.com/jobs/view/12345'])->assertRedirect();
        $job = JobPosting::first();
        $this->assertTrue($job->is_manual);
        $this->assertTrue($job->needs_details);
        $this->assertStringContainsString('linkedin.com', $job->title);
        Http::assertNothingSent();

        $this->asOwner()->post(route('jobs.store'), ['url' => 'https://www.linkedin.com/jobs/view/12345?utm_source=x'])->assertRedirect();
        $this->assertSame(1, JobPosting::count(), 'same link is not saved twice');

        $this->asOwner()->put(route('jobs.details', $job), ['title' => 'Laravel Developer', 'company' => 'Zed', 'description' => 'We use PHP, Laravel and MySQL for APIs. Fully remote role open worldwide.'])->assertSessionHas('status');
        $job->refresh();
        $this->assertFalse($job->needs_details);
        $this->assertContains('Laravel', $job->skills);

        foreach (['javascript:alert(1)', 'ftp://x.test/a', 'not a url', 'https://user:pw@evil.test/'] as $bad) {
            $this->asOwner()->post(route('jobs.store'), ['url' => $bad])->assertSessionHasErrors('url');
        }
    }

    public function test_only_manual_jobs_are_editable(): void
    {
        $this->decided();
        $job = $this->job();
        $this->asOwner()->put(route('jobs.details', $job), ['title' => 'Hacked'])->assertForbidden();
    }

    // ---- sources ---------------------------------------------------------------------------------------

    public function test_sources_page_and_adding_each_supported_source_type(): void
    {
        $this->decided();
        Queue::fake();
        $this->asOwner()->get(route('sources.index'))->assertOk()->assertSee('Add a source')->assertSee('intentionally not scraped');

        $this->asOwner()->post(route('sources.store'), ['type' => 'greenhouse', 'board_token' => 'stripe'])->assertSessionHas('status');
        $this->asOwner()->post(route('sources.store'), ['type' => 'lever', 'site' => 'netflix', 'region' => 'global'])->assertSessionHas('status');
        $this->asOwner()->post(route('sources.store'), ['type' => 'remoteok', 'keywords' => 'php, laravel'])->assertSessionHas('status');
        $this->asOwner()->post(route('sources.store'), ['type' => 'rss', 'url' => 'https://example.com/jobs.rss', 'kind' => 'employment'])->assertSessionHas('status');

        $this->assertSame(4, JobSource::count());
        Queue::assertPushed(SyncJobSource::class, 4);

        $this->asOwner()->post(route('sources.store'), ['type' => 'greenhouse', 'board_token' => 'stripe'])->assertSessionHas('error');
        $this->assertSame(4, JobSource::count());
        $this->asOwner()->get(route('sources.index'))->assertOk()->assertSee('stripe')->assertSee('netflix');
    }

    public function test_adding_unsafe_or_invalid_sources_is_rejected_with_messages(): void
    {
        $this->decided();
        Queue::fake();
        $this->asOwner()->post(route('sources.store'), ['type' => 'rss', 'url' => 'http://169.254.169.254/latest'])->assertSessionHasErrors('url');
        $this->asOwner()->post(route('sources.store'), ['type' => 'rss', 'url' => 'http://localhost:8000/feed'])->assertSessionHasErrors('url');
        $this->asOwner()->post(route('sources.store'), ['type' => 'greenhouse', 'board_token' => '../etc'])->assertSessionHasErrors('board_token');
        $this->asOwner()->post(route('sources.store'), ['type' => 'adzuna', 'country' => 'gb', 'what' => 'php'])->assertSessionHasErrors(['app_id', 'usage_confirmed']);
        $this->assertSame(0, JobSource::count());
    }

    public function test_source_test_sync_toggle_and_delete(): void
    {
        $this->decided();
        Queue::fake();
        $s = $this->source(SourceType::Greenhouse, ['board_token' => 'acme']);
        Http::fake([
            'boards-api.greenhouse.io/v1/boards/acme' => Http::response(['name' => 'Acme'], 200),
            'boards-api.greenhouse.io/v1/boards/acme/jobs*' => Http::response(['jobs' => []], 200),
        ]);

        $this->asOwner()->post(route('sources.test', $s))->assertSessionHas('status');
        $this->assertSame('ok', $s->refresh()->health_status);
        $this->asOwner()->post(route('sources.sync', $s))->assertSessionHas('status');
        Queue::assertPushed(SyncJobSource::class);
        $this->asOwner()->patch(route('sources.update', $s), ['enabled' => 0]);
        $this->assertFalse($s->refresh()->enabled);
        $this->assertSame('disabled', $s->health_status);

        $this->asOwner()->delete(route('sources.destroy', $s))->assertSessionHas('status');
        $this->assertSame(0, JobSource::count());
    }

    public function test_source_with_applications_cannot_be_deleted(): void
    {
        $this->profile($this->owner);
        $job = $this->job();
        app(DraftService::class)->create($this->owner, $job);

        $this->asOwner()->delete(route('sources.destroy', $job->source))->assertSessionHas('error');
        $this->assertSame(1, JobSource::count());
        $this->assertSame(1, Application::count());
    }

    public function test_sync_command_isolates_failures_and_reports_exit_codes(): void
    {
        $this->source(SourceType::Greenhouse, ['board_token' => 'bad'], 'Bad');
        $this->source(SourceType::Lever, ['site' => 'ok'], 'Ok');
        Http::fake([
            'boards-api.greenhouse.io/*' => Http::response('x', 500),
            'api.lever.co/*' => Http::sequence()->push('x', 500)->push([], 200),
        ]);
        $this->artisan('app:sync-job-sources')->assertFailed();        // every source failed -> non-zero
        $this->artisan('app:sync-job-sources')->assertSuccessful();    // partial failure (Lever now fine) still exits 0
        $this->artisan('app:sync-job-sources', ['source' => 9999])->assertFailed();
        $this->artisan('app:sync-job-sources', ['--due' => true])->expectsOutputToContain('No sources were due')->assertSuccessful();
    }

    // ---- settings / diagnostics ------------------------------------------------------------------------------

    public function test_settings_validate_and_changing_weights_triggers_rescoring(): void
    {
        $this->decided();
        Queue::fake();
        $base = [
            'target_roles' => 'Backend Developer, AI/ML Developer', 'location_preference' => 'Remote worldwide', 'experience_levels' => ['mid', 'senior'],
            'weights' => ['skills' => 40, 'duties' => 20, 'seniority' => 15, 'projects' => 15, 'salary' => 5, 'freshness' => 5],
            'threshold_strong' => 85, 'threshold_potential' => 60, 'threshold_ai_prefilter' => 30, 'scan_frequency_minutes' => 120,
            'notify_strong_matches' => 1, 'gemini_daily_limit' => 25,
            'digest_frequency' => 'daily', 'digest_hour' => 8, 'digest_max_items' => 10, 'followup_days' => 7,
        ];

        $this->asOwner()->put(route('settings.update'), $base)->assertSessionHas('status');
        Queue::assertPushed(RecalculateMatches::class);
        $this->assertSame(85, settings()->strongThreshold());
        $this->assertSame(40.0, settings()->weights()['skills']);
        $this->assertSame(['Backend Developer', 'AI/ML Developer'], settings()->get('target_roles'));
        $this->assertSame(25, app(AiBudget::class)->limit());
        $this->assertFalse(settings()->get('notify_drafts_awaiting'), 'unticked toggles are saved as off');

        $this->asOwner()->put(route('settings.update'), array_merge($base, ['threshold_potential' => 90]))->assertSessionHasErrors('threshold_potential');
        $this->asOwner()->put(route('settings.update'), array_merge($base, ['weights' => array_fill_keys(['skills', 'duties', 'seniority', 'projects', 'salary', 'freshness'], 0)]))->assertSessionHasErrors('weights');
    }

    public function test_default_preferences_match_the_brief(): void
    {
        $this->assertSame(['Full-stack Developer', 'AI/ML Developer', 'Software Engineer', 'Frontend Developer', 'Backend Developer', 'Freelance Projects'], settings()->get('target_roles'));
        $this->assertSame('Remote worldwide', settings()->get('location_preference'));
        $this->assertSame(['junior', 'mid', 'senior'], settings()->get('experience_levels'));
        $this->assertSame(80, settings()->strongThreshold());
        $this->assertSame(65, settings()->potentialThreshold());
        $this->assertSame('gemini-3.5-flash-lite', config('jobpilot.gemini.model'));
        $this->assertSame('Asia/Karachi', $this->owner->timezone);
        $this->assertSame('UTC', config('app.timezone'), 'datetimes are stored in UTC');
    }

    public function test_account_settings_change_timezone_and_password(): void
    {
        $this->decided();
        $this->asOwner()->put(route('settings.account'), ['name' => 'New Name', 'timezone' => 'Europe/Berlin', 'notification_email' => 'a@example.test'])->assertSessionHas('status');
        $this->assertSame('Europe/Berlin', $this->owner->refresh()->timezone);

        $this->asOwner()->put(route('settings.account'), ['name' => 'N', 'timezone' => 'UTC', 'current_password' => 'wrong', 'password' => 'brand-new-long-password', 'password_confirmation' => 'brand-new-long-password'])->assertSessionHasErrors('current_password');
        $this->asOwner()->put(route('settings.account'), ['name' => 'N', 'timezone' => 'UTC', 'current_password' => 'correct-horse-battery', 'password' => 'brand-new-long-password', 'password_confirmation' => 'brand-new-long-password'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('brand-new-long-password', $this->owner->refresh()->password));
    }

    public function test_times_are_displayed_in_the_selected_time_zone(): void
    {
        $this->decided();
        $i = app(InterviewService::class)->create($this->owner, ['kind' => 'interview', 'title' => 'Zone check', 'starts_at_local' => '2027-06-01 12:00', 'source_timezone' => 'UTC']);
        $this->asOwner()->get(route('interviews.index'))->assertSee('5:00 PM');   // 12:00 UTC in Asia/Karachi (UTC+5)
        $this->owner->update(['timezone' => 'America/New_York']);
        $this->asOwner()->get(route('interviews.index'))->assertSee('8:00 AM');
        $this->assertSame('2027-06-01 12:00:00', DB::table('interviews')->value('starts_at'), 'storage stays UTC');
    }

    public function test_diagnostics_page_shows_health_without_leaking_secrets(): void
    {
        $this->decided();
        config(['jobpilot.gemini.api_key' => 'AIzaSyA-SUPER-SECRET-KEY-1234567890', 'services.google.client_secret' => 'GOOG-SECRET', 'mail.mailers.smtp.password' => 'SMTP-PASS']);
        $this->source(SourceType::Adzuna, ['app_key' => 'ADZ-SECRET', 'country' => 'gb', 'what' => 'x'], 'Adz');
        AiUsageLog::create(['model' => 'm', 'task' => 't', 'called_on' => today(), 'status' => 'error', 'error_code' => 'http_403', 'error_message' => Redactor::scrubSecrets('bad key AIzaSyA-SUPER-SECRET-KEY-1234567890'), 'created_at' => now()]);

        $html = $this->asOwner()->get(route('activity.index'))->assertOk()->assertSee('Queue')->assertSee('Scheduler')->assertSee('Gemini')->getContent();
        foreach (['SUPER-SECRET', 'GOOG-SECRET', 'SMTP-PASS', 'ADZ-SECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
        $dash = $this->asOwner()->get(route('dashboard'))->getContent();
        $this->assertStringNotContainsString('SUPER-SECRET', $dash);
        $this->asOwner()->get(route('settings.edit'))->assertOk()->assertDontSee('SUPER-SECRET');
    }

    public function test_model_other_than_a_known_free_model_shows_a_warning(): void
    {
        $this->decided();
        config(['jobpilot.gemini.model' => 'gemini-9-ultra-paid']);
        $this->asOwner()->get(route('settings.edit'))->assertOk()->assertSee('Confirm its free availability in Google AI Studio');
    }

    public function test_pages_render_for_every_main_route(): void
    {
        $this->profile($this->owner);
        $job = $this->job();
        $app = app(DraftService::class)->create($this->owner, $job);
        foreach ([route('dashboard'), route('jobs.index'), route('jobs.create'), route('jobs.show', $job), route('profile.edit'), route('applications.index'), route('applications.show', $app),
            route('interviews.index'), route('inbox.index'), route('sources.index'), route('settings.edit'), route('activity.index'), route('privacy.show')] as $url) {
            $this->asOwner()->get($url)->assertOk();
        }
    }

    // ---- delete my data -------------------------------------------------------------------------------------

    public function test_delete_my_data_requires_confirmation_and_password(): void
    {
        $this->profile($this->owner);
        $this->asOwner()->delete(route('settings.delete-data'), ['confirm_text' => 'delete', 'password' => 'correct-horse-battery'])->assertSessionHasErrors('confirm_text');
        $this->asOwner()->delete(route('settings.delete-data'), ['confirm_text' => 'DELETE', 'password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertSame(1, CandidateProfile::count());
    }

    public function test_delete_my_data_removes_everything_personal_but_keeps_the_login(): void
    {
        Storage::fake('resumes');
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response('', 200)]);
        $this->profile($this->owner);
        $job = $this->job();
        app(MatchService::class)->scoreMany([$job], notify: false);
        $app = app(DraftService::class)->create($this->owner, $job);
        Storage::disk('resumes')->put('u1/file.txt', 'cv');
        Resume::create(['user_id' => $this->owner->id, 'disk_path' => 'u1/file.txt', 'original_filename' => 'cv.txt', 'mime' => 'text/plain', 'size' => 2, 'checksum' => str_repeat('a', 64), 'parsed_text' => 'secret cv text', 'parse_status' => 'parsed', 'version' => 1, 'is_active' => true]);
        $acct = ConnectedAccount::create(['user_id' => $this->owner->id, 'provider' => 'google', 'email' => 'me@gmail.test', 'access_token' => 'a', 'refresh_token' => 'r', 'status' => 'connected']);
        InboundMessage::create(['connected_account_id' => $acct->id, 'provider_message_id' => 'm', 'subject' => 's', 'dedupe_key' => 'k', 'classification' => 'uncertain']);
        Interview::create(['user_id' => $this->owner->id, 'application_id' => $app->id, 'kind' => 'interview', 'title' => 't', 'source_timezone' => 'UTC']);
        NotificationLog::create(['type' => 't', 'event_key' => 'e', 'recipient' => 'x']);

        $this->asOwner()->delete(route('settings.delete-data'), ['confirm_text' => 'DELETE', 'password' => 'correct-horse-battery'])->assertRedirect(route('privacy.consent'));

        foreach ([CandidateProfile::class, CandidateProfileVersion::class, Resume::class, JobMatch::class, Application::class, ApplicationEvent::class,
            Interview::class, InboundMessage::class, ConnectedAccount::class, NotificationLog::class, AiUsageLog::class] as $model) {
            $this->assertSame(0, $model::count(), "{$model} should be empty");
        }
        Storage::disk('resumes')->assertMissing('u1/file.txt');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/revoke'));
        $this->assertSame(1, User::count(), 'the login itself stays');
        $this->assertSame(1, JobPosting::count(), 'public job data is kept unless asked');
        $this->assertTrue(AuditLog::where('action', 'privacy.data_deleted')->exists());

        // After a wipe the consent question is asked again (first-use gate); answer it, then delete the jobs too.
        $this->asOwner()->get(route('dashboard'))->assertRedirect(route('privacy.consent'));
        app(ProfileService::class)->setConsent(app(ProfileService::class)->forUser($this->owner->refresh()), false);
        $this->asOwner()->delete(route('settings.delete-data'), ['confirm_text' => 'DELETE', 'password' => 'correct-horse-battery', 'include_jobs' => 1]);
        $this->assertSame(0, JobPosting::count());
    }

    // ---- pipeline UX ------------------------------------------------------------------------------------------

    public function test_applications_tracker_filters_by_status_and_search(): void
    {
        $this->profile($this->owner);
        $a = app(DraftService::class)->create($this->owner, $this->job(null, ['title' => 'Alpha Laravel Dev', 'externalId' => 'a']));
        $b = app(DraftService::class)->create($this->owner, $this->job(null, ['title' => 'Beta PHP Dev', 'externalId' => 'b']));
        app(ApplicationService::class)->reject($b);

        $this->asOwner()->get(route('applications.index'))->assertSee('Alpha Laravel Dev')->assertSee('Beta PHP Dev');
        $this->asOwner()->get(route('applications.index', ['status' => 'closed']))->assertSee('Beta PHP Dev')->assertDontSee('Alpha Laravel Dev');
        $this->asOwner()->get(route('applications.index', ['q' => 'alpha']))->assertSee('Alpha Laravel Dev')->assertDontSee('Beta PHP Dev');
    }

    public function test_one_owner_cannot_open_anothers_application(): void
    {
        $this->profile($this->owner);
        $app = app(DraftService::class)->create($this->owner, $this->job());
        $other = User::create(['name' => 'O', 'email' => 'o@example.test', 'password' => 'another-long-password', 'timezone' => 'UTC']);
        app(ProfileService::class)->setConsent(app(ProfileService::class)->forUser($other), false);

        $this->actingAs($other)->get(route('applications.show', $app))->assertForbidden();
        $this->actingAs($other)->post(route('applications.approve', $app), ['reviewed' => 1, 'truthful' => 1])->assertForbidden();
        $this->actingAs($other)->get(route('applications.export', [$app, 'resume', 'format' => 'txt']))->assertForbidden();
    }
}
