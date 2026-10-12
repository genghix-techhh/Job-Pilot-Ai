<?php

namespace App\Services\Mail;

use App\Enums\MessageClass;
use App\Models\Application;
use App\Models\ConnectedAccount;
use App\Models\InboundMessage;
use App\Models\Interview;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiResultSanitizer;
use App\Services\Ai\Prompts\EmailClassificationPrompt;
use App\Services\Ai\Redactor;
use App\Services\Applications\ApplicationService;
use App\Services\Notifications\NotificationService;
use App\Services\SettingsService;
use App\Support\Text;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns one fetched e-mail into a stored InboundMessage and, ONLY when the application match
 * is unambiguous, applies the consequences (status hint, tentative interview entry, alert).
 *
 * It never replies, never accepts invitations and never treats a proposed time as confirmed.
 * The e-mail body is treated as untrusted data: it can only influence a classification field
 * that is validated against fixed enums and a list of ids we supplied.
 */
class InboundProcessor
{
    public function __construct(
        private readonly InboundClassifier $classifier,
        private readonly ApplicationMatcher $matcher,
        private readonly TimeExtractor $times,
        private readonly AiProvider $ai,
        private readonly AiResultSanitizer $sanitizer,
        private readonly Redactor $redactor,
        private readonly ApplicationService $applications,
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $msg  normalized message incl. 'body' (may be empty)
     * @param  Collection<int, Application>  $apps  submitted applications to match against
     */
    public function process(ConnectedAccount $account, array $msg, Collection $apps): ?InboundMessage
    {
        $tz = $account->user?->tz() ?? config('jobpilot.timezone');
        $text = trim((string) ($msg['body'] ?? '')) !== '' ? (string) $msg['body'] : (string) $msg['snippet'];
        $received = $msg['received_at'] ?? now();

        $match = $this->matcher->match($msg, $apps);
        $rule = $this->classifier->classify((string) $msg['subject'], $text);
        $class = $rule['class'];
        $confidence = $rule['confidence'];
        $aiUsed = false;
        $aiMatched = null;
        $aiTimes = [];
        $deadline = null;
        $action = null;

        $worthAi = ($match['ranked'] !== [] || $this->classifier->looksRecruiting((string) $msg['subject'], (string) $msg['snippet']))
            && ($confidence < 0.8 || in_array($class, [MessageClass::InterviewInvite, MessageClass::Assessment], true));

        if ($worthAi && $this->ai->availability()->available) {
            try {
                $r = $this->askAi($msg, $text, $match['ranked'], $apps);
                $class = $r['class'];
                $confidence = $r['confidence'];
                $aiMatched = $r['application_id'];
                $aiTimes = $r['times'];
                $deadline = $r['deadline'];
                $action = $r['action'];
                $aiUsed = true;
            } catch (AiException) {
                // Keep the rule-based result; the AI status is visible on the diagnostics page.
            }
        }

        if ($class === MessageClass::Unrelated && $match['ranked'] === []) {
            return null; // nothing job-related: do not store it
        }

        // ---- application link ---------------------------------------------------------------
        $applicationId = $match['matched'];
        if (! $applicationId && $aiMatched && count($match['ranked']) === 1 && $match['ranked'][0] === $aiMatched) {
            $applicationId = $aiMatched;
        }
        $auto = $applicationId !== null && $confidence >= 0.7 && $class !== MessageClass::Uncertain;

        // ---- extraction (local first; model times are merged in) ------------------------------
        $extracted = $this->extract($text, $aiTimes, $deadline, $action, $received, $tz, $class);

        $store = (bool) $this->settings->get('store_message_bodies');
        $message = InboundMessage::firstOrCreate(
            ['dedupe_key' => sha1($account->id.'|'.$msg['id'])],
            [
                'connected_account_id' => $account->id,
                'provider_message_id' => $msg['id'],
                'thread_id' => $msg['thread_id'] ?: null,
                'from_email' => Str::limit((string) $msg['from_email'], 250, ''),
                'from_name' => Str::limit((string) $msg['from_name'], 190, ''),
                'subject' => Str::limit((string) $msg['subject'], 480, ''),
                'snippet' => Str::limit(Text::normalizeWhitespace((string) $msg['snippet']), 300, '…'),
                'body' => $store ? Text::limit($text, 4000) : null,
                'received_at' => $received,
                'classification' => $class,
                'confidence' => round($confidence, 2),
                'extracted' => $extracted,
                'application_id' => $applicationId,
                'candidate_application_ids' => $match['ranked'] ?: null,
                'review_status' => $auto ? 'auto' : 'needs_review',
                'web_link' => 'https://mail.google.com/mail/u/0/#all/'.rawurlencode((string) $msg['id']),
                'ai_classified' => $aiUsed,
            ]
        );

        if ($message->wasRecentlyCreated && $auto) {
            $this->applyOutcome($message);
        }

        return $message;
    }

    /** Apply the consequences of a (confirmed) message to its application. Idempotent. */
    public function applyOutcome(InboundMessage $message): void
    {
        $app = $message->application()->with('job')->first();
        if (! $app || ($message->extracted['applied'] ?? false)) {
            return;
        }
        $class = $message->classification;
        $x = (array) $message->extracted;

        $this->applications->record($app, 'email_detected', 'gmail', [
            'classification' => $class->value,
            'confidence' => $message->confidence,
            'message_id' => $message->id,
        ]);

        if ($implied = $class->impliedStatus()) {
            $this->applications->applyDetectedStatus($app, $implied, ['message_id' => $message->id, 'classification' => $class->value]);
        }

        if (in_array($class, [MessageClass::InterviewInvite, MessageClass::Assessment], true)) {
            $this->createInterviews($message, $app, $x);
        }

        if (in_array($class, [MessageClass::RecruiterReply, MessageClass::InfoRequest, MessageClass::Rejection, MessageClass::Offer, MessageClass::InterviewInvite, MessageClass::Assessment], true)) {
            $this->notifications->recruiterResponse($app, $class->label(), $message->id);
        }

        $message->extracted = array_merge($x, ['applied' => true]);
        $message->save();
    }

    /** The owner corrected or confirmed the match/classification. */
    public function confirm(InboundMessage $message, ?Application $application, ?MessageClass $class): void
    {
        $message->fill([
            'application_id' => $application?->id ?? $message->application_id,
            'classification' => $class ?? $message->classification,
            'review_status' => 'confirmed',
        ])->save();
        $this->applyOutcome($message->refresh());
    }

    // ---- internals ---------------------------------------------------------------------------

    /** @param array<string, mixed> $x */
    private function createInterviews(InboundMessage $m, Application $app, array $x): void
    {
        if (Interview::query()->where('inbound_message_id', $m->id)->exists()) {
            return;
        }
        $kind = $m->classification === MessageClass::Assessment ? 'assessment' : 'interview';
        $title = ($kind === 'assessment' ? 'Assessment' : 'Interview').' — '.$app->job->title.($app->job->company ? ' ('.$app->job->company.')' : '');
        $slots = $x['proposed_times'] ?? [];
        $deadline = ! empty($x['deadline_at']) ? Carbon::parse($x['deadline_at'], 'UTC') : null;
        $common = [
            'user_id' => $app->user_id,
            'application_id' => $app->id,
            'inbound_message_id' => $m->id,
            'kind' => $kind,
            'meeting_url' => $x['meeting_url'] ?? null,
            'state' => 'tentative',                     // never confirmed automatically
            'notes' => Str::limit(trim(($x['requested_action'] ?? '')."\nDetected from an e-mail; verify the time before relying on it."), 900, ''),
        ];

        if ($slots === []) {
            $i = Interview::create($common + ['title' => $title, 'source_timezone' => $app->user->tz(), 'deadline_at' => $deadline]);
            $this->notifications->interviewDetected($i->load('user'));

            return;
        }
        foreach (array_slice($slots, 0, 3) as $n => $slot) {
            $start = Carbon::parse($slot['start_utc'], 'UTC');
            $i = Interview::create($common + [
                'title' => $title.(count($slots) > 1 ? ' — option '.($n + 1) : ''),
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(45),
                'deadline_at' => $deadline,
                'source_timezone' => $slot['tz_label_iana'] ?? 'UTC',
                'notes' => $common['notes'].($slot['tz_stated'] ? '' : ' Time zone was NOT stated in the e-mail.').(! empty($slot['ambiguous']) ? ' The time-zone abbreviation is ambiguous.' : ''),
            ]);
            if ($n === 0) {
                $this->notifications->interviewDetected($i->load('user'));
            }
        }
    }

    /**
     * @param  list<array{start: Carbon, tz_label: string, tz_stated: bool, ambiguous: bool}>  $aiTimes
     * @return array<string, mixed>
     */
    private function extract(string $text, array $aiTimes, ?Carbon $deadline, ?string $action, Carbon $received, string $ownerTz, MessageClass $class): array
    {
        $slots = $aiTimes ?: $this->times->extract($text, $received, $ownerTz);
        $out = [
            'meeting_url' => $this->classifier->meetingUrl($text),
            'proposed_times' => array_map(fn ($s) => [
                'start_utc' => $s['start']->copy()->utc()->toIso8601String(),
                'tz_label' => $s['tz_label'],
                'tz_label_iana' => $this->ianaFor($s['tz_label'], $ownerTz),
                'tz_stated' => $s['tz_stated'],
                'ambiguous' => $s['ambiguous'],
                'tentative' => true,
            ], in_array($class, [MessageClass::InterviewInvite, MessageClass::Assessment], true) ? $slots : []),
            'deadline_at' => $deadline?->copy()->utc()->toIso8601String(),
            'requested_action' => $action ? Str::limit($action, 160, '') : null,
        ];

        return array_filter($out, fn ($v) => $v !== null && $v !== []);
    }

    private function ianaFor(string $label, string $ownerTz): string
    {
        $clean = trim(str_replace('(assumed)', '', $label));

        return in_array($clean, timezone_identifiers_list(), true) ? $clean : (str_contains($label, 'assumed') ? $ownerTz : 'UTC');
    }

    /**
     * @param  list<int>  $candidateIds
     * @param  Collection<int, Application>  $apps
     * @return array{class: MessageClass, confidence: float, application_id: ?int, times: list<array<string, mixed>>, deadline: ?Carbon, action: ?string}
     *
     * @throws AiException
     */
    private function askAi(array $msg, string $text, array $candidateIds, Collection $apps): array
    {
        $known = $apps->whereIn('id', $candidateIds)->take(5)->map(fn (Application $a) => [
            'id' => $a->id,
            'company' => $this->redactor->redactGeneral((string) $a->job->company),
            'title' => $this->redactor->redactGeneral((string) $a->job->title),
        ])->values()->all();

        $names = array_filter([$msg['__owner_name'] ?? null]);
        $excerpt = $this->redactor->maskNames($this->redactor->redactGeneral(Text::limit($text, 2500)), array_values($names));

        $raw = $this->ai->generateJson(EmailClassificationPrompt::build(
            Str::after((string) $msg['from_email'], '@'),
            $this->redactor->redactGeneral((string) $msg['subject']),
            $excerpt,
            $known,
            (int) crc32((string) $msg['id']) & 0x7FFFFFFF,
        ));

        $ownerTz = config('jobpilot.timezone');
        $times = [];
        foreach ((array) $raw['proposed_times'] as $t) {
            if ($slot = $this->times->fromIso((string) ($t['start_iso'] ?? ''), (string) ($t['timezone'] ?? ''), $ownerTz)) {
                $times[] = $slot;
            }
        }
        $deadline = null;
        if (! empty($raw['deadline_iso'])) {
            $d = $this->times->fromIso((string) $raw['deadline_iso'], '', $ownerTz);
            $deadline = $d['start'] ?? null;
        }
        $id = (int) ($raw['matched_application_id'] ?? 0);

        return [
            'class' => MessageClass::tryFrom((string) $raw['classification']) ?? MessageClass::Uncertain,
            'confidence' => max(0.0, min(1.0, (float) $raw['confidence'])),
            'application_id' => in_array($id, $candidateIds, true) ? $id : null,   // ids the model invented are ignored
            'times' => $times,
            'deadline' => $deadline,
            'action' => isset($raw['requested_action']) ? $this->sanitizer->line((string) $raw['requested_action'], 160) : null,
        ];
    }
}
