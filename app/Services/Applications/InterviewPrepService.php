<?php

namespace App\Services\Applications;

use App\Models\Application;
use App\Models\CandidateProfile;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiResultSanitizer;
use App\Services\Ai\Prompts\InterviewPrepPrompt;
use App\Services\Ai\Redactor;
use App\Services\Matching\MatchService;
use App\Services\Matching\RequirementSplitter;
use App\Services\Matching\SkillCatalog;
use App\Services\Profile\AiProfilePayload;
use Illuminate\Support\Str;

/**
 * Interview preparation. The LOCAL plan (always available, no AI, nothing leaves the server) links each
 * thing the job asks for to the evidence you actually have, flags gaps honestly, and seeds STAR stories
 * from your own bullets. Gemini can optionally write a sharper, job-specific question list — validated
 * so that every answer outline cites evidence that really exists in your profile.
 */
class InterviewPrepService
{
    private const BEHAVIOURAL = [
        ['Tell me about a time you disagreed with a teammate or stakeholder. How did you handle it?', 'Checks communication and collaboration.'],
        ['Describe a deadline you were at risk of missing. What did you do?', 'Checks prioritisation, honesty about risk and recovery.'],
        ['Tell me about something you built that did not go as planned. What did you learn?', 'Checks ownership and learning from failure.'],
        ['Why this role and this company?', 'Checks motivation and whether you researched them.'],
    ];

    public function __construct(
        private readonly MatchService $matches,
        private readonly AiProvider $ai,
        private readonly AiProfilePayload $payloads,
        private readonly AiResultSanitizer $sanitizer,
        private readonly Redactor $redactor,
    ) {}

    /** @return array<string, mixed> */
    public function local(Application $app, ?CandidateProfile $profile = null): array
    {
        $app->loadMissing('job');
        $profile ??= $this->matches->activeProfile();
        $job = $app->job;
        $split = RequirementSplitter::split($job->title, (string) $job->description);
        $needs = array_values(array_unique(array_merge($split['required'], $split['preferred'])));
        $mine = array_map('strtolower', $profile?->skillList() ?? []);
        $evidence = $profile ? $this->evidenceLines($profile) : [];

        $topics = [];
        foreach (array_slice($needs, 0, 12) as $skill) {
            $have = in_array(strtolower($skill), $mine, true);
            $proof = array_values(array_filter($evidence, fn ($e) => SkillCatalog::mentions($e['text'], $skill)));
            $topics[] = [
                'skill' => $skill,
                'have' => $have,
                'required' => in_array($skill, $split['required'], true),
                'evidence' => array_map(fn ($e) => $e['text'], array_slice($proof, 0, 2)),
                'advice' => $have
                    ? ($proof ? 'Prepare one concrete story from the evidence below.' : 'It is on your profile but no bullet shows it — recall a specific project and add it to your profile.')
                    : 'Not on your profile. Be upfront; show how quickly you learn related tools and what you would do in your first weeks.',
            ];
        }

        $questions = [];
        foreach (array_slice(array_filter($topics, fn ($t) => $t['have']), 0, 5) as $t) {
            $questions[] = [
                'category' => 'technical',
                'question' => "Tell me about a project where you used {$t['skill']}. What was your role, and what would you do differently?",
                'why_asked' => 'The posting asks for it; they want proof beyond a keyword.',
                'answer_outline' => $t['evidence'] ? 'Start from: '.$t['evidence'][0] : '',
                'evidence' => $t['evidence'],
            ];
        }
        foreach (array_slice(array_filter($topics, fn ($t) => ! $t['have'] && $t['required']), 0, 3) as $t) {
            $questions[] = [
                'category' => 'technical',
                'question' => "This role uses {$t['skill']}. How would you get productive with it?",
                'why_asked' => 'A required skill that is not on your CV — they want to see honesty and a learning plan.',
                'answer_outline' => '',
                'evidence' => [],
            ];
        }
        foreach ($this->roleQuestions($job->title) as [$q, $why]) {
            $questions[] = ['category' => 'role', 'question' => $q, 'why_asked' => $why, 'answer_outline' => '', 'evidence' => []];
        }
        foreach (self::BEHAVIOURAL as [$q, $why]) {
            $questions[] = ['category' => 'behavioural', 'question' => $q, 'why_asked' => $why, 'answer_outline' => '', 'evidence' => []];
        }
        $questions[] = [
            'category' => 'logistics',
            'question' => 'What is your availability, and how would you handle our time-zone overlap?',
            'why_asked' => 'Remote roles often need overlapping hours.',
            'answer_outline' => '', 'evidence' => [],
        ];

        $stories = [];
        foreach (array_slice($evidence, 0, 3) as $e) {
            $stories[] = ['seed' => $e['text'], 'prompts' => ['Situation: what was the context?', 'Task: what exactly was yours to do?', 'Action: what did YOU do (the seed above)?', 'Result: what changed, with a number if you honestly have one?']];
        }

        return [
            'generator' => 'local',
            'topics' => $topics,
            'questions' => $questions,
            'stories' => $stories,
            'gap_plan' => array_values(array_map(
                fn ($t) => ['skill' => $t['skill'], 'how_to_prepare' => 'Spend a few hours on the official tutorial and build one tiny working example you can describe truthfully.'],
                array_slice(array_filter($topics, fn ($t) => ! $t['have']), 0, 5)
            )),
            'questions_to_ask' => [
                'What does success look like in the first 90 days?',
                'How is the team structured, and who would I work with most closely?',
                'How do you handle on-call, deployments and code review?',
                'How does the team communicate across time zones?',
                'What are the biggest challenges the team is facing right now?',
            ],
            'research' => [
                'Read the job posting again and underline the top three responsibilities.',
                'Open the company website and the product; note one thing you would improve or ask about.',
                'Check the posting date and re-confirm the role is still open: '.$job->url,
            ],
        ];
    }

