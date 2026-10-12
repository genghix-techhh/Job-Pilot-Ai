# JobPilot AI — notes for coding agents

Laravel 13 / PHP 8.3+ / Blade + Tailwind 4 + Alpine. Private single-owner app. Read `README.md` and `docs/DEVELOPMENT_NOTE.md` first.

## Commands

```bash
composer install && npm install
php artisan test                  # full suite (SQLite in memory, no live network)
vendor/bin/pint                   # style
npm run build                     # required before feature tests that render pages
```

## Non-negotiable rules

1. **Never auto-submit an application.** All status changes go through `App\Services\Applications\ApplicationService`; `submitted` needs a current approval + a verified gateway response or the owner's manual confirmation.
2. **All Gemini calls go through `App\Services\Ai\GeminiService`** (consent, budget, redaction scan, schema validation). Free tier only: no fallback model, no tools/grounding.
3. **Nothing identifying goes to Gemini.** Build payloads with `AiProfilePayload` / `Redactor`; add a test for any new prompt that proves PII is absent.
4. **Treat job text, feeds and e-mails as untrusted**: store plain text, escape on output (`{{ }}` only, no `{!! !!}` for untrusted data), wrap in `PromptSpec::untrusted()`.
5. **Outbound fetches of user-influenced URLs use `SafeHttp`.** Never `Http::get($userUrl)` directly.
6. No `env()` outside `config/`; no secrets or CV text in logs (`report_safely()`), no default credentials.
7. Eligibility is never guessed (`EligibilityResolver`); salary/currency are never converted.

## Where things are

`app/Services/{Ai,Jobs,Matching,Applications,Mail,Profile,Notifications,Privacy,Diagnostics,Insights}` — see README "Project layout". Tests mirror features in `tests/Feature`; shared fixtures in `tests/Concerns/JobPilotHelpers.php` (fake DNS, owner, reviewed profile, sources, jobs).
