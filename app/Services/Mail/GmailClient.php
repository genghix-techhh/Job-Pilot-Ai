<?php

namespace App\Services\Mail;

use App\Models\ConnectedAccount;
use App\Services\Ai\Redactor;
use App\Support\Text;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Thin Google OAuth + Gmail REST client. Requests the single read-only scope
 * (gmail.readonly): the app can read mail but cannot send, delete, label or reply.
 */
class GmailClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const REVOKE = 'https://oauth2.googleapis.com/revoke';

    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled($this->redirectUri());
    }

    public function redirectUri(): string
    {
        return (string) (config('services.google.redirect') ?: url('/inbox/callback'));
    }

    public function authUrl(string $state): string
    {
        return self::AUTH.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
        ]);
    }

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int, scope: string} */
    public function exchangeCode(string $code): array
    {
        $r = $this->post(self::TOKEN, [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        if (! $r->successful()) {
            throw new GmailException('Google rejected the authorisation code ('.$this->reason($r).'). Try connecting again.');
        }

        return [
            'access_token' => (string) $r->json('access_token'),
            'refresh_token' => $r->json('refresh_token'),
            'expires_in' => (int) $r->json('expires_in', 3600),
            'scope' => (string) $r->json('scope', ''),
        ];
    }

    /** Returns a usable access token, refreshing (and persisting) it when it is about to expire. */
    public function freshToken(ConnectedAccount $account): string
    {
        if ($account->access_token && $account->expires_at && $account->expires_at->gt(now()->addSeconds(60))) {
            return $account->access_token;
        }
        if (! $account->refresh_token) {
            throw new GmailException('Google did not provide a refresh token. Disconnect and connect again.', true);
        }

        $r = $this->post(self::TOKEN, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $account->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        if (! $r->successful()) {
            $invalid = $r->json('error') === 'invalid_grant';
            $account->forceFill(['status' => $invalid ? 'revoked' : 'error', 'last_error' => $invalid ? 'Access was revoked or expired. Reconnect Gmail.' : 'Token refresh failed ('.$this->reason($r).').'])->save();
            throw new GmailException($account->last_error, $invalid);
        }

        $account->forceFill([
            'access_token' => (string) $r->json('access_token'),
            'expires_at' => now()->addSeconds((int) $r->json('expires_in', 3600)),
            'status' => 'connected',
            'last_error' => null,
        ])->save();

        return $account->access_token;
    }

    public function revoke(ConnectedAccount $account): void
    {
        $token = $account->refresh_token ?: $account->access_token;
        if (! $token) {
            return;
        }
        try {
            Http::asForm()->timeout(10)->post(self::REVOKE, ['token' => $token]);
        } catch (\Throwable $e) {
            report_safely($e, 'google revoke');
        }
    }

    /** @return array{emailAddress: string, historyId: ?string} */
    public function profile(string $token): array
    {
        $r = $this->get($token, '/profile');

        return ['emailAddress' => (string) $r->json('emailAddress'), 'historyId' => $r->json('historyId')];
    }

    /** @return list<string> message ids */
    public function search(string $token, string $query, int $max = 50): array
    {
        $r = $this->get($token, '/messages', ['q' => $query, 'maxResults' => $max]);

        return array_values(array_map(fn ($m) => (string) $m['id'], (array) $r->json('messages', [])));
    }

    /**
     * Headers + snippet only (cheap). @return array<string, mixed>
     */
    public function metadata(string $token, string $id): array
    {
        $r = $this->get($token, "/messages/{$id}", [
            'format' => 'metadata',
            'metadataHeaders' => ['From', 'Subject', 'Date', 'Message-ID'],
        ]);

        return $this->normalize((array) $r->json());
    }

    /** Full message including the text body (only requested for relevant mail). @return array<string, mixed> */
    public function full(string $token, string $id): array
    {
        $r = $this->get($token, "/messages/{$id}", ['format' => 'full']);
        $data = (array) $r->json();
        $out = $this->normalize($data);
        $out['body'] = $this->extractText((array) ($data['payload'] ?? []));

        return $out;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalize(array $data): array
    {
        $headers = [];
        foreach ((array) ($data['payload']['headers'] ?? []) as $h) {
            $headers[strtolower((string) $h['name'])] = (string) $h['value'];
        }
        $from = $headers['from'] ?? '';
        $email = preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : trim($from);
        $name = trim(preg_replace('/<[^>]+>/', '', $from) ?? '', " \t\"'");

        return [
            'id' => (string) ($data['id'] ?? ''),
            'thread_id' => (string) ($data['threadId'] ?? ''),
            'from_email' => strtolower($email),
            'from_name' => $name,
            'subject' => $headers['subject'] ?? '',
            'snippet' => html_entity_decode((string) ($data['snippet'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'received_at' => isset($data['internalDate']) ? Carbon::createFromTimestampMsUTC((int) $data['internalDate']) : null,
            'labels' => (array) ($data['labelIds'] ?? []),
        ];
    }

    /** Prefer text/plain; fall back to stripped HTML. @param array<string, mixed> $payload */
    private function extractText(array $payload): string
    {
        $plain = $html = '';
        $walk = function (array $part) use (&$walk, &$plain, &$html) {
            $mime = (string) ($part['mimeType'] ?? '');
            $data = $part['body']['data'] ?? null;
            if ($data && in_array($mime, ['text/plain', 'text/html'], true)) {
                $text = base64_decode(strtr($data, '-_', '+/')) ?: '';
                $mime === 'text/plain' ? $plain .= "\n".$text : $html .= "\n".$text;
            }
            foreach ((array) ($part['parts'] ?? []) as $child) {
                $walk($child);
            }
        };
        $walk($payload);

        $text = trim($plain) !== '' ? $plain : Text::htmlToText($html);

        return Text::limit(Text::normalizeWhitespace($text), 20000);
    }

    public function webLink(string $messageId): string
    {
        return 'https://mail.google.com/mail/u/0/#all/'.rawurlencode($messageId);
    }

    // ---- http -------------------------------------------------------------------------------

    private function http(): PendingRequest
    {
        return Http::timeout(20)->connectTimeout(8)->retry(2, 400, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    /** @param array<string, mixed> $form */
    private function post(string $url, array $form): Response
    {
        try {
            return $this->http()->asForm()->post($url, $form);
        } catch (ConnectionException $e) {
            throw new GmailException('Could not reach Google ('.Redactor::scrubSecrets(class_basename($e)).').');
        }
    }

    /** @param array<string, mixed> $query */
    private function get(string $token, string $path, array $query = []): Response
    {
        try {
            $r = $this->http()->withToken($token)->get(self::API.$path, $query);
        } catch (ConnectionException) {
            throw new GmailException('Could not reach the Gmail API.');
        }
        if ($r->status() === 401) {
            throw new GmailException('Gmail rejected the access token. Reconnect Gmail.', true);
        }
        if ($r->status() === 403) {
            throw new GmailException('Gmail access was denied ('.$this->reason($r).'). Is the Gmail API enabled in your Google Cloud project?', false);
        }
        if ($r->status() === 429) {
            throw new GmailException('Gmail API rate limit reached; will retry on the next sync.');
        }
        if (! $r->successful()) {
            throw new GmailException('Gmail API error (HTTP '.$r->status().').');
        }

        return $r;
    }

    private function reason(Response $r): string
    {
        return Redactor::scrubSecrets((string) ($r->json('error_description') ?: $r->json('error.message') ?: $r->json('error') ?: 'HTTP '.$r->status()));
    }
}
