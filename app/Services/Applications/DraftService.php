<?php

namespace App\Services\Applications;

use App\Enums\ApplicationStatus;
use App\Enums\JobKind;
use App\Jobs\GenerateApplicationDraft;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiResultSanitizer;
use App\Services\Ai\Prompts\ApplicationDraftPrompt;
use App\Services\Ai\Redactor;
use App\Services\Matching\MatchService;
use App\Services\Profile\AiProfilePayload;
use Illuminate\Support\Str;

/**
 * Creates and (optionally) AI-enhances application drafts. A usable, fully truthful
 * template draft exists the moment the owner clicks "Create draft"; Gemini only improves
 * the wording, and only from evidence refs we sent and can verify.
 */
class DraftService
{
    /** Questions the app must never answer on the owner's behalf. */
    private const CONSEQUENTIAL = '/authori[sz]|work permit|\bvisa\b|sponsor|citizen|nationalit|relocat|salary|compensation|expect(?:ed|ation)|notice period|criminal|background check|disabilit|gender|\brace\b|ethnic|veteran|\bage\b|date of birth|marital|pregnan|religio|clearance|right to work|legally|resid(?:e|ent|ency)|commut/i';

    public function __construct(
        private readonly MatchService $matches,
        private readonly LocalDraftBuilder $local,
        private readonly ApplicationService $applications,
        private readonly AiProvider $ai,
        private readonly AiProfilePayload $payloads,
        private readonly AiResultSanitizer $sanitizer,
        private readonly Redactor $redactor,
    ) {}

    public static function isConsequential(string $question): bool
    {
        return (bool) preg_match(self::CONSEQUENTIAL, $question);
    }

    /** Create the draft (idempotent per job). Throws WorkflowException when preconditions fail. */
    public function create(User $user, JobPosting $job): Application
    {
        $profile = $this->matches->activeProfile();
        if (! $profile) {
            throw new WorkflowException('Review and save your profile first (CV & Profile) — drafts are built only from your reviewed data.');
        }

        $existing = Application::query()->where('user_id', $user->id)->where('job_id', $job->id)->first();
        if ($existing) {
            if ($existing->status === ApplicationStatus::Closed && $existing->submitted_at === null) {
                $this->applications->updateStatus($existing, ApplicationStatus::DraftReady);
                $this->applications->record($existing, 'draft_reopened');
            }

            return $existing->refresh();
        }

        $match = JobMatch::query()->where('job_id', $job->id)->where('user_id', $user->id)->latest('id')->first();
        $unknowns = $job->kind === JobKind::Freelance ? ($match?->freelance_fit['unknowns'] ?? null) : null;
        $draft = $this->local->build($profile, $job, $match, $unknowns);
        $resume = Resume::query()->where('user_id', $user->id)->where('is_active', true)->first();

        $app = Application::create([
            'user_id' => $user->id,
            'job_id' => $job->id,
            'resume_id' => $resume?->id,
            'profile_version' => $profile->profile_version,
            'type' => $job->kind->value,
            'status' => ApplicationStatus::DraftReady,
            'cover_letter' => $draft['cover_letter'],
            'tailored_resume' => $draft['tailored_resume'],
            'screening_answers' => [],
            'artifacts' => $this->artifacts($draft, 'template'),
            'idempotency_key' => hash('sha256', $user->id.'|'.$job->id.'|'.Str::uuid()),
            'last_event_at' => now(),
        ]);
        $this->applications->record($app, 'draft_created', 'owner', ['generator' => 'template', 'resume_version' => $resume?->version], null, ApplicationStatus::DraftReady);

        $this->queueAi($app);

        return $app;
    }

    /** Throw away the current text and rebuild it (asks nothing of Gemini unless available). */
    public function regenerate(Application $app): Application
    {
        $profile = $this->matches->activeProfile();
        if (! $profile) {
            throw new WorkflowException('Review and save your profile first.');
        }
        $job = $app->job;
        $match = JobMatch::query()->where('job_id', $job->id)->where('user_id', $app->user_id)->latest('id')->first();
        $draft = $this->local->build($profile, $job, $match, $job->kind === JobKind::Freelance ? ($match?->freelance_fit['unknowns'] ?? null) : null);

        $app = $this->applications->saveDraft($app, ['cover_letter' => $draft['cover_letter'], 'tailored_resume' => $draft['tailored_resume']]);
        $app->artifacts = $this->artifacts($draft, 'template');
        $app->profile_version = $profile->profile_version;
        $app->save();
        $this->applications->record($app, 'draft_regenerated', 'owner');
        $this->queueAi($app);

        return $app->refresh();
    }