    /**
     * Ask Gemini for a sharper list. Returns the stored plan; throws AiException for the caller to display.
     *
     * @return array<string, mixed>
     *
     * @throws AiException
     */
    public function withAi(Application $app): array
    {
        $profile = $this->matches->activeProfile();
        if (! $profile) {
            throw new WorkflowException('Review and save your profile first.');
        }
        $app->loadMissing('job');
        $job = $app->job;
        $req = $job->ai_requirements ?? [];
        $payload = $this->payloads->build($profile);
        $summary = [
            'title' => $this->redactor->redactGeneral($job->title),
            'seniority' => $req['seniority'] ?? 'unknown',
            'required_skills' => $req['required_skills'] ?? RequirementSplitter::split($job->title, (string) $job->description)['required'],
            'responsibilities' => $req['responsibilities'] ?? [],
        ];

        $raw = $this->ai->generateJson(InterviewPrepPrompt::build($payload, $summary, $app->id));
        $index = $this->payloads->evidenceIndex($payload);

        $questions = [];
        foreach ($raw['questions'] as $q) {
            $refs = array_values(array_filter((array) $q['evidence_ids'], fn ($id) => isset($index[$id])));
            $outline = $refs === [] ? '' : $this->sanitizer->line((string) $q['answer_outline'], 500);   // unsupported outlines are dropped
            $questions[] = [
                'category' => $q['category'],
                'question' => $this->sanitizer->line((string) $q['question'], 240),
                'why_asked' => $this->sanitizer->line((string) $q['why_asked'], 200),
                'answer_outline' => $outline,
                'evidence' => array_map(fn ($id) => Str::limit($index[$id], 200), $refs),
            ];
        }

        $plan = $this->local($app, $profile);
        $plan['generator'] = 'gemini';
        $plan['model'] = config('jobpilot.gemini.model');
        $plan['questions'] = $questions;
        $plan['gap_plan'] = array_map(fn ($g) => [
            'skill' => $this->sanitizer->line((string) $g['skill'], 60),
            'how_to_prepare' => $this->sanitizer->line((string) $g['how_to_prepare'], 300),
        ], array_slice($raw['gap_plan'], 0, 5));
        $plan['questions_to_ask'] = array_map(fn ($q) => $this->sanitizer->line((string) $q, 200), array_slice($raw['questions_to_ask'], 0, 6));

        return $plan;
    }

    /** @return list<array{0: string, 1: string}> */
    private function roleQuestions(string $title): array
    {
        $t = Str::lower($title);
        $out = [];
        if (preg_match('/front|react|vue|ui\b|web developer/', $t)) {
            $out[] = ['How do you keep a page fast and accessible as it grows?', 'Frontend quality: performance, accessibility, state management.'];
        }
        if (preg_match('/back|api|php|laravel|python|node|server/', $t)) {
            $out[] = ['How would you design and version an API that other teams depend on?', 'Backend design: contracts, compatibility, errors, pagination.'];
            $out[] = ['A page is slow because of database queries. How do you find and fix the cause?', 'Debugging: indexes, N+1, caching, measuring first.'];
        }
        if (preg_match('/full[\s-]?stack/', $t)) {
            $out[] = ['How do you decide what belongs in the browser versus on the server?', 'Full-stack judgement across the boundary.'];
        }
        if (preg_match('/machine|ml\b|ai\b|data scien|llm/', $t)) {
            $out[] = ['How do you decide whether a model is good enough to ship, and how do you monitor it afterwards?', 'ML practice: evaluation, drift, failure modes.'];
        }
        if (preg_match('/devops|sre|platform|infra/', $t)) {
            $out[] = ['Walk me through how you would roll out and roll back a risky change.', 'Reliability: staged rollout, observability, rollback.'];
        }
        if ($out === []) {
            $out[] = ['What would you want to learn or improve in your first three months here?', 'Fit and self-awareness.'];
        }

        return array_slice($out, 0, 3);
    }

    /** @return list<array{text: string}> every bullet/project line the candidate wrote, most recent first */
    private function evidenceLines(CandidateProfile $profile): array
    {
        $s = $profile->structured ?? [];
        $out = [];
        foreach ($s['work_history'] ?? [] as $w) {
            foreach ($w['bullets'] ?? [] as $b) {
                $out[] = ['text' => (string) $b];
            }
        }
        foreach ($s['projects'] ?? [] as $p) {
            $out[] = ['text' => trim(($p['name'] ?? '').' — '.($p['description'] ?? '').' ['.implode(', ', $p['technologies'] ?? []).']')];
        }

        return array_values(array_filter($out, fn ($e) => $e['text'] !== ''));
    }
}
