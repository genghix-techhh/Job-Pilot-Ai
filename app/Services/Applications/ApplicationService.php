<?php

namespace App\Services\Applications;

use App\Enums\ApplicationStatus;
use App\Enums\EligibilityStatus;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\JobMatch;
use App\Models\User;
use App\Services\Applications\Submission\SubmissionManager;
use App\Services\AuditLogger;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Enforces the approval-first workflow. Nothing in the UI is trusted: every rule below is
 * checked here, server-side.
 *
 *  1. Discovery only creates a job + score.
 *  2. A draft exists only after the owner clicks "Create draft".
 *  3. Approval records who, when, the exact job URL and SHA-256 hashes of the documents.
 *  4. Editing an approved draft revokes the approval.
 *  5. Submission requires a CURRENT approval AND either an authorised gateway returning a
 *     verified confirmation, or the owner's explicit "I submitted this" confirmation.
 *  6. Opening the employer's page never changes the status to submitted.
 *  7. Submission is guarded by a status lock + idempotency key, so it cannot run twice.
 */
class ApplicationService
{
    public function __construct(
        private readonly SubmissionManager $submissions,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    // ---- events ----------------------------------------------------------------------------

    /** @param array<string, mixed> $meta safe metadata only (no CV text, no secrets) */
    public function record(Application $app, string $type, string $actor = 'owner', array $meta = [], ?ApplicationStatus $from = null, ?ApplicationStatus $to = null): ApplicationEvent
    {
        $event = ApplicationEvent::create([
            'application_id' => $app->id,
            'type' => $type,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'actor' => $actor,
            'occurred_at' => now(),
            'metadata' => $meta ?: null,
        ]);
        $app->forceFill(['last_event_at' => $event->occurred_at])->save();

        return $event;
    }

    /**
     * The only place `status` changes. Pre-conditions that protect the "submitted" state live here.
     *
     * @param  array<string, mixed>  $meta
     */
    private function transition(Application $app, ApplicationStatus $to, string $actor, array $meta = [], bool $verifiedSubmission = false, string $type = 'status_changed'): void
    {
        $from = $app->status;
        if ($from === $to) {
            return;
        }
        if (! $from->canTransitionTo($to)) {
            throw new WorkflowException("An application cannot move from \"{$from->label()}\" to \"{$to->label()}\".");
        }
        if ($to === ApplicationStatus::Submitted && ! $verifiedSubmission) {
            throw new WorkflowException('An application can only be marked submitted after a verified confirmation.');
        }
        if ($to->isPostSubmission() && $to !== ApplicationStatus::Submitted && $app->submitted_at === null) {
            throw new WorkflowException('This application has not been submitted yet, so it cannot be marked "'.$to->label().'".');
        }
        if ($from === ApplicationStatus::Closed && $to === ApplicationStatus::DraftReady && $app->submitted_at !== null) {
            throw new WorkflowException('A submitted application cannot be reopened as a draft.');
        }

        $app->status = $to;
        $app->save();
        $this->record($app, $type, $actor, $meta, $from, $to);
    }

    // ---- drafting --------------------------------------------------------------------------

    /**
     * @param  array{cover_letter?: ?string, tailored_resume?: ?string, screening_answers?: array<int, array<string, mixed>>, notes?: ?string}  $data
     */
    public function saveDraft(Application $app, array $data, ?User $user = null): Application
    {
        return DB::transaction(function () use ($app, $data) {
            $app = Application::query()->lockForUpdate()->findOrFail($app->id);
            if (! $app->status->isEditable()) {
                throw new WorkflowException('This application can no longer be edited ('.$app->status->label().').');
            }

            $before = $app->documentHashes();

            $app->cover_letter = $data['cover_letter'] ?? $app->cover_letter;
            $app->tailored_resume = $data['tailored_resume'] ?? $app->tailored_resume;
            if (array_key_exists('screening_answers', $data)) {
                $app->screening_answers = $this->cleanAnswers($data['screening_answers']);
            }
            if (array_key_exists('notes', $data)) {
                $app->notes = $data['notes'];
            }

            $changed = $before !== $app->documentHashes();
            if ($changed) {
                $app->revision++;
                // "edited" protects the owner's own wording from being overwritten by a late AI draft;
                // adding/answering a screening question is not an edit of the letter or CV text.
                $after = $app->documentHashes();
                if ($before['cover_letter'] !== $after['cover_letter'] || $before['tailored_resume'] !== $after['tailored_resume']) {
                    $app->artifacts = array_merge((array) $app->artifacts, ['edited' => true]);
                }
            }
            $app->save();

            if ($changed) {
                $this->record($app, 'draft_edited', 'owner', ['revision' => $app->revision]);
                // Only a real approval is revoked; a draft merely awaiting approval stays where it is.
                if ($app->status === ApplicationStatus::Approved) {
                    $this->revokeApproval($app, 'Documents were edited after approval.');
                }
            }

            return $app;
        });
    }

    /** @param array<int, array<string, mixed>> $answers @return list<array<string, mixed>> */
    public function cleanAnswers(array $answers): array
    {
        return collect($answers)->map(fn ($a) => [
            'question' => Str::limit(trim((string) ($a['question'] ?? '')), 500, ''),
            'answer' => Str::limit(trim((string) ($a['answer'] ?? '')), 3000, ''),
            'needs_confirmation' => filter_var($a['needs_confirmation'] ?? false, FILTER_VALIDATE_BOOL),
            'evidence' => array_values((array) ($a['evidence'] ?? [])),
        ])->filter(fn ($a) => $a['question'] !== '')->values()->all();
    }

    public function requestApproval(Application $app): void
    {
        DB::transaction(function () use ($app) {
            $app = Application::query()->lockForUpdate()->findOrFail($app->id);
            if ($app->status !== ApplicationStatus::DraftReady) {
                throw new WorkflowException('Only a draft that is ready can be sent for approval.');
            }
            $this->transition($app, ApplicationStatus::AwaitingApproval, 'owner');
            $this->notifications->draftAwaiting($app->loadMissing('job'));
        });
    }

    /** Reject the draft: nothing is sent, the draft is closed (and can be reopened). */
    public function reject(Application $app, ?string $reason = null): void
    {
        DB::transaction(function () use ($app, $reason) {
            $app = Application::query()->lockForUpdate()->findOrFail($app->id);
            if ($app->submitted_at !== null) {
                throw new WorkflowException('A submitted application cannot be rejected as a draft.');
            }
            $this->clearApproval($app);
            $this->transition($app, ApplicationStatus::Closed, 'owner', ['reason' => Str::limit((string) $reason, 200, ''), 'draft_rejected' => true]);
            $this->audit->record('application.draft_rejected', $app);
        });
    }

    // ---- approval --------------------------------------------------------------------------

    /**
     * @param  array{reviewed?: mixed, truthful?: mixed, eligibility_ack?: mixed}  $confirmations
     */
    public function approve(Application $app, User $user, array $confirmations): Application
    {
        return DB::transaction(function () use ($app, $user, $confirmations) {
            $app = Application::query()->lockForUpdate()->with('job')->findOrFail($app->id);

            if (! in_array($app->status, [ApplicationStatus::DraftReady, ApplicationStatus::AwaitingApproval], true)) {
                throw new WorkflowException('Only a draft can be approved (current status: '.$app->status->label().').');
            }
            if (blank($app->cover_letter) && blank($app->tailored_resume)) {
                throw new WorkflowException('The draft is empty — there is nothing to approve.');
            }
            if (! filter_var($confirmations['reviewed'] ?? false, FILTER_VALIDATE_BOOL)) {
                throw new WorkflowException('Please confirm that you reviewed the documents and answers.');
            }
            if (! filter_var($confirmations['truthful'] ?? false, FILTER_VALIDATE_BOOL)) {
                throw new WorkflowException('Please confirm that everything in the documents is accurate and yours to claim.');
            }
            $unanswered = collect($app->screening_answers ?? [])->filter(fn ($a) => blank($a['answer'] ?? null))->count();
            if ($unanswered > 0) {
                throw new WorkflowException("{$unanswered} screening question(s) still have no answer. Answer them yourself (the app never guesses eligibility or personal claims) or remove them.");
            }
            $match = JobMatch::query()->where('job_id', $app->job_id)->where('user_id', $app->user_id)->latest('id')->first();
            if ($match?->eligibility_status === EligibilityStatus::Restricted && ! filter_var($confirmations['eligibility_ack'] ?? false, FILTER_VALIDATE_BOOL)) {
                throw new WorkflowException('This job looks restricted for your location ('.$match->eligibility_note.'). Tick the acknowledgement if you still want to proceed.');
            }

            if ($app->status === ApplicationStatus::DraftReady) {
                $this->transition($app, ApplicationStatus::AwaitingApproval, 'owner');
            }

            $hashes = $app->documentHashes();
            $app->forceFill([
                'approved_at' => now(),
                'approved_by' => $user->id,
                'approved_url' => $app->job->url,
                'approved_hashes' => $hashes,
            ]);
            $this->transition($app, ApplicationStatus::Approved, 'owner', ['url' => $app->job->url, 'hashes' => $hashes, 'revision' => $app->revision], type: 'approved');
            $this->audit->record('application.approved', $app, ['url' => $app->job->url, 'hashes' => $hashes, 'revision' => $app->revision]);

            return $app;
        });
    }

    public function revokeApproval(Application $app, string $reason): void
    {
        $this->clearApproval($app);
        if (in_array($app->status, [ApplicationStatus::Approved, ApplicationStatus::AwaitingApproval, ApplicationStatus::SubmissionFailed], true)) {
            $this->transition($app, ApplicationStatus::DraftReady, 'system', ['reason' => $reason], type: 'approval_revoked');
        }
        $this->audit->record('application.approval_revoked', $app, ['reason' => $reason]);
    }

    private function clearApproval(Application $app): void
    {
        $app->forceFill(['approved_at' => null, 'approved_by' => null, 'approved_url' => null, 'approved_hashes' => null])->save();
    }

    // ---- submission ------------------------------------------------------------------------

    /** Guard shared by every path that leads to "submitted". */
    private function assertSubmittable(Application $app): void
    {
        if ($app->status !== ApplicationStatus::Approved) {
            throw new WorkflowException('Only an approved application can be submitted (current status: '.$app->status->label().').');
        }
        if (! $app->approvalIsCurrent()) {
            throw new WorkflowException('The approval no longer matches the documents or job link. Review and approve again.');
        }
    }

    /** URL of the employer's page, available only after approval. Never changes the status. */
    public function openOfficialPage(Application $app): string
    {
        $app->loadMissing('job');
        $this->assertSubmittable($app);
        $url = (string) $app->approved_url;
        if (! preg_match('~^https?://~i', $url)) {
            throw new WorkflowException('The job has no valid application link.');
        }
        $this->record($app, 'official_page_opened', 'owner', ['url' => $url]);

        return $url;
    }

    /**
     * Submit through an authorised gateway. Without one this always fails with a clear message:
     * the owner then applies on the employer's site and confirms manually.
     */
    public function submitViaGateway(Application $app): Application
    {
        $app->loadMissing('job');
        $gateway = $this->submissions->gatewayFor($app);
        if (! $gateway) {
            throw new WorkflowException('No authorised submission integration exists for this job. Open the official application page, apply there, then confirm with "I submitted this".');
        }

        // Status lock = mutex: only the first caller can move Approved -> SubmissionInProgress.
        $locked = DB::transaction(function () use ($app, $gateway) {
            $fresh = Application::query()->lockForUpdate()->with('job')->findOrFail($app->id);
            if ($fresh->submitted_at !== null || $fresh->status === ApplicationStatus::SubmissionInProgress) {
                return null; // already done or running: idempotent no-op
            }
            $this->assertSubmittable($fresh);
            $this->transition($fresh, ApplicationStatus::SubmissionInProgress, 'system', ['gateway' => $gateway->name(), 'idempotency_key' => $fresh->idempotency_key]);

            return $fresh;
        });

        if ($locked === null) {
            return $app->refresh();
        }

        try {
            $result = $gateway->submit($locked);
        } catch (Throwable $e) {
            report_safely($e, 'submission');
            $this->transition($locked, ApplicationStatus::SubmissionFailed, 'system', ['error' => 'Gateway error ('.class_basename($e).')']);

            return $locked;
        }

        if ($result->verified) {
            $locked->forceFill(['submitted_at' => now(), 'submission_method' => 'api', 'external_reference' => $result->reference])->save();
            $this->transition($locked, ApplicationStatus::Submitted, 'system', ['gateway' => $gateway->name(), 'reference' => $result->reference], verifiedSubmission: true);
            $this->audit->record('application.submitted_api', $locked, ['gateway' => $gateway->name()]);
        } else {
            $this->transition($locked, ApplicationStatus::SubmissionFailed, 'system', ['error' => Str::limit((string) $result->error, 200, '')]);
        }

        return $locked->refresh();
    }

    /** Owner confirms they submitted on the employer's site. The only non-API route to "submitted". */
    public function markSubmittedManually(Application $app, User $user, ?string $reference = null, ?Carbon $at = null): Application
    {
        return DB::transaction(function () use ($app, $user, $reference, $at) {
            $app = Application::query()->lockForUpdate()->with('job')->findOrFail($app->id);

            if ($app->submitted_at !== null) {
                return $app; // idempotent: a double click cannot create a second submission
            }
            $this->assertSubmittable($app);

            $when = $at && $at->lte(now()) ? $at : now();
            $app->forceFill([
                'submitted_at' => $when,
                'submission_method' => 'manual',
                'external_reference' => $reference ? Str::limit($reference, 190, '') : null,
            ])->save();
            $this->transition($app, ApplicationStatus::Submitted, 'owner', ['method' => 'manual', 'confirmed_by' => $user->id, 'reference' => $reference ? Str::limit($reference, 60, '') : null], verifiedSubmission: true);
            $this->audit->record('application.submitted_manual', $app, ['url' => $app->approved_url]);

            return $app;
        });
    }

    /** Owner clears a submission that is stuck "in progress" (e.g. the process died). */
    public function markSubmissionFailed(Application $app, string $reason = 'Marked as failed by owner.'): void
    {
        $this->transition($app, ApplicationStatus::SubmissionFailed, 'owner', ['reason' => $reason]);
    }

    // ---- tracking --------------------------------------------------------------------------

    /** Statuses the owner may move an application to by hand from the tracker. */
    public const OWNER_STATUSES = [
        ApplicationStatus::ResponseReceived, ApplicationStatus::Assessment, ApplicationStatus::Interview,
        ApplicationStatus::Offer, ApplicationStatus::Rejected, ApplicationStatus::Withdrawn,
        ApplicationStatus::Closed, ApplicationStatus::DraftReady,
    ];

    public function updateStatus(Application $app, ApplicationStatus $to, string $actor = 'owner', ?string $note = null): Application
    {
        if (! in_array($to, self::OWNER_STATUSES, true)) {
            throw new WorkflowException('That status can only be reached through the approval and submission steps.');
        }
        DB::transaction(function () use ($app, $to, $actor, $note) {
            $app = Application::query()->lockForUpdate()->findOrFail($app->id);
            $this->transition($app, $to, $actor, array_filter(['note' => $note ? Str::limit($note, 200, '') : null]));
        });

        return $app->refresh();
    }

    /** Apply a status suggested by a matched e-mail (never skips the workflow rules). */
    public function applyDetectedStatus(Application $app, ApplicationStatus $to, array $meta): bool
    {
        if (! $app->status->canTransitionTo($to) || ! $app->submitted_at) {
            return false;
        }
        $this->transition($app, $to, 'gmail', $meta);

        return true;
    }

    /** @param array{next_task?: ?string, next_task_at?: mixed, notes?: ?string} $data */
    public function updateTracking(Application $app, array $data): void
    {
        $app->forceFill([
            'next_task' => isset($data['next_task']) ? Str::limit(trim((string) $data['next_task']), 190, '') ?: null : $app->next_task,
            'next_task_at' => array_key_exists('next_task_at', $data) ? $data['next_task_at'] : $app->next_task_at,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $app->notes,
        ])->save();
        $this->record($app, 'tracking_updated', 'owner');
    }
}
