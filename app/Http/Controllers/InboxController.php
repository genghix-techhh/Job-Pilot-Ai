<?php

namespace App\Http\Controllers;

use App\Enums\MessageClass;
use App\Models\Application;
use App\Models\ConnectedAccount;
use App\Models\InboundMessage;
use App\Services\Mail\GmailClient;
use App\Services\Mail\GmailException;
use App\Services\Mail\GmailSyncService;
use App\Services\Mail\InboundProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InboxController extends Controller
{
    public function index(Request $request, GmailClient $gmail): View
    {
        $account = ConnectedAccount::query()->where('user_id', $request->user()->id)->where('provider', 'google')->first();

        $review = InboundMessage::query()->with('application.job')->where('review_status', 'needs_review')->latest('received_at')->limit(50)->get();
        $apps = Application::query()->with('job')->where('user_id', $request->user()->id)->whereNotNull('submitted_at')->latest('submitted_at')->get();

        return view('inbox.index', [
            'configured' => $gmail->isConfigured(),
            'redirectUri' => $gmail->redirectUri(),
            'account' => $account,
            'review' => $review,
            'matched' => InboundMessage::query()->with('application.job')->whereIn('review_status', ['auto', 'confirmed'])->latest('received_at')->limit(30)->get(),
            'apps' => $apps,
            'appsById' => $apps->keyBy('id'),
            'classes' => MessageClass::cases(),
            'storeBodies' => (bool) settings()->get('store_message_bodies'),
            'bodiesStored' => InboundMessage::query()->whereNotNull('body')->count(),
        ]);
    }

    public function connect(Request $request, GmailClient $gmail): RedirectResponse
    {
        if (! $gmail->isConfigured()) {
            return redirect()->route('inbox.index')->with('error', 'Google OAuth is not configured yet. Follow the setup guide below.');
        }
        $state = Str::random(40);
        $request->session()->put('gmail_oauth_state', $state);

        return redirect()->away($gmail->authUrl($state));
    }

    public function callback(Request $request, GmailClient $gmail): RedirectResponse
    {
        $expected = (string) $request->session()->pull('gmail_oauth_state', '');
        $given = (string) $request->query('state', '');
        if ($expected === '' || ! hash_equals($expected, $given)) {
            return redirect()->route('inbox.index')->with('error', 'The Google sign-in could not be verified (state mismatch). Please try connecting again.');
        }
        if ($request->query('error')) {
            return redirect()->route('inbox.index')->with('error', 'Google did not grant access ('.e((string) $request->query('error')).').');
        }
        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect()->route('inbox.index')->with('error', 'Google returned no authorisation code.');
        }

        try {
            $tokens = $gmail->exchangeCode($code);
            $granted = preg_split('/\s+/', $tokens['scope']) ?: [];
            if (! in_array(GmailClient::SCOPE, $granted, true)) {
                return redirect()->route('inbox.index')->with('error', 'Read-only Gmail access was not granted, so inbox monitoring cannot work. Tick the Gmail permission on the consent screen.');
            }
            $profile = $gmail->profile($tokens['access_token']);

            $existing = ConnectedAccount::query()->where('user_id', $request->user()->id)->where('provider', 'google')->first();
            $refresh = $tokens['refresh_token'] ?: $existing?->refresh_token;
            if (! $refresh) {
                return redirect()->route('inbox.index')->with('error', 'Google did not return a refresh token. Remove JobPilot at myaccount.google.com/permissions and connect again.');
            }

            ConnectedAccount::updateOrCreate(
                ['user_id' => $request->user()->id, 'provider' => 'google'],
                [
                    'provider_user_id' => $profile['emailAddress'],
                    'email' => $profile['emailAddress'],
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $refresh,
                    'scopes' => $granted,
                    'expires_at' => now()->addSeconds($tokens['expires_in']),
                    'status' => 'connected',
                    'last_error' => null,
                ]
            );
        } catch (GmailException $e) {
            return redirect()->route('inbox.index')->with('error', $e->getMessage());
        }

        return redirect()->route('inbox.index')->with('status', 'Gmail connected (read-only). Click "Sync now" to check for replies.');
    }

    public function sync(Request $request, GmailSyncService $sync): RedirectResponse
    {
        $account = ConnectedAccount::query()->where('user_id', $request->user()->id)->where('provider', 'google')->where('status', 'connected')->first();
        if (! $account) {
            return back()->with('error', 'Gmail is not connected.');
        }
        set_time_limit(120);
        $r = $sync->sync($account);

        return $r['error']
            ? back()->with('error', 'Sync problem: '.$r['error'])
            : back()->with('status', "Checked {$r['checked']} new message(s); {$r['stored']} job-related, {$r['needs_review']} need your review.");
    }

    public function disconnect(Request $request, GmailClient $gmail): RedirectResponse
    {
        $account = ConnectedAccount::query()->where('user_id', $request->user()->id)->where('provider', 'google')->first();
        if ($account) {
            $gmail->revoke($account);
            $account->forceFill(['access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'status' => 'revoked', 'last_error' => null])->save();
        }

        return back()->with('status', 'Disconnected and access revoked at Google. Stored messages were kept; delete them below if you wish.');
    }

    public function resolve(Request $request, InboundMessage $message, InboundProcessor $processor): RedirectResponse
    {
        $this->authorize('update', $message);
        $d = $request->validate([
            'application_id' => ['nullable', 'integer'],
            'classification' => ['required', 'in:'.implode(',', array_column(MessageClass::cases(), 'value'))],
        ]);

        $app = ! empty($d['application_id'])
            ? Application::query()->where('user_id', $request->user()->id)->findOrFail($d['application_id'])
            : null;
        $class = MessageClass::from($d['classification']);
        if ($class !== MessageClass::Unrelated && ! $app && ! $message->application_id) {
            return back()->with('error', 'Pick the application this message belongs to.');
        }

        if ($class === MessageClass::Unrelated) {
            $message->update(['classification' => $class, 'review_status' => 'dismissed']);

            return back()->with('status', 'Marked as unrelated.');
        }
        $processor->confirm($message, $app, $class);

        return back()->with('status', 'Saved. Any interview or assessment found in it was added as tentative.');
    }

    public function dismiss(InboundMessage $message): RedirectResponse
    {
        $this->authorize('update', $message);
        $message->update(['review_status' => 'dismissed']);

        return back()->with('status', 'Dismissed.');
    }

    public function purgeBodies(): RedirectResponse
    {
        $n = InboundMessage::query()->whereNotNull('body')->update(['body' => null]);

        return back()->with('status', "Deleted stored message bodies from {$n} message(s). Snippets and metadata remain.");
    }
}
