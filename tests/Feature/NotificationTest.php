<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Mail\AlertMail;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Jobs\JobSyncService;
use App\Services\Matching\MatchService;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\JobPilotHelpers;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use JobPilotHelpers, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jobpilot.gemini.api_key' => null]);
        $this->owner = $this->owner();
        $this->profile($this->owner);
        $this->fakeDns();
    }

    private function strongMatch()
    {
        $job = $this->job();
        settings()->setMany(['threshold_strong' => 20, 'threshold_potential' => 10]);
        $match = app(MatchService::class)->scoreJob($job);

        return [$job, $match];
    }

    public function test_the_same_event_never_produces_two_emails(): void
    {
        Mail::fake();
        [$job, $match] = $this->strongMatch();
        $n = app(NotificationService::class);

        $first = $n->strongMatch($job, $match);
        $second = $n->strongMatch($job, $match);

        $this->assertNotNull($first);
        $this->assertNull($second);
        Mail::assertSent(AlertMail::class, 1);
        $this->assertSame(1, NotificationLog::count());
        $this->assertSame('sent', NotificationLog::first()->status);
    }

    public function test_rescoring_does_not_re_alert_for_the_same_job(): void
    {
        Mail::fake();
        [$job] = $this->strongMatch();
        app(MatchService::class)->scoreMany([$job]);
        app(MatchService::class)->scoreMany([$job]);
        app(MatchService::class)->scoreMany([$job]);
        Mail::assertSent(AlertMail::class, 1);
    }

    public function test_old_jobs_do_not_trigger_alerts_when_the_profile_is_first_saved(): void
    {
        Mail::fake();
        $job = $this->job();
        $job->update(['first_seen_at' => now()->subDays(20)]);
        settings()->setMany(['threshold_strong' => 20, 'threshold_potential' => 10]);

        app(MatchService::class)->scoreMany([$job->refresh()]);

        Mail::assertNothingSent();
    }

    public function test_disabled_notification_types_create_nothing(): void
    {
        Mail::fake();
        settings()->set('notify_strong_matches', false);
        [$job, $match] = $this->strongMatch();
        $this->assertNull(app(NotificationService::class)->strongMatch($job, $match));
        $this->assertSame(0, NotificationLog::count());
    }

    public function test_delivery_failure_is_recorded_safely_and_retried_a_bounded_number_of_times(): void
    {
        // A real SMTP transport pointed at a closed port fails the way a mis-configured host would.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.username' => 'u', 'mail.mailers.smtp.password' => 'SMTP-SECRET-PASSWORD']);
        [$job, $match] = $this->strongMatch();
        $n = app(NotificationService::class);

        $log = $n->strongMatch($job, $match);

        $this->assertSame('failed', $log->status);
        $this->assertSame(1, $log->attempts);
        $this->assertNotEmpty($log->last_error);
        $this->assertStringNotContainsString('SMTP-SECRET-PASSWORD', (string) $log->last_error);

        $this->assertSame(0, $n->retryFailed(), 'not retried immediately');
        $this->travel(6)->minutes();
        $this->assertSame(1, $n->retryFailed());
        $this->assertSame(2, $log->refresh()->attempts);
        $this->travel(6)->minutes();
        $n->retryFailed();
        $this->assertSame(3, $log->refresh()->attempts);
        $this->travel(6)->minutes();
        $this->assertSame(0, $n->retryFailed(), 'gives up after MAX_ATTEMPTS');
        $this->assertSame('failed', $log->refresh()->status);

        // And a later recovery sends it.
        Mail::fake();
        config(['mail.default' => 'array']);
    }

    public function test_a_failed_alert_can_succeed_on_retry_once_mail_works(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
        [$job, $match] = $this->strongMatch();
        $log = app(NotificationService::class)->strongMatch($job, $match);
        $this->assertSame('failed', $log->status);

        config(['mail.default' => 'array']);
        app('mail.manager')->forgetMailers();
        $this->travel(6)->minutes();
        app(NotificationService::class)->retryFailed();

        $log->refresh();
        $this->assertSame('sent', $log->status);
        $this->assertNotNull($log->sent_at);
        $this->assertNull($log->last_error);
    }

    public function test_send_test_email_reports_success_in_the_ui(): void
    {
        Mail::fake();
        $this->actingAs($this->owner)->post(route('settings.test-email'))->assertSessionHas('status');
        Mail::assertSent(AlertMail::class, fn ($m) => $m->hasTo('owner@example.test') && str_contains($m->heading, 'Test'));
    }

    public function test_send_test_email_reports_failure_in_the_ui_and_diagnostics(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
        $this->actingAs($this->owner)->post(route('settings.test-email'))->assertSessionHas('error');
        $this->actingAs($this->owner)->get(route('activity.index'))->assertOk()->assertSee('failed');
    }

    public function test_alerts_go_to_the_notification_address_when_set(): void
    {
        Mail::fake();
        $this->owner->update(['notification_email' => 'alerts@example.test']);
        app(NotificationService::class)->test($this->owner->refresh());
        Mail::assertSent(AlertMail::class, fn ($m) => $m->hasTo('alerts@example.test'));
    }

    public function test_repeated_source_failures_alert_once_per_day(): void
    {
        Mail::fake();
        $source = $this->source(SourceType::Greenhouse, ['board_token' => 'broken'], 'Broken');
        Http::fake(['*' => Http::response('boom', 500)]);

        for ($i = 0; $i < 5; $i++) {
            app(JobSyncService::class)->sync($source->refresh());
        }

        $this->assertSame(5, $source->refresh()->consecutive_failures);
        Mail::assertSent(AlertMail::class, 1);
        $this->assertSame(1, NotificationLog::where('type', 'source_failure')->count());
    }

    public function test_the_alert_email_escapes_hostile_text(): void
    {
        Mail::fake();
        app(NotificationService::class)->queue('test', 'xss-1', ['heading' => '<script>alert(1)</script>', 'lines' => ['<img src=x onerror=alert(1)>'], 'url' => route('dashboard'), 'label' => 'Open']);
        Mail::assertSent(AlertMail::class, function (AlertMail $m) {
            $html = $m->render();

            return ! str_contains($html, '<script>alert(1)</script>') && ! str_contains($html, '<img src=x');
        });
    }
}
