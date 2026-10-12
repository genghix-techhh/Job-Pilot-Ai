<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\JobSource;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProvider;
use App\Services\Applications\ApplicationService;
use App\Services\Applications\DocumentRenderer;
use App\Services\Applications\DraftService;
use App\Services\Applications\FollowUpService;
use App\Services\Applications\InterviewPrepService;
use App\Services\Applications\Submission\SubmissionManager;
use App\Services\Applications\WorkflowException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    /** Pipeline columns shown on the tracker board. */
    private const STAGES = [
        'Drafts' => ['draft_ready', 'awaiting_approval', 'submission_failed'],
        'Approved' => ['approved', 'submission_in_progress'],
        'Submitted' => ['submitted'],
        'In conversation' => ['response_received', 'assessment'],
        'Interview' => ['interview'],
        'Offer' => ['offer'],
        'Closed' => ['rejected', 'withdrawn', 'closed'],
    ];

    public function index(Request $request): View
    {
        $f = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'in:employment,freelance'],
            'source' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'view' => ['nullable', 'in:table,board'],
        ]);

        $q = Application::query()->with(['job.source', 'interviews' => fn ($i) => $i->whereIn('state', ['tentative', 'confirmed'])->orderBy('starts_at')])
            ->where('user_id', $request->user()->id);

        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['type'])) {
            $q->where('type', $f['type']);
        }
        if (! empty($f['source'])) {
            $q->whereHas('job', fn ($j) => $j->where('source_id', $f['source']));
        }
        if ($t = trim((string) ($f['q'] ?? ''))) {
            $like = '%'.addcslashes($t, '%_\\').'%';
            $q->whereHas('job', fn ($j) => $j->where('title', 'like', $like)->orWhere('company', 'like', $like));
        }

        $apps = $q->orderByRaw('COALESCE(last_event_at, updated_at) DESC')->get();

        return view('applications.index', [
            'apps' => $apps,
            'filters' => $f,
            'view' => $f['view'] ?? 'table',
            'stages' => self::STAGES,
            'sources' => JobSource::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => ApplicationStatus::cases(),
        ]);
    }

    public function store(Request $request, JobPosting $job, DraftService $drafts): RedirectResponse
    {
        try {
            $app = $drafts->create($request->user(), $job);
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('applications.show', $app)->with('status', 'Draft ready for your review. Nothing has been submitted.');
    }

    public function prep(Application $application, InterviewPrepService $prep, AiProvider $ai): View
    {
        $this->authorize('view', $application);
        $application->load('job.source');
        $stored = $application->artifacts['prep'] ?? null;

        return view('applications.prep', [
            'app' => $application,
            'job' => $application->job,
            'plan' => $stored ?: $prep->local($application),
            'aiStored' => (bool) $stored,
            'ai' => $ai->availability(),
        ]);
    }

    public function prepGenerate(Application $application, InterviewPrepService $prep): RedirectResponse
    {
        $this->authorize('update', $application);
        try {
            $plan = $prep->withAi($application);
        } catch (AiException $e) {
            return back()->with('error', 'Gemini could not prepare this: '.$e->getMessage());
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }
        $application->artifacts = array_merge((array) $application->artifacts, ['prep' => $plan + ['generated_at' => now()->toIso8601String()]]);
        $application->save();

        return back()->with('status', 'Interview prep improved with Gemini. Every answer outline cites your own evidence; outlines without evidence were removed.');
    }

    public function prepReset(Application $application): RedirectResponse
    {
        $this->authorize('update', $application);
        $art = (array) $application->artifacts;
        unset($art['prep']);
        $application->artifacts = $art;
        $application->save();

        return back()->with('status', 'Back to the offline prep plan.');
    }

    public function followUpPlan(Application $application, FollowUpService $followUps): RedirectResponse
    {
        $this->authorize('update', $application);
        $followUps->plan($application);

        return back()->with('status', 'Follow-up planned — it now shows as your next task. Copy the message below and send it yourself.');
    }

    public function followUpDismiss(Application $application, FollowUpService $followUps): RedirectResponse
    {
        $this->authorize('update', $application);
        $followUps->dismiss($application);

        return back()->with('status', 'Follow-up nudge dismissed for this application.');
    }

    public function show(Request $request, Application $application, SubmissionManager $submissions, FollowUpService $followUps): View
    {
        $this->authorize('view', $application);
        $application->load(['job.source', 'resume', 'events', 'interviews', 'messages', 'user.profile']);

        $match = JobMatch::query()->where('user_id', $application->user_id)->where('job_id', $application->job_id)->latest('id')->first();

        return view('applications.show', [
            'app' => $application,
            'job' => $application->job,
            'match' => $match,
            'artifacts' => (array) $application->artifacts,
            'gateway' => $submissions->gatewayFor($application),
            'approvalCurrent' => $application->approvalIsCurrent(),
            'unanswered' => collect($application->screening_answers ?? [])->filter(fn ($a) => blank($a['answer'] ?? null))->count(),
            'ownerStatuses' => ApplicationService::OWNER_STATUSES,
            'followUp' => $application->status === ApplicationStatus::Submitted && $application->submitted_at
                ? ['due' => $followUps->isDue($application), 'days' => $followUps->daysWaiting($application), 'message' => $followUps->message($application)]
                : null,
        ]);
    }

    public function update(Request $request, Application $application, ApplicationService $svc): RedirectResponse
    {
        $this->authorize('update', $application);
        $d = $request->validate([
            'cover_letter' => ['nullable', 'string', 'max:20000'],
            'tailored_resume' => ['nullable', 'string', 'max:40000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'screening_answers' => ['nullable', 'array', 'max:20'],
            'screening_answers.*.question' => ['nullable', 'string', 'max:500'],
            'screening_answers.*.answer' => ['nullable', 'string', 'max:3000'],
            'screening_answers.*.needs_confirmation' => ['nullable', 'boolean'],
        ]);
        $d['screening_answers'] = array_values($d['screening_answers'] ?? []);

        try {
            $before = $application->status;
            $app = $svc->saveDraft($application, $d, $request->user());
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return back()->with('status', $before === ApplicationStatus::Approved && $app->status !== $before
            ? 'Draft saved. Because you changed the documents, the earlier approval was revoked — review and approve again.'
            : 'Draft saved. Status: not submitted.');
    }

    public function addQuestion(Request $request, Application $application, DraftService $drafts): RedirectResponse
    {
        $this->authorize('update', $application);
        $d = $request->validate(['question' => ['required', 'string', 'max:500']]);
        try {
            $drafts->addQuestion($application, $d['question']);
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', DraftService::isConsequential($d['question'])
            ? 'Question added. It touches eligibility or personal facts, so it is left blank for you to answer — the app never guesses these.'
            : 'Question added. Answer it yourself or regenerate the draft to get a suggestion from your evidence.');
    }

    public function regenerate(Application $application, DraftService $drafts): RedirectResponse
    {
        $this->authorize('update', $application);
        try {
            $drafts->regenerate($application);
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Draft rebuilt from your current profile.');
    }

    public function adoptAiSuggestion(Application $application, ApplicationService $svc): RedirectResponse
    {
        $this->authorize('update', $application);
        $s = $application->artifacts['ai_suggestion'] ?? null;
        if (! $s) {
            return back()->with('error', 'There is no AI suggestion to apply.');
        }
        try {
            $app = $svc->saveDraft($application, ['cover_letter' => $s['cover_letter'], 'tailored_resume' => $s['tailored_resume']]);
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }
        $art = (array) $app->artifacts;
        unset($art['ai_suggestion']);
        $art['edited'] = false;
        $app->artifacts = $art;
        $app->save();

        return back()->with('status', 'AI suggestion applied. Review it before approving.');
    }

    public function requestApproval(Application $application, ApplicationService $svc): RedirectResponse
    {
        $this->authorize('update', $application);

        return $this->attempt(fn () => $svc->requestApproval($application), 'Marked as awaiting your approval.');
    }

    public function approve(Request $request, Application $application, ApplicationService $svc): RedirectResponse
    {
        $this->authorize('update', $application);
        $d = $request->validate([
            'reviewed' => ['nullable', 'boolean'],
            'truthful' => ['nullable', 'boolean'],
            'eligibility_ack' => ['nullable', 'boolean'],
        ]);

        return $this->attempt(
            fn () => $svc->approve($application, $request->user(), $d),
            'Approved — but NOT submitted. Open the official application page to apply, then confirm with "I submitted this".'
        );
    }

    public function revoke(Application $application, ApplicationService $svc): RedirectResponse
    {
        $this->authorize('update', $application);

        return $this->attempt(fn () => $svc->revokeApproval($application, 'Revoked by owner.'), 'Approval revoked. You can edit the draft again.');
    }

    public function reject(Request $request, Application $application, ApplicationService $svc): RedirectResponse
    {
        $this->authorize('update', $application);
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        return $this->attempt(fn () => $svc->reject($application, $d['reason'] ?? null), 'Draft rejected and closed. Nothing was sent.');
    }

    /** Sends the owner to the employer's page. Never marks anything as submitted. */
    public function open(Application $application, ApplicationService $svc): RedirectResponse
    {
        $this->authorize('update', $application);
        try {
            $url = $svc->openOfficialPage($application);
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->away($url);
    }

    public function submit(Application $application, ApplicationService $svc): RedirectResponse
    {
        $this->authorize('update', $application);

        return $this->attempt(function () use ($svc, $application) {
            $app = $svc->submitViaGateway($application);
            if ($app->status !== ApplicationStatus::Submitted) {
                throw new WorkflowException('The submission did not go through ('.$app->status->label().'). Nothing is recorded as submitted.');
            }
        }, 'Submitted through the authorised integration and confirmed by the response.');
    }

    public function markSubmitted(Request $request, Application $application, ApplicationService $svc): RedirectResponse
    {
        $this->authorize('update', $application);
        $d = $request->validate([
            'confirm' => ['accepted'],
            'reference' => ['nullable', 'string', 'max:190'],
            'submitted_on' => ['nullable', 'date', 'before_or_equal:today'],
        ], ['confirm.accepted' => 'Tick the box to confirm that you submitted this application yourself.']);

        $at = ! empty($d['submitted_on']) ? Carbon::parse($d['submitted_on'], $request->user()->tz())->utc() : null;

        return $this->attempt(
            fn () => $svc->markSubmittedManually($application, $request->user(), $d['reference'] ?? null, $at),
            'Recorded as submitted (confirmed by you). It now appears in your tracker.'
        );
    }

    public function status(Request $request, Application $application, ApplicationService $svc): RedirectResponse
    {
        $this->authorize('update', $application);
        $d = $request->validate([
            'status' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $to = ApplicationStatus::tryFrom($d['status']);
        if (! $to) {
            return back()->with('error', 'Unknown status.');
        }

        if ($to === ApplicationStatus::SubmissionFailed && $application->status === ApplicationStatus::SubmissionInProgress) {
            return $this->attempt(fn () => $svc->markSubmissionFailed($application), 'Marked as failed. Nothing is recorded as submitted; you can approve again.');
        }

        return $this->attempt(fn () => $svc->updateStatus($application, $to, 'owner', $d['note'] ?? null), 'Status updated to "'.$to->label().'".');
    }

    public function tracking(Request $request, Application $application, ApplicationService $svc): RedirectResponse
    {
        $this->authorize('update', $application);
        $d = $request->validate([
            'next_task' => ['nullable', 'string', 'max:190'],
            'next_task_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $svc->updateTracking($application, [
            'next_task' => $d['next_task'] ?? null,
            'next_task_at' => ! empty($d['next_task_at']) ? Carbon::parse($d['next_task_at'], $request->user()->tz())->utc() : null,
            'notes' => $d['notes'] ?? null,
        ]);

        return back()->with('status', 'Tracking details saved.');
    }

    public function export(Request $request, Application $application, string $kind, DocumentRenderer $docs): Response|View
    {
        $this->authorize('view', $application);
        $format = $request->query('format', 'txt');
        abort_unless(in_array($format, ['txt', 'html', 'print', 'pdf'], true), 404);

        $application->load('job');
        $text = $kind === 'cover-letter' ? (string) $application->cover_letter : (string) $application->tailored_resume;
        $title = ($kind === 'cover-letter' ? 'Cover letter' : 'CV').' — '.$application->job->title;
        $base = Str::slug(($application->job->company ?: 'job').'-'.$application->job->title.'-'.$kind) ?: $kind;
        $docKind = $kind === 'cover-letter' ? 'letter' : 'resume';
        $headers = fn (string $type, string $ext) => ['Content-Type' => $type, 'Content-Disposition' => "attachment; filename=\"{$base}.{$ext}\"", 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

        return match ($format) {
            'print' => view('applications.document', ['doc' => $docs->structure($docKind, $text), 'title' => $title, 'print' => true, 'pdf' => false]),
            'html' => response(view('applications.document', ['doc' => $docs->structure($docKind, $text), 'title' => $title, 'print' => false, 'pdf' => false])->render(), 200, $headers('text/html; charset=utf-8', 'html')),
            'pdf' => response($docs->pdf($docKind, $text, $title), 200, $headers('application/pdf', 'pdf')),
            default => response($text, 200, $headers('text/plain; charset=utf-8', 'txt')),
        };
    }

    /** Run a workflow action and turn rule violations into friendly flash messages. */
    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $success);
    }
}
