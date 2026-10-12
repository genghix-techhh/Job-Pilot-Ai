<?php

namespace App\Services\Notifications;

use App\Jobs\SendNotification;
use App\Mail\AlertMail;
use App\Models\Application;
use App\Models\Interview;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Ai\Redactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-mail alerts only (no SMS/WhatsApp). Each alert has a unique event key, so the same
 * event can never produce two e-mails, even if the scan or sync that triggered it runs again.
 */
class NotificationService
{
    public const MAX_ATTEMPTS = 3;

    // ---- domain helpers --------------------------------------------------------------------

    public function strongMatch(JobPosting $job, JobMatch $match): ?NotificationLog
    {
        if (! settings()->get('notify_strong_matches')) {
            return null;
        }

        return $this->queue('strong_match', "strong_match:job:{$job->id}", [
            'heading' => 'Strong match: '.$job->title,
            'lines' => array_filter([
                ($job->company ?: 'A company').' — '.$job->title,
                'Match score '.round($match->score).'/100 ('.$match->tier->label().'). '.$match->eligibility_status->label().'.',
                $match->rationale,
                'Nothing has been applied for. Review the job and create a draft if you want to proceed.',
            ]),
            'url' => route('jobs.show', $job),
            'label' => 'Review this job',
        ]);
    }

    public function draftAwaiting(Application $app): ?NotificationLog
    {
        if (! settings()->get('notify_drafts_awaiting')) {
            return null;
        }

        return $this->queue('draft_awaiting', "draft_awaiting:app:{$app->id}:rev:{$app->revision}", [
            'heading' => 'Draft awaiting your approval',
            'lines' => ['Your application draft for "'.$app->job->title.'" is ready for review. It will not be submitted until you approve it.'],
            'url' => route('applications.show', $app),
            'label' => 'Review draft',
        ]);
    }

    public function recruiterResponse(Application $app, string $classLabel, int $messageId): ?NotificationLog
    {
        if (! settings()->get('notify_responses')) {
            return null;
        }

        return $this->queue('recruiter_response', "recruiter_response:msg:{$messageId}", [
            'heading' => $classLabel.': '.$app->job->title,
            'lines' => ['A new message related to your application for "'.$app->job->title.'" ('.($app->job->company ?: 'company').') was detected in your inbox: '.$classLabel.'.', 'Open the app to see the details and confirm anything time-sensitive.'],
            'url' => route('applications.show', $app),
            'label' => 'Open application',
        ]);
    }

    public function interviewDetected(Interview $interview): ?NotificationLog
    {
        if (! settings()->get('notify_interviews')) {
            return null;
        }
        $when = $interview->starts_at ? jp_dt($interview->starts_at, 'D, M j · g:i A T', $interview->user?->tz()) : 'time not stated';

        return $this->queue('interview', "interview:{$interview->id}:{$interview->state}", [
            'heading' => ucfirst($interview->kind).' '.($interview->isConfirmed() ? 'confirmed' : 'proposed (tentative)').': '.$interview->title,
            'lines' => ['When: '.$when, $interview->isConfirmed() ? '' : 'This time is tentative until you confirm it in JobPilot.'],
            'url' => route('interviews.index'),
            'label' => 'Open interviews',
        ]);
    }

    public function deadlineApproaching(Interview $interview): ?NotificationLog
    {
        if (! settings()->get('notify_deadlines')) {
            return null;
        }
        $when = $interview->when();

        return $this->queue('deadline', "deadline:{$interview->id}:".($when?->format('YmdH')), [
            'heading' => 'Coming up: '.$interview->title,
            'lines' => ['Scheduled for '.jp_dt($when, 'D, M j · g:i A T', $interview->user?->tz()).'.'],
            'url' => route('interviews.index'),
            'label' => 'Open interviews',
        ]);
    }

    public function sourceFailing(JobSource $source): ?NotificationLog
    {
        if (! settings()->get('notify_source_failures')) {
            return null;
        }

        return $this->queue('source_failure', "source_failure:{$source->id}:".now()->format('Ymd'), [
            'heading' => 'Job source failing: '.$source->name,
            'lines' => [
                $source->name.' has failed '.$source->consecutive_failures.' times in a row.',
                'Last error: '.Redactor::scrubSecrets($source->last_error),
            ],
            'url' => route('sources.index'),
            'label' => 'Check sources',
        ]);
    }

