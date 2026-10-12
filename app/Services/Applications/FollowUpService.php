<?php

namespace App\Services\Applications;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Collection;

/**
 * Gentle nudges for applications that went quiet. It only SUGGESTS: a follow-up message you can copy,
 * a task you can plan, a reminder e-mail. It never sends anything to an employer.
 */
class FollowUpService
{
    public function __construct(
        private readonly ApplicationService $applications,
        private readonly NotificationService $notifications,
    ) {}

    public function days(): int
    {
        return max(0, (int) settings()->get('followup_days'));
    }

    /** @return Collection<int, Application> submitted, unanswered, not already planned/dismissed */
    public function due(User $user): Collection
    {
        if ($this->days() === 0) {
            return collect();
        }

        return Application::query()->with('job')->where('user_id', $user->id)
            ->where('status', ApplicationStatus::Submitted->value)
            ->where('submitted_at', '<=', now()->subDays($this->days()))
            ->whereNull('next_task')
            ->orderBy('submitted_at')->get()
            ->filter(fn (Application $a) => empty($a->artifacts['followup_dismissed_at']))
            ->values();
    }

    public function isDue(Application $a): bool
    {
        return $this->days() > 0 && $a->status === ApplicationStatus::Submitted && $a->submitted_at
            && $a->submitted_at->lte(now()->subDays($this->days())) && empty($a->artifacts['followup_dismissed_at']);
    }

    public function daysWaiting(Application $a): int
    {
        return (int) floor($a->submitted_at?->diffInDays(now(), true) ?? 0);
    }

    /** A short, neutral, truthful follow-up. The owner copies it; the app never sends it. */
    public function message(Application $a): string
    {
        $a->loadMissing('job');
        $name = (string) ($a->user?->profile?->structured['name'] ?? '');
        $company = $a->job->company ?: 'hiring';
        $when = $a->submitted_at?->copy()->setTimezone($a->user?->tz() ?? 'UTC')->format('F j');

        return "Subject: Following up on my application — {$a->job->title}\n\n"
            ."Hello {$company} team,\n\n"
            ."I applied for the {$a->job->title} role".($when ? " on {$when}" : '').' and wanted to follow up to check that my application reached you and to say that I remain very interested. '
            ."I would be glad to provide anything else that would help.\n\n"
            ."Thank you for your time.\n\n"
            .'Kind regards,'.($name !== '' ? "\n{$name}" : '');
    }

    public function plan(Application $a): void
    {
        $a->loadMissing('job');
        $this->applications->updateTracking($a, [
            'next_task' => 'Send follow-up to '.($a->job->company ?: 'the employer'),
            'next_task_at' => now(),
        ]);
        $this->applications->record($a, 'followup_planned');
    }

    public function dismiss(Application $a): void
    {
        $a->artifacts = array_merge((array) $a->artifacts, ['followup_dismissed_at' => now()->toIso8601String()]);
        $a->save();
        $this->applications->record($a, 'followup_dismissed');
    }

    /** E-mail a nudge for each due application (at most 3 per application, spaced by the follow-up interval). */
    public function notifyDue(): int
    {
        $sent = 0;
        foreach (User::query()->get() as $user) {
            foreach ($this->due($user) as $a) {
                $waiting = $this->daysWaiting($a);
                $bucket = min(3, intdiv($waiting, max(1, $this->days())));
                if ($this->notifications->followUpDue($a, $waiting, $bucket)) {
                    $sent++;
                }
            }
        }

        return $sent;
    }
}
