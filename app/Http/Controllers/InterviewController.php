<?php

namespace App\Http\Controllers;

use App\Http\Requests\InterviewRequest;
use App\Models\Application;
use App\Models\Interview;
use App\Services\Interviews\IcsGenerator;
use App\Services\Interviews\InterviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InterviewController extends Controller
{
    public function index(Request $request): View
    {
        $uid = $request->user()->id;
        $base = Interview::query()->with('application.job')->where('user_id', $uid);

        return view('interviews.index', [
            'upcoming' => (clone $base)->whereIn('state', ['tentative', 'confirmed'])
                ->where(fn ($q) => $q->where('starts_at', '>=', now())->orWhere(fn ($w) => $w->whereNull('starts_at')->where('deadline_at', '>=', now())))
                ->orderByRaw('COALESCE(starts_at, deadline_at)')->get(),
            'undated' => (clone $base)->whereIn('state', ['tentative', 'confirmed'])->whereNull('starts_at')->whereNull('deadline_at')->get(),
            'past' => (clone $base)->where(fn ($q) => $q->whereIn('state', ['completed', 'cancelled'])->orWhere('starts_at', '<', now()))
                ->orderByRaw('COALESCE(starts_at, deadline_at) DESC')->limit(15)->get(),
            'applications' => Application::query()->with('job')->where('user_id', $uid)->whereNotNull('submitted_at')->latest('submitted_at')->get(),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function store(InterviewRequest $request, InterviewService $svc): RedirectResponse
    {
        $i = $svc->create($request->user(), $request->validated());

        return back()->with('status', 'Saved'.($i->state === 'tentative' ? ' as tentative — confirm it once the time is agreed.' : '.'));
    }

    public function update(InterviewRequest $request, Interview $interview, InterviewService $svc): RedirectResponse
    {
        $this->authorize('update', $interview);
        $svc->update($interview, $request->validated());

        return back()->with('status', 'Updated.');
    }

    public function confirm(Interview $interview, InterviewService $svc): RedirectResponse
    {
        $this->authorize('update', $interview);
        try {
            $svc->confirm($interview);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Confirmed. You can download the calendar file below.');
    }

    public function cancel(Interview $interview, InterviewService $svc): RedirectResponse
    {
        $this->authorize('update', $interview);
        $svc->cancel($interview);

        return back()->with('status', 'Marked as cancelled.');
    }

    public function complete(Interview $interview, InterviewService $svc): RedirectResponse
    {
        $this->authorize('update', $interview);
        $svc->complete($interview);

        return back()->with('status', 'Marked as done.');
    }

    public function ics(Interview $interview, IcsGenerator $ics): Response|RedirectResponse
    {
        $this->authorize('view', $interview);
        $interview->load('application.job');
        if (! $interview->starts_at && ! $interview->deadline_at) {
            return back()->with('error', 'Add a date and time first.');
        }

        return response($ics->forInterview($interview), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.(Str::slug($interview->title) ?: 'event').'.ics"',
        ]);
    }
}