    /**
     * @param  list<array{title: string, meta?: string, url?: string}>  $items
     * @param  list<string>  $lines
     */
    public function digest(string $eventKey, string $heading, array $lines, array $items, bool $sendNow = false): ?NotificationLog
    {
        return $this->queue('digest', $eventKey, [
            'heading' => $heading, 'lines' => $lines, 'items' => $items,
            'url' => route('jobs.index'), 'label' => 'Open your job feed',
        ], sendNow: $sendNow);
    }

    public function followUpDue(Application $app, int $daysWaiting, int $bucket): ?NotificationLog
    {
        if (! settings()->get('notify_followups')) {
            return null;
        }

        return $this->queue('followup', "followup:app:{$app->id}:{$bucket}", [
            'heading' => 'Time to follow up: '.$app->job->title,
            'lines' => [
                'You submitted your application to '.($app->job->company ?: 'this company').' '.$daysWaiting.' days ago and have not heard back.',
                'A short, polite follow-up message is ready for you to copy — JobPilot never sends anything on your behalf.',
            ],
            'url' => route('applications.show', $app), 'label' => 'Open the follow-up message',
        ]);
    }

    public function test(User $user): NotificationLog
    {
        $log = $this->queue('test', 'test:'.now()->format('YmdHis').':'.bin2hex(random_bytes(3)), [
            'heading' => 'Test e-mail',
            'lines' => ['If you can read this, your SMTP settings work and JobPilot can reach you.'],
            'url' => route('dashboard'),
            'label' => 'Open JobPilot',
        ], sendNow: true, force: true);

        return $log;
    }

    // ---- core -----------------------------------------------------------------------------

    /**
     * @param  array{heading: string, lines: array<int, string>, url?: ?string, label?: ?string}  $payload
     */
    public function queue(string $type, string $eventKey, array $payload, bool $sendNow = false, bool $force = false): ?NotificationLog
    {
        $user = User::query()->first();
        if (! $user) {
            return null;
        }

        $payload['lines'] = array_values(array_filter(array_map(fn ($l) => trim((string) $l), $payload['lines'])));

        try {
            $log = NotificationLog::create([
                'channel' => 'mail',
                'type' => $type,
                'event_key' => $eventKey,
                'recipient' => $user->alertEmail(),
                'payload' => $payload,
                'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // this event already produced (or queued) an e-mail
        }

        if ($sendNow || config('queue.default') === 'sync') {
            $this->deliver($log);
        } else {
            SendNotification::dispatch($log->id);
        }

        return $log->fresh();
    }

    /** Attempt delivery. Never throws: failures are recorded on the row for the UI. */
    public function deliver(NotificationLog $log): bool
    {
        if ($log->status === 'sent') {
            return true;
        }

        $log->increment('attempts');
        $p = $log->payload ?? [];

        try {
            Mail::to($log->recipient)->send(new AlertMail(
                (string) ($p['heading'] ?? 'JobPilot alert'),
                (array) ($p['lines'] ?? []),
                $p['url'] ?? null,
                $p['label'] ?? null,
                (array) ($p['items'] ?? []),
            ));
            $log->forceFill(['status' => 'sent', 'sent_at' => now(), 'last_error' => null])->save();

            return true;
        } catch (Throwable $e) {
            report_safely($e, 'mail');
            $log->forceFill([
                'status' => 'failed',
                'last_error' => Redactor::scrubSecrets(class_basename($e).': '.$e->getMessage()),
            ])->save();

            return false;
        }
    }

    /** Re-queue failed alerts that still have attempts left. @return int how many were retried */
    public function retryFailed(): int
    {
        $count = 0;
        NotificationLog::query()
            ->where('status', 'failed')->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->limit(25)->get()
            ->each(function (NotificationLog $log) use (&$count) {
                $this->deliver($log);
                $count++;
            });

        return $count;
    }

    /** Human-readable description of the mail transport for the diagnostics page. */
    public function transportStatus(): array
    {
        $mailer = (string) config('mail.default');
        $cfg = (array) config("mail.mailers.{$mailer}", []);

        return match (true) {
            in_array($mailer, ['log', 'array'], true) => ['ok' => false, 'label' => "Mailer \"{$mailer}\" — messages are NOT delivered", 'detail' => 'Set MAIL_MAILER=smtp and the MAIL_* values in your .env to receive alerts.'],
            $mailer === 'smtp' && empty($cfg['host']) => ['ok' => false, 'label' => 'SMTP host is missing', 'detail' => 'Set MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD.'],
            default => ['ok' => true, 'label' => "Mailer \"{$mailer}\" configured".(($cfg['host'] ?? null) ? ' ('.$cfg['host'].')' : ''), 'detail' => 'Use "Send test e-mail" to verify delivery.'],
        };
    }
}
