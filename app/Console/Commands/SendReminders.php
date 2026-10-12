<?php

namespace App\Console\Commands;

use App\Services\Applications\FollowUpService;
use App\Services\Interviews\InterviewService;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

class SendReminders extends Command
{
    protected $signature = 'app:send-reminders';

    protected $description = 'Send due interview/assessment reminders and retry failed e-mail alerts (bounded attempts)';

    public function handle(InterviewService $interviews, NotificationService $notifications, FollowUpService $followUps): int
    {
        $reminders = $interviews->sendDueReminders();
        $nudges = $followUps->notifyDue();
        $retried = $notifications->retryFailed();
        $this->info("Reminders sent: {$reminders}. Follow-up nudges: {$nudges}. Failed alerts retried: {$retried}.");

        return self::SUCCESS;
    }
}
