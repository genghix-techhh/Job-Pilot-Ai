<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Enums\MatchTier;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\ConnectedAccount;
use App\Models\InboundMessage;
use App\Models\Interview;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\Resume;
use App\Services\Applications\FollowUpService;
use App\Services\Diagnostics\Diagnostics;
use App\Services\Mail\GmailClient;
use App\Services\Matching\MatchService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MatchService $matches, Diagnostics $diag, GmailClient $gmail, FollowUpService $followUps): View
    {
        $user = $request->user();
        $profile = $matches->activeProfile();
        $allProfile = $user->profile;

        $active = JobPosting::query()->where('state', 'active')->whereNull('duplicate_of');

        $strong = $profile
            ? JobMatch::query()->where('user_id', $user->id)->where('profile_version', $profile->profile_version)
                ->where('tier', MatchTier::Strong->value)->whereNull('exclusion')
                ->whereHas('job', fn ($q) => $q->where('state', 'active')->whereNull('duplicate_of')->where('user_status', '!=', 'hidden'))
                ->count()
            : null;

        $stats = [
            'new' => (clone $active)->where('user_status', '!=', 'hidden')->where('first_seen_at', '>=', now()->subDays(7))->count(),
            'strong' => $strong,
            'drafts' => Application::query()->whereIn('status', [ApplicationStatus::DraftReady, ApplicationStatus::AwaitingApproval, ApplicationStatus::Approved])->count(),
            'submitted' => Application::query()->whereNotNull('submitted_at')->count(),
            'responses' => Application::query()->whereIn('status', [ApplicationStatus::ResponseReceived, ApplicationStatus::Assessment, ApplicationStatus::Interview, ApplicationStatus::Offer])->count(),
            'interviews' => Interview::query()->whereIn('state', ['tentative', 'confirmed'])->where('starts_at', '>=', now())->count(),
        ];

        $upcoming = Interview::query()->with('application.job')->whereIn('state', ['tentative', 'confirmed'])
            ->where(fn ($q) => $q->where('starts_at', '>=', now())->orWhere('deadline_at', '>=', now()))
            ->orderByRaw('COALESCE(starts_at, deadline_at)')->limit(5)->get();

        $topMatches = $profile
            ? JobMatch::query()->with(['job.source'])->where('user_id', $user->id)->where('profile_version', $profile->profile_version)
                ->whereIn('tier', [MatchTier::Strong->value, MatchTier::Potential->value])->whereNull('exclusion')
                ->whereHas('job', fn ($q) => $q->where('state', 'active')->whereNull('duplicate_of')->where('user_status', '!=', 'hidden'))
                ->orderByDesc('score')->limit(5)->get()
            : collect();

        $events = ApplicationEvent::query()->with('application.job')->latest('occurred_at')->limit(8)->get();

        $sources = JobSource::query()->orderBy('type')->orderBy('name')->get();
        $account = ConnectedAccount::query()->where('user_id', $user->id)->where('provider', 'google')->first();

        $checklist = [
            ['done' => $allProfile?->ai_consent !== null, 'label' => 'Choose your AI privacy option', 'url' => route('privacy.consent')],
            ['done' => Resume::query()->where('user_id', $user->id)->where('is_active', true)->exists(), 'label' => 'Upload your CV', 'url' => route('profile.edit')],
            ['done' => (bool) $profile, 'label' => 'Review and save your profile', 'url' => route('profile.edit')],
            ['done' => $sources->where('type.value', '!=', 'manual')->isNotEmpty(), 'label' => 'Add a job source (Greenhouse, Lever, Remote OK or a feed)', 'url' => route('sources.index')],
            ['done' => filled(\settings()->get('home_country')), 'label' => 'Set your country so remote eligibility can be checked', 'url' => route('settings.edit')],
        ];

        return view('dashboard', [
            'stats' => $stats,
            'followUps' => $followUps->due($user)->take(5),
            'followUpService' => $followUps,
            'profile' => $profile,
            'upcoming' => $upcoming,
            'topMatches' => $topMatches,
            'events' => $events,
            'sources' => $sources,
            'ai' => $diag->ai(),
            'queue' => $diag->queue(),
            'checklist' => $checklist,
            'checklistDone' => collect($checklist)->every(fn ($c) => $c['done']),
            'needsReview' => InboundMessage::query()->where('review_status', 'needs_review')->count(),
            'gmailConfigured' => $gmail->isConfigured(),
            'gmailConnected' => $account?->status === 'connected',
            'consentStale' => $allProfile && $allProfile->ai_consent === true && ! $allProfile->aiConsentGiven(),
        ]);
    }
}
