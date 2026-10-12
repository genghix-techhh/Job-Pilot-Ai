<?php

namespace App\Jobs;

use App\Models\Application;
use App\Services\Ai\AiException;
use App\Services\Applications\DraftService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/** Triggered only by the owner clicking "Create draft" / "Regenerate". Never submits anything. */
class GenerateApplicationDraft implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 6;

    public int $timeout = 120;

    public function __construct(public int $applicationId) {}

    public function handle(DraftService $drafts): void
    {
        $app = Application::with('job')->find($this->applicationId);
        if (! $app) {
            return;
        }

        try {
            $drafts->enhanceWithAi($app);
        } catch (AiException $e) {
            if ($this->job && $e->isTemporary()) {
                $seconds = $e->retryAt ? max(60, min(3600, now()->diffInSeconds($e->retryAt, false))) : 120;
                $this->release((int) $seconds);
            }
        }
    }
}
