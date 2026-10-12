<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus as S;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\CandidateProfile;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\Applications\ApplicationService;
use App\Services\Applications\DraftService;
use App\Services\Applications\Submission\SubmissionGateway;
use App\Services\Applications\Submission\SubmissionManager;
use App\Services\Applications\Submission\SubmissionResult;
use App\Services\Applications\WorkflowException;
use App\Services\Matching\MatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\JobPilotHelpers;
use Tests\TestCase;

class ApplicationWorkflowTest extends TestCase
{
    use JobPilotHelpers, RefreshDatabase;

    private User $owner;

    private JobPosting $job;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jobpilot.gemini.api_key' => null]);
        $this->owner = $this->owner();
        $this->profile($this->owner);
        $this->job = $this->job();
    }

    private function draft(): Application
    {
        return app(DraftService::class)->create($this->owner, $this->job);
    }

    private function approved(): Application
    {
        $app = $this->draft();

        return app(ApplicationService::class)->approve($app, $this->owner, ['reviewed' => 1, 'truthful' => 1]);
    }

    // ---- drafting ---------------------------------------------------------------------------

    public function test_discovery_and_scoring_never_create_an_application(): void
    {
        app(MatchService::class)->scoreMany([$this->job]);
        $this->assertSame(0, Application::count());
    }

    public function test_draft_requires_a_reviewed_profile(): void
    {
        CandidateProfile::query()->update(['profile_version' => 0]);
        $this->expectException(WorkflowException::class);
        $this->draft();
    }

    public function test_creating_a_draft_is_idempotent_and_starts_unsubmitted(): void
    {
        $a = $this->draft();
        $b = $this->draft();

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Application::count());
        $this->assertSame(S::DraftReady, $a->status);
        $this->assertNull($a->submitted_at);
        $this->assertNull($a->approved_at);
    }

    public function test_draft_text_comes_only_from_the_profile_and_flags_missing_skills(): void
    {
        $this->job->update(['description' => 'Build APIs with PHP, Laravel and Kubernetes. Fully remote.', 'skills' => ['PHP', 'Laravel', 'Kubernetes']]);
        $app = app(DraftService::class)->create($this->owner, $this->job->refresh());

        $this->assertStringContainsString('Dear Hiring Team', $app->cover_letter);
        $this->assertStringContainsString('Built REST APIs in Laravel and MySQL serving 50k users', $app->tailored_resume, 'achievements are quoted verbatim from the profile');
        $this->assertStringNotContainsStringIgnoringCase('kubernetes', $app->cover_letter, 'must not claim a skill the profile lacks');
        $this->assertStringNotContainsStringIgnoringCase('kubernetes', $app->tailored_resume);
        $this->assertStringContainsString('Kubernetes', implode(' ', $app->artifacts['warnings']));
        $this->assertStringContainsString('Ayesha Khan', $app->tailored_resume);   // identity is inserted locally, from the profile
    }

    public function test_consequential_screening_questions_are_left_blank(): void
    {
        $app = $this->draft();
        foreach (['Are you legally authorized to work in the US?', 'What are your salary expectations?', 'Do you require visa sponsorship?'] as $q) {
            $app = app(DraftService::class)->addQuestion($app->refresh(), $q);
        }
        foreach ($app->screening_answers as $row) {
            $this->assertSame('', $row['answer']);
            $this->assertTrue($row['needs_confirmation']);
        }
        $this->assertTrue(DraftService::isConsequential('What is your notice period?'));
        $this->assertFalse(DraftService::isConsequential('Describe a project you are proud of.'));
    }

    // ---- approval ---------------------------------------------------------------------------

    public function test_approval_records_who_when_hashes_and_exact_url_but_does_not_submit(): void
    {
        $app = $this->approved();

        $this->assertSame(S::Approved, $app->status);
        $this->assertSame($this->owner->id, $app->approved_by);
        $this->assertNotNull($app->approved_at);
        $this->assertSame($this->job->url, $app->approved_url);
        $this->assertSame($app->documentHashes(), $app->approved_hashes);
        $this->assertNull($app->submitted_at);
        $this->assertTrue($app->refresh()->load('job')->approvalIsCurrent());
        $this->assertTrue(AuditLog::where('action', 'application.approved')->exists());
        $this->assertTrue(ApplicationEvent::where('type', 'approved')->exists());
    }

    public function test_approval_needs_both_confirmations(): void
    {
        $app = $this->draft();
        foreach ([[], ['reviewed' => 1], ['truthful' => 1]] as $conf) {
            try {
                app(ApplicationService::class)->approve($app->refresh(), $this->owner, $conf);
                $this->fail('should refuse');
            } catch (WorkflowException) {
                $this->assertSame(S::DraftReady, $app->refresh()->status);
            }
        }
    }

    public function test_approval_is_blocked_while_a_question_is_unanswered(): void
    {
        $app = app(DraftService::class)->addQuestion($this->draft(), 'Why do you want this job?');
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('no answer');
        app(ApplicationService::class)->approve($app, $this->owner, ['reviewed' => 1, 'truthful' => 1]);
    }

    public function test_restricted_location_needs_an_explicit_acknowledgement(): void
    {
        settings()->set('home_country', 'Pakistan');
        $job = $this->job(null, ['externalId' => 'us-only', 'location' => 'Remote - US', 'remoteScope' => 'country', 'eligibleCountries' => ['United States']]);
        app(MatchService::class)->scoreMany([$job], notify: false);
        $app = app(DraftService::class)->create($this->owner, $job);

        try {
            app(ApplicationService::class)->approve($app, $this->owner, ['reviewed' => 1, 'truthful' => 1]);
            $this->fail('should require acknowledgement');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('restricted', $e->getMessage());
        }
        $approved = app(ApplicationService::class)->approve($app->refresh(), $this->owner, ['reviewed' => 1, 'truthful' => 1, 'eligibility_ack' => 1]);
        $this->assertSame(S::Approved, $approved->status);
    }

    public function test_editing_an_approved_draft_revokes_the_approval(): void
    {
        $app = $this->approved();

        $app = app(ApplicationService::class)->saveDraft($app, ['cover_letter' => $app->cover_letter."\nP.S. one more thing."]);

        $this->assertSame(S::DraftReady, $app->refresh()->status);
        $this->assertNull($app->approved_at);
        $this->assertNull($app->approved_hashes);
    }

    // ---- submission guarantees ------------------------------------------------------------------

    public function test_an_unapproved_application_can_never_be_marked_submitted(): void
    {
        $app = $this->draft();
        $svc = app(ApplicationService::class);

        foreach ([fn () => $svc->markSubmittedManually($app, $this->owner, 'ref'), fn () => $svc->openOfficialPage($app), fn () => $svc->updateStatus($app, S::Interview)] as $try) {
            try {
                $try();
                $this->fail('must be refused');
            } catch (WorkflowException) {
                $this->assertTrue(true);
            }
        }
        $app->refresh();
        $this->assertSame(S::DraftReady, $app->status);
        $this->assertNull($app->submitted_at);
    }

    public function test_http_submitted_endpoint_refuses_without_approval(): void
    {
        $app = $this->draft();

        $this->actingAs($this->owner)->post(route('applications.submitted', $app), ['confirm' => 1])
            ->assertSessionHas('error');

        $this->assertNull($app->refresh()->submitted_at);
        $this->assertSame(S::DraftReady, $app->status);
    }

    public function test_opening_the_official_page_does_not_mark_anything_submitted(): void
    {
        $app = $this->approved();

        $this->actingAs($this->owner)->post(route('applications.open', $app))->assertRedirect($this->job->url);

        $app->refresh();
        $this->assertSame(S::Approved, $app->status);
        $this->assertNull($app->submitted_at);
        $this->assertTrue(ApplicationEvent::where('type', 'official_page_opened')->exists());
    }

    public function test_manual_submission_requires_the_explicit_confirmation_box(): void
    {
        $app = $this->approved();

        $this->actingAs($this->owner)->post(route('applications.submitted', $app), [])->assertSessionHasErrors('confirm');
        $this->assertNull($app->refresh()->submitted_at);

        $this->actingAs($this->owner)->post(route('applications.submitted', $app), ['confirm' => 1, 'reference' => 'ABC-1'])->assertSessionHas('status');
        $app->refresh();
        $this->assertSame(S::Submitted, $app->status);
        $this->assertSame('manual', $app->submission_method);
        $this->assertSame('ABC-1', $app->external_reference);
        $this->assertNotNull($app->submitted_at);
    }

    public function test_double_clicking_i_submitted_this_is_idempotent(): void
    {
        $app = $this->approved();
        $svc = app(ApplicationService::class);

        $svc->markSubmittedManually($app, $this->owner, 'r1');
        $first = $app->refresh()->submitted_at;
        $svc->markSubmittedManually($app->refresh(), $this->owner, 'r2');

        $app->refresh();
        $this->assertEquals($first, $app->submitted_at);
        $this->assertSame('r1', $app->external_reference);
        $this->assertSame(1, ApplicationEvent::where('to_status', 'submitted')->count());
    }

    public function test_tampering_with_documents_after_approval_invalidates_it(): void
    {
        $app = $this->approved();
        Application::whereKey($app->id)->update(['cover_letter' => 'Changed behind the app\'s back']);   // bypasses saveDraft

        try {
            app(ApplicationService::class)->markSubmittedManually($app->refresh(), $this->owner);
            $this->fail('approval must no longer match');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('approve again', $e->getMessage());
        }
        $this->assertNull($app->refresh()->submitted_at);
    }

    public function test_post_submission_statuses_are_unreachable_before_submission(): void
    {
        $app = $this->approved();
        foreach ([S::ResponseReceived, S::Interview, S::Offer, S::Rejected] as $to) {
            try {
                app(ApplicationService::class)->updateStatus($app->refresh(), $to);
                $this->fail("should not reach {$to->value}");
            } catch (WorkflowException) {
                $this->assertSame(S::Approved, $app->refresh()->status);
            }
        }
    }

    public function test_no_authorised_gateway_exists_by_default(): void
    {
        $app = $this->approved();
        $this->assertFalse(app(SubmissionManager::class)->hasAny());
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No authorised submission integration');
        app(ApplicationService::class)->submitViaGateway($app);
    }

    // ---- gateway safeguards ---------------------------------------------------------------------

    private function gateway(bool $ok, ?\Closure $onSubmit = null): SubmissionGateway
    {
        return new class($ok, $onSubmit) implements SubmissionGateway
        {
            public int $calls = 0;

            public function __construct(private bool $ok, private ?\Closure $onSubmit) {}

            public function name(): string
            {
                return 'Test ATS';
            }

            public function supports(Application $a): bool
            {
                return true;
            }

            public function submit(Application $a): SubmissionResult
            {
                $this->calls++;
                if ($this->onSubmit) {
                    ($this->onSubmit)($a);
                }

                return $this->ok ? SubmissionResult::confirmed('REF-'.$a->idempotency_key) : SubmissionResult::failed('rejected by server');
            }
        };
    }

    public function test_gateway_success_marks_submitted_only_on_a_verified_confirmation(): void
    {
        $gw = $this->gateway(true);
        app(SubmissionManager::class)->register($gw);
        $app = $this->approved();

        $out = app(ApplicationService::class)->submitViaGateway($app);

        $this->assertSame(S::Submitted, $out->status);
        $this->assertSame('api', $out->submission_method);
        $this->assertStringStartsWith('REF-', $out->external_reference);
        $this->assertSame(1, $gw->calls);
    }

    public function test_gateway_failure_never_marks_submitted(): void
    {
        app(SubmissionManager::class)->register($this->gateway(false));
        $app = $this->approved();

        $out = app(ApplicationService::class)->submitViaGateway($app);

        $this->assertSame(S::SubmissionFailed, $out->status);
        $this->assertNull($out->submitted_at);
    }

    public function test_gateway_exception_is_recorded_as_failure_not_success(): void
    {
        app(SubmissionManager::class)->register($this->gateway(true, fn () => throw new \RuntimeException('network down')));
        $app = $this->approved();

        $out = app(ApplicationService::class)->submitViaGateway($app);

        $this->assertSame(S::SubmissionFailed, $out->status);
        $this->assertNull($out->submitted_at);
    }

    public function test_submission_cannot_run_twice_idempotency(): void
    {
        $gw = $this->gateway(true);
        app(SubmissionManager::class)->register($gw);
        $app = $this->approved();
        $svc = app(ApplicationService::class);

        $svc->submitViaGateway($app);
        $svc->submitViaGateway($app->refresh());
        $svc->submitViaGateway($app->refresh());

        $this->assertSame(1, $gw->calls, 'the integration must be called exactly once');
        $this->assertSame(1, ApplicationEvent::where('to_status', 'submitted')->count());
    }

    public function test_submission_in_progress_blocks_a_concurrent_second_attempt(): void
    {
        $gw = $this->gateway(true);
        app(SubmissionManager::class)->register($gw);
        $app = $this->approved();
        Application::whereKey($app->id)->update(['status' => S::SubmissionInProgress->value]);

        app(ApplicationService::class)->submitViaGateway($app->refresh());

        $this->assertSame(0, $gw->calls);
    }

    // ---- ownership + misc -----------------------------------------------------------------------

    public function test_status_history_is_recorded_in_order(): void
    {
        $app = $this->approved();
        app(ApplicationService::class)->markSubmittedManually($app, $this->owner);
        app(ApplicationService::class)->updateStatus($app->refresh(), S::Interview);

        $trail = $app->events()->reorder('id')->pluck('to_status')->filter()->values()->all();
        $this->assertSame(['draft_ready', 'awaiting_approval', 'approved', 'submitted', 'interview'], $trail);
    }

    public function test_rejecting_a_draft_closes_it_and_can_reopen_when_never_submitted(): void
    {
        $app = $this->draft();
        app(ApplicationService::class)->reject($app, 'not a fit');
        $this->assertSame(S::Closed, $app->refresh()->status);

        $again = app(DraftService::class)->create($this->owner, $this->job);
        $this->assertSame($app->id, $again->id);
        $this->assertSame(S::DraftReady, $again->status);
    }

    public function test_application_pages_render_for_every_stage(): void
    {
        $app = $this->draft();
        $this->actingAs($this->owner)->get(route('applications.show', $app))->assertOk()->assertSee('NOT SUBMITTED')->assertSee('Approve (does not submit)');

        app(ApplicationService::class)->approve($app, $this->owner, ['reviewed' => 1, 'truthful' => 1]);
        $this->actingAs($this->owner)->get(route('applications.show', $app))->assertOk()->assertSee('Open official application page')->assertSee('I submitted this');

        app(ApplicationService::class)->markSubmittedManually($app->refresh(), $this->owner);
        $this->actingAs($this->owner)->get(route('applications.show', $app))->assertOk()->assertSee('confirmed by you');
        $this->actingAs($this->owner)->get(route('applications.index'))->assertOk()->assertSee($this->job->title);
        $this->actingAs($this->owner)->get(route('applications.index', ['view' => 'board']))->assertOk();
    }

    public function test_exports_download_as_text_html_and_print(): void
    {
        $app = $this->draft();
        $this->actingAs($this->owner)->get(route('applications.export', [$app, 'cover-letter', 'format' => 'txt']))
            ->assertOk()->assertHeader('Content-Disposition')->assertSee('Dear Hiring Team');
        $this->actingAs($this->owner)->get(route('applications.export', [$app, 'resume', 'format' => 'html']))->assertOk();
        $this->actingAs($this->owner)->get(route('applications.export', [$app, 'resume', 'format' => 'print']))->assertOk()->assertSee('window.print');
    }
}