    /** @param array<string, mixed> $draft @return array<string, mixed> */
    private function artifacts(array $draft, string $generator): array
    {
        return [
            'generator' => $generator,
            'ai_status' => 'none',
            'ai_note' => null,
            'edited' => false,
            'paragraphs' => $draft['paragraphs'],
            'warnings' => $draft['warnings'],
            'open_questions' => [],
            'highlighted_skills' => $draft['highlighted_skills'],
            'model' => null,
        ];
    }

    private function queueAi(Application $app): void
    {
        $avail = $this->ai->availability();
        $art = (array) $app->artifacts;
        if ($avail->available) {
            $art['ai_status'] = 'pending';
            $art['ai_note'] = 'Gemini is polishing this draft from your verified evidence…';
            $app->artifacts = $art;
            $app->save();
            GenerateApplicationDraft::dispatch($app->id);
        } else {
            $art['ai_note'] = 'AI wording is off ('.$avail->message.') — this is the evidence-only template draft.';
            $app->artifacts = $art;
            $app->save();
        }
    }

    /**
     * Ask Gemini to improve the wording from verified evidence. Results are validated: every body
     * paragraph must cite evidence we sent; uncited ones are flagged "Needs your confirmation".
     *
     * @throws AiException temporary failures bubble up so the queue retries later
     */
    public function enhanceWithAi(Application $app): Application
    {
        $app->loadMissing('job');
        $profile = $this->matches->activeProfile();
        if (! $profile || ! in_array($app->status, [ApplicationStatus::DraftReady, ApplicationStatus::AwaitingApproval], true)) {
            return $app;
        }

        $job = $app->job;
        $req = $job->ai_requirements ?? [];
        $payload = $this->payloads->build($profile);
        $jobSummary = [
            'title' => $this->redactor->redactGeneral($job->title),
            'company' => $job->company,
            'kind' => $job->kind->value,
            'required_skills' => $req['required_skills'] ?? ($job->skills ?? []),
            'responsibilities' => $req['responsibilities'] ?? [],
        ];
        $questions = collect($app->screening_answers ?? [])->pluck('question')->filter()->map(fn ($q) => $this->redactor->redactGeneral((string) $q))->values()->all();

        try {
            $raw = $this->ai->generateJson(ApplicationDraftPrompt::build($payload, $jobSummary, $questions, $job->id, $job->kind->value));
        } catch (AiException $e) {
            $art = (array) $app->artifacts;
            if ($e->isTemporary()) {
                $art['ai_note'] = 'Waiting to retry Gemini: '.Str::limit($e->getMessage(), 160, '');
                $app->artifacts = $art;
                $app->save();
                throw $e;
            }
            $art['ai_status'] = 'failed';
            $art['ai_note'] = 'AI wording was skipped: '.Str::limit($e->getMessage(), 200, '');
            $app->artifacts = $art;
            $app->save();

            return $app;
        }

        $index = $this->payloads->evidenceIndex($payload);
        $mySkills = array_map('strtolower', $profile->skillList());
        $name = (string) ($profile->structured['name'] ?? '');

        $paragraphs = [];
        $warnings = [];
        foreach ($raw['cover_letter'] as $p) {
            $refs = array_values(array_filter((array) $p['evidence_ids'], fn ($id) => isset($index[$id])));
            $text = Str::limit($this->sanitizer->line((string) $p['text'], 900), 900, '');
            if ($text === '') {
                continue;
            }
            $verified = $p['kind'] !== 'body' || $refs !== [];
            if (! $verified) {
                $warnings[] = 'Needs your confirmation (no supporting evidence found in your profile): "'.Str::limit($text, 110).'"';
            }
            $paragraphs[] = [
                'kind' => $p['kind'],
                'text' => $text,
                'evidence' => array_map(fn ($id) => ['ref' => $id, 'text' => Str::limit($index[$id], 200)], $refs),
                'verified' => $verified,
            ];
        }
        if ($paragraphs === []) {
            throw AiException::invalid('no usable cover letter paragraphs');
        }

        $highlighted = array_values(array_filter(
            array_map(fn ($s) => trim((string) $s), $raw['highlighted_skills']),
            fn ($s) => in_array(strtolower($s), $mySkills, true)
        ));
        $summary = '';
        $summaryRefs = array_filter((array) ($raw['summary_evidence_ids'] ?? []), fn ($id) => isset($index[$id]));
        if ($summaryRefs && trim((string) $raw['tailored_summary']) !== '') {
            $summary = $this->sanitizer->line((string) $raw['tailored_summary'], 500);
        }

        $answers = $this->mergeAnswers((array) ($app->screening_answers ?? []), (array) $raw['answers'], $index);

        $isFreelance = $job->kind === JobKind::Freelance;
        $coverLetter = $this->local->composeCoverLetter($paragraphs, $name, $isFreelance);
        $resume = $this->local->composeResume(
            $profile,
            array_values(array_unique(array_merge($highlighted, $profile->skillList()))),
            $this->rankedBullets($profile, (array) $raw['highlighted_evidence_ids']),
            $this->local->rankProjects($profile, $job),
            $summary !== '' ? $summary : (string) ($profile->structured['summary'] ?? ''),
        );

        $art = (array) $app->artifacts;
        $art = array_merge($art, [
            'generator' => 'gemini',
            'ai_status' => 'done',
            'ai_note' => null,
            'paragraphs' => $paragraphs,
            'warnings' => array_values(array_unique(array_merge($warnings, array_filter((array) ($art['warnings'] ?? []), fn ($w) => ! str_starts_with($w, 'Needs your confirmation'))))),
            'open_questions' => array_map(fn ($q) => $this->sanitizer->line((string) $q, 200), array_slice((array) $raw['open_questions'], 0, 8)),
            'highlighted_skills' => $highlighted,
            'model' => config('jobpilot.gemini.model'),
            'prompt_version' => ApplicationDraftPrompt::VERSION,
        ]);

        if (! empty($art['edited'])) {
            // The owner already edited: never overwrite their work; keep the AI version as a suggestion.
            $art['ai_suggestion'] = ['cover_letter' => $coverLetter, 'tailored_resume' => $resume];
            $app->artifacts = $art;
            $app->save();
            $this->applications->record($app, 'draft_ai_suggestion_ready', 'system');

            return $app->refresh();
        }

        $app->cover_letter = $coverLetter;
        $app->tailored_resume = $resume;
        $app->screening_answers = $answers;
        $app->artifacts = $art;
        $app->revision++;
        $app->save();
        $this->applications->record($app, 'draft_ai_generated', 'system', ['model' => config('jobpilot.gemini.model'), 'prompt_version' => ApplicationDraftPrompt::VERSION]);

        return $app->refresh();
    }

