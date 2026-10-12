<?php

namespace App\View\Composers;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\InboundMessage;
use App\Models\Interview;
use Illuminate\View\View;

/** Small badge counts for the sidebar. Cheap indexed counts, no model hydration. */
class NavComposer
{
    public function compose(View $view): void
    {
        if (! auth()->check()) {
            return;
        }

        $view->with('navCounts', [
            'drafts' => Application::query()->whereIn('status', [ApplicationStatus::DraftReady, ApplicationStatus::AwaitingApproval])->count(),
            'review' => InboundMessage::query()->where('review_status', 'needs_review')->count(),
            'interviews' => Interview::query()->whereIn('state', ['tentative', 'confirmed'])
                ->whereBetween('starts_at', [now(), now()->addDays(7)])->count(),
        ]);
    }
}
