<?php

namespace App\Http\Controllers;

use App\Jobs\RecalculateMatches;
use App\Services\Ai\GeminiService;
use App\Services\AuditLogger;
use App\Services\Diagnostics\Diagnostics;
use App\Services\Matching\Geo;
use App\Services\Notifications\DigestService;
use App\Services\Notifications\NotificationService;
use App\Services\Privacy\DataDeleter;
use App\Services\Privacy\DataExporter;
use App\Services\Profile\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(Request $request, ProfileService $profiles, Diagnostics $diag): View
    {
        return view('settings.edit', [
            's' => settings()->all(),
            'user' => $request->user(),
            'profile' => $profiles->forUser($request->user()),
            'ai' => $diag->ai(),
            'mail' => $diag->system()['mail'],
            'timezones' => timezone_identifiers_list(),
            'countries' => Geo::countryNames(),
            'weights' => settings()->weights(),
            'privacyVersion' => config('jobpilot.privacy_version'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'target_roles' => ['required', 'string', 'max:1000'],
            'location_preference' => ['required', 'string', 'max:120'],
            'experience_levels' => ['nullable', 'array'],
            'experience_levels.*' => ['in:junior,mid,senior'],
            'home_country' => ['nullable', 'string', 'max:60'],
            'weights' => ['required', 'array'],
            'weights.*' => ['required', 'numeric', 'min:0', 'max:100'],
            'threshold_strong' => ['required', 'integer', 'min:2', 'max:100'],
            'threshold_potential' => ['required', 'integer', 'min:1', 'max:99', 'lt:threshold_strong'],
            'threshold_ai_prefilter' => ['required', 'integer', 'min:0', 'max:100'],
            'scan_frequency_minutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'gemini_daily_limit' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'blocked_companies' => ['nullable', 'string', 'max:2000'],
            'exclude_keywords' => ['nullable', 'string', 'max:2000'],
            'exclude_below_min_salary' => ['nullable', 'boolean'],
            'digest_frequency' => ['required', 'in:off,daily,weekly'],
            'digest_hour' => ['required', 'integer', 'min:0', 'max:23'],
            'digest_max_items' => ['required', 'integer', 'min:1', 'max:30'],
            'followup_days' => ['required', 'integer', 'min:0', 'max:60'],
            'notify_followups' => ['nullable', 'boolean'],
            'notify_strong_matches' => ['nullable', 'boolean'],
            'notify_drafts_awaiting' => ['nullable', 'boolean'],
            'notify_responses' => ['nullable', 'boolean'],
            'notify_interviews' => ['nullable', 'boolean'],
            'notify_deadlines' => ['nullable', 'boolean'],
            'notify_source_failures' => ['nullable', 'boolean'],
            'store_message_bodies' => ['nullable', 'boolean'],
            'show_low_matches' => ['nullable', 'boolean'],
        ], [
            'threshold_potential.lt' => 'The "potential" threshold must be lower than the "strong" threshold.',
        ]);

        $weights = [];
        foreach (array_keys(config('jobpilot.defaults.weights')) as $k) {
            $weights[$k] = (float) ($d['weights'][$k] ?? 0);
        }
        if (array_sum($weights) <= 0) {
            throw ValidationException::withMessages(['weights' => 'At least one scoring weight must be above zero.']);
        }

        $before = settings()->scoringHash();

        settings()->setMany([
            'target_roles' => array_values(array_filter(array_map('trim', preg_split('/[\n,;]+/', $d['target_roles']) ?: []))),
            'location_preference' => trim($d['location_preference']),
            'experience_levels' => array_values($d['experience_levels'] ?? []),
            'home_country' => trim((string) ($d['home_country'] ?? '')),
            'weights' => $weights,
            'threshold_strong' => (int) $d['threshold_strong'],
            'threshold_potential' => (int) $d['threshold_potential'],
            'threshold_ai_prefilter' => (int) $d['threshold_ai_prefilter'],
            'scan_frequency_minutes' => (int) $d['scan_frequency_minutes'],
            'gemini_daily_limit' => isset($d['gemini_daily_limit']) && $d['gemini_daily_limit'] !== null ? (int) $d['gemini_daily_limit'] : null,
            'store_message_bodies' => $request->boolean('store_message_bodies'),
            'show_low_matches' => $request->boolean('show_low_matches'),
            'blocked_companies' => $this->lines($d['blocked_companies'] ?? ''),
            'exclude_keywords' => $this->lines($d['exclude_keywords'] ?? ''),
            'exclude_below_min_salary' => $request->boolean('exclude_below_min_salary'),
            'digest_frequency' => $d['digest_frequency'],
            'digest_hour' => (int) $d['digest_hour'],
            'digest_max_items' => (int) $d['digest_max_items'],
            'followup_days' => (int) $d['followup_days'],
            'notify_followups' => $request->boolean('notify_followups'),
            ...collect(['notify_strong_matches', 'notify_drafts_awaiting', 'notify_responses', 'notify_interviews', 'notify_deadlines', 'notify_source_failures'])
                ->mapWithKeys(fn ($k) => [$k => $request->boolean($k)])->all(),
        ]);

        $rescore = settings()->scoringHash() !== $before;
        if ($rescore) {
            RecalculateMatches::dispatch(notify: false);
        }

        return back()->with('status', 'Settings saved.'.($rescore ? ' Jobs are being re-scored with the new weights or thresholds.' : ''));
    }

    /** @return list<string> unique, trimmed, non-empty entries from comma/line separated text */
    private function lines(string $text): array
    {
        return collect(preg_split('/[\n,;]+/', $text) ?: [])
            ->map(fn ($v) => mb_substr(trim($v), 0, 80))->filter()
            ->unique(fn ($v) => mb_strtolower($v))->values()->take(100)->all();
    }

    public function account(Request $request): RedirectResponse
    {
        $user = $request->user();
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'timezone:all'],
            'notification_email' => ['nullable', 'email', 'max:190'],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'string', 'min:12', 'max:200', 'confirmed'],
        ]);

        $user->fill(['name' => $d['name'], 'timezone' => $d['timezone'], 'notification_email' => $d['notification_email'] ?? null]);
        if (! empty($d['password'])) {
            $user->password = $d['password'];
        }
        $user->save();

        if (! empty($d['password'])) {
            Auth::logoutOtherDevices($d['current_password']);
        }

        return back()->with('status', 'Account saved.');
    }

    public function testEmail(Request $request, NotificationService $notifications): RedirectResponse
    {
        $log = $notifications->test($request->user());

        return $log && $log->status === 'sent'
            ? back()->with('status', 'Test e-mail sent to '.$log->recipient.'. Check your inbox (and spam folder).')
            : back()->with('error', 'The test e-mail could not be sent: '.($log?->last_error ?? 'unknown error').' — check the MAIL_* settings in your .env.');
    }

    public function export(Request $request, DataExporter $exporter): Response
    {
        app(AuditLogger::class)->record('privacy.data_exported');

        return response(
            json_encode($exporter->export($request->user()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            200,
            ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="jobpilot-export-'.now()->format('Ymd').'.json"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']
        );
    }

    public function sendDigest(Request $request, DigestService $digest): RedirectResponse
    {
        $data = $digest->collect($request->user(), now()->subDays(7));
        if ($data['items'] === []) {
            return back()->with('error', 'Nothing to put in a digest: no new, non-excluded, non-restricted matches in the last 7 days.');
        }
        $log = $digest->send($request->user(), force: true, since: now()->subDays(7));

        return $log && $log->status === 'sent'
            ? back()->with('status', 'Digest with '.count($data['items']).' job(s) sent to '.$log->recipient.'.')
            : back()->with('error', 'The digest could not be sent: '.($log?->last_error ?? 'unknown error').' — check the MAIL_* settings.');
    }

    public function testAi(GeminiService $gemini): RedirectResponse
    {
        $a = $gemini->availability();
        if (! $a->available) {
            return back()->with('error', $a->message);
        }
        $r = $gemini->connectivityTest();

        return back()->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    public function consent(Request $request, ProfileService $profiles): RedirectResponse
    {
        $d = $request->validate(['decision' => ['required', 'in:accept,decline']]);
        $profiles->setConsent($profiles->forUser($request->user()), $d['decision'] === 'accept');

        return back()->with('status', $d['decision'] === 'accept' ? 'AI analysis enabled (redacted data only).' : 'AI analysis switched off. Everything else keeps working.');
    }

    public function deleteData(Request $request, DataDeleter $deleter): RedirectResponse
    {
        $d = $request->validate([
            'confirm_text' => ['required', 'in:DELETE'],
            'password' => ['required', 'current_password'],
            'include_jobs' => ['nullable', 'boolean'],
        ], ['confirm_text.in' => 'Type DELETE (in capitals) to confirm.']);

        $counts = $deleter->deleteAll($request->user(), $request->boolean('include_jobs'));

        return redirect()->route('privacy.consent')->with('status', 'Local data deleted ('.collect($counts)->filter()->map(fn ($n, $k) => "$n $k")->implode(', ').'). Nothing at Google or other services was touched.');
    }
}