    /** @param list<array<string, mixed>> $existing @param list<array<string, mixed>> $ai @param array<string, string> $index */
    private function mergeAnswers(array $existing, array $ai, array $index): array
    {
        $byQ = collect($ai)->keyBy(fn ($a) => Str::lower(trim((string) $a['question'])));
        $out = [];
        foreach ($existing as $row) {
            $q = (string) ($row['question'] ?? '');
            $a = $byQ->get(Str::lower(trim($q)));
            $refs = $a ? array_values(array_filter((array) $a['evidence_ids'], fn ($id) => isset($index[$id]))) : [];
            $needs = self::isConsequential($q) || ! $a || ($a['needs_confirmation'] ?? true) || $refs === [];
            $answer = (! $needs && $a) ? $this->sanitizer->line((string) $a['answer'], 1500) : (string) ($row['answer'] ?? '');
            $out[] = [
                'question' => $q,
                'answer' => self::isConsequential($q) ? (string) ($row['answer'] ?? '') : $answer,
                'needs_confirmation' => $needs || blank($answer),
                'evidence' => array_map(fn ($id) => ['ref' => $id, 'text' => Str::limit($index[$id], 160)], $refs),
            ];
        }

        return $out;
    }

    /** Add a screening question; consequential ones are flagged and left blank for the owner. */
    public function addQuestion(Application $app, string $question): Application
    {
        $question = trim($question);
        $rows = (array) ($app->screening_answers ?? []);
        $rows[] = ['question' => $question, 'answer' => '', 'needs_confirmation' => true, 'evidence' => []];

        return $this->applications->saveDraft($app, ['screening_answers' => $rows]);
    }

    /** @param list<string> $ids @return list<array<string, mixed>> */
    private function rankedBullets(CandidateProfile $profile, array $ids): array
    {
        $rank = array_flip(array_values($ids));
        $out = [];
        foreach (($profile->structured['work_history'] ?? []) as $wi => $w) {
            foreach (($w['bullets'] ?? []) as $bi => $b) {
                $ref = 'W'.($wi + 1).'.'.($bi + 1);
                $out[] = ['ref' => $ref, 'text' => $b, 'score' => isset($rank[$ref]) ? 100 - $rank[$ref] : 0];
            }
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $out;
    }
}
