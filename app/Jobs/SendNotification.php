<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\Notifications\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // retries are handled by NotificationService::retryFailed (bounded, visible in the UI)

    public function __construct(public int $notificationId) {}

    public function handle(NotificationService $notifications): void
    {
        $log = NotificationLog::find($this->notificationId);
        if ($log && $log->status !== 'sent') {
            $notifications->deliver($log);
        }
    }
}
