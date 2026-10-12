# JobPilot AI

A **private, single-owner** job-hunting assistant. It discovers remote jobs and freelance projects from sources that permit it, scores them against *your* CV, prepares truthful application drafts, **waits for your approval**, tracks every application, watches your inbox for recruiter replies (optional) and e-mails you about what matters.

Built with Laravel 13, Blade + Tailwind + Alpine, MySQL, and the **Google Gemini API free tier** (optional).

> **The rule this app is built around:** it never submits an application by itself. Every submission needs your explicit approval, and then either an *authorised* integration (none ships by default) or you applying on the employer's own page and confirming it here.

---

## What is automatic — and what is not

| Automatic | Needs you | Intentionally NOT included |
|---|---|---|
| Fetching jobs from the sources you added (Greenhouse, Lever, Remote OK, RSS/Atom, optional Adzuna) | Reviewing the parsed CV before it is used | Scraping LinkedIn / Indeed (hidden APIs, cookies, bots, CAPTCHA tricks) |
| De-duplication, expiry, rule-based scoring and eligibility flags | Clicking **Create draft** for a job | Auto-applying on LinkedIn, Indeed, Upwork, Fiverr |
| Optional Gemini analysis of the best candidates (redacted data only) | Editing and **approving** a draft | Submitting through Greenhouse/Lever/Remote OK public APIs (they need the *employer's* credentials) |
| E-mail alerts, reminders, recruiter-mail detection (Gmail, optional) | Applying on the employer's page and clicking **I submitted this** | WhatsApp / SMS, billing, teams, mobile apps, paid job-data plans |

Restricted sites are handled with **Add a job link** (paste the posting → score → draft) and **Open official application page**.

---

## Features

- **Dashboard** with real numbers only (no fake data), source health, Gemini quota/errors, queue/scheduler warnings.
- **Job Feed** (cards/list, search, role/source/date/score/eligibility/type/status filters, sort, pagination), **saved searches** (one click to re-apply; optionally included in the digest) and **bulk actions** (save / hide many at once).
- **Smarter matching (v2)**: requirements are split into *required* vs *nice to have*; title skills count double; a TF-IDF text-similarity signal and a title-similarity signal back up the skill match (the TF-IDF part switches on once ≥ 25 jobs are stored). Eligibility is still never guessed.
- **Exclusion rules**: blocked companies, excluded keywords (`unpaid`, or `title:manager` to look at the job title only), and "below my minimum salary". Excluded jobs are still scored but hidden from the feed, dashboard and alerts (Feed → *show excluded* to see them and why).
- **Opportunity detail**: original link, source attribution, factor-by-factor score, matched/missing skills, eligibility note, freelance-fit panel.
- **Daily digest + follow-up nudges**: one e-mail with your best new matches (`app:send-digest`, default daily 08:00 in your time zone, off/weekly in Settings) and a nudge when an application has had no reply for N days — with a suggested, truthful follow-up message you copy yourself.
- **Insights**: funnel, response rate and median reply time, performance by source, score distribution, weekly activity, skills in demand vs. skills you lack, salary quartiles, eligibility mix — all computed from your own data.
- **Interview prep**: per-application prep sheet (likely questions, evidence from *your* profile for each, gaps to prepare, questions to ask). Local by default; optional Gemini refinement whose answers must cite real profile evidence.
- **Export my data** (JSON, no secrets and no CV files) from Settings.
- **CV & Profile**: private upload (PDF, DOCX, TXT), local parsing, full review/edit form, immutable profile versions.
- **Application Studio**: cover letter / proposal, tailored CV text, screening answers, evidence panel, approval gate, TXT/HTML/print/**PDF** export, clear "NOT SUBMITTED" state.
- **Applications tracker** (table + board), full event history.
- **Interviews & follow-ups** with explicit time zones and `.ics` download.
- **Inbox monitoring** (optional Gmail, read-only) with a "needs review" queue.
- **Sources**, **Settings** (roles, weights, thresholds, AI guardrails, notifications, account), **Activity & Diagnostics**, **Delete my data**, **Privacy** page.

---

## Requirements

- PHP **8.3+** (developed on 8.4) with: `curl`, `dom`, `mbstring`, `openssl`, `pdo_mysql` (or `pdo_sqlite` for local), `zip`, `xml`, `fileinfo`, `tokenizer`, `ctype`, `json`
- Composer 2, Node 20+ (only to build the front end)
- MySQL 8 / MariaDB 10.6+ for production (SQLite works for local development and the test-suite)
- No Redis, no always-on worker required (a database queue + one cron line is enough; a supervised worker is optional)

## Local setup (clone → login)

```bash
composer install
cp .env.example .env
php artisan key:generate
# local development: use SQLite and run jobs inline
#   DB_CONNECTION=sqlite  (and: touch database/database.sqlite)   QUEUE_CONNECTION=sync   APP_ENV=local   APP_DEBUG=true
php artisan migrate
npm install
npm run build            # or `npm run dev` while developing
php artisan app:create-owner      # interactive; asks for name, e-mail, password (12+ chars)
php artisan serve
```

Open <http://127.0.0.1:8000/login>. On first sign-in you see the **privacy choice** screen, then the dashboard checklist walks you through: upload CV → review profile → add a source → set your country.

### Creating the owner safely

- There is **no registration page** and **no default password**.
- `php artisan app:create-owner` works only while no user exists. The password is typed interactively (never a command-line argument). For scripted installs set the `JOBPILOT_OWNER_PASSWORD` environment variable for that one command.
- Optional browser setup: set `OWNER_SETUP_TOKEN` in `.env`, visit `/setup`, enter the token. The page closes permanently once an owner exists — **remove the token afterwards**.
- Forgot the password? `php artisan app:reset-owner-password` (there is deliberately no e-mail reset flow).

## Configuration (`.env`)

See [`.env.example`](.env.example). Highlights:

| Variable | Purpose |
|---|---|
| `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` | Standard Laravel. **Never** enable debug in production. `APP_KEY` also encrypts stored OAuth tokens and source secrets — back it up. |
| `DB_*` | MySQL connection. |
| `QUEUE_CONNECTION=database` | Default. `sync` runs everything inline (fine locally). |
| `SCHEDULER_RUNS_QUEUE` | `true` (default): the cron scheduler also drains the queue each minute. Set `false` if you run a supervised `queue:work`. |
| `GEMINI_API_KEY`, `GEMINI_MODEL=gemini-3.5-flash-lite`, `GEMINI_ENABLED`, `GEMINI_DAILY_CALL_LIMIT=50`, `GEMINI_TIMEOUT=30` | Gemini free tier. The daily limit is a *local* guard, not Google's quota. |
| `MAIL_*` | SMTP for alerts. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | Optional Gmail monitoring. Blank = feature shows "Not connected". |
| `ADZUNA_APP_ID`, `ADZUNA_APP_KEY` | Optional. The Adzuna connector stays off until you also confirm permitted use in the UI. |
| `SCAN_INTERVAL_MINUTES`, `JOB_EXPIRY_DAYS`, `SOURCE_REQUEST_DELAY_MS`, `AI_JOBS_PER_SCAN` | Discovery pacing. |
| `JOBPILOT_TIMEZONE=Asia/Karachi` | Default display zone. Datetimes are **stored in UTC** and shown in the owner's selected zone. |
| `TRUSTED_PROXIES` | Only if you run behind a reverse proxy that sets `X-Forwarded-*`. |

Secrets live in `.env` / hosting settings only — never in the repository (`.env` is git-ignored).

## Gemini (free tier)

1. Create a key in **Google AI Studio** (<https://aistudio.google.com/apikey>), using a Google project **without billing enabled**.
2. Put it in the server's `.env` as `GEMINI_API_KEY=...` (never in chat, never in JavaScript), then `php artisan config:clear` (or `config:cache`).
3. Test **without spending paid credits**: Settings → *Test Gemini connection* or `php artisan app:test-gemini`. It makes **one** tiny call to the configured model only — no fallback model, no search grounding, no billed tools.

**Privacy, plainly:** Google's terms for the *unpaid* Gemini API say prompts and responses may be used to improve Google products and may be reviewed by humans. Therefore:

- The CV is parsed **locally**. A redacted copy (no name, contact details, links, IDs, birth/family/address lines) is sent **once**, only if you consented, to structure it. That copy can still contain employer/school/project names; decline AI if that matters — local parsing then handles everything.
- Matching and drafting send only skills, years, role titles, experience bullets and project descriptions **without** employer/school names, plus public job text. A final scan blocks any request that still contains an e-mail, phone number, ID number or your name.
- Job posts and e-mails are treated as **untrusted data** (delimited, schema-validated answers, evidence must exist in what was sent, AI output is sanitised, AI can never take an action).
- Declining consent keeps everything except Gemini working. Details: [`docs/PRIVACY.md`](docs/PRIVACY.md) and the in-app **Privacy** page.

If the free quota is hit the app **pauses AI**, keeps collecting jobs, preserves queued work and shows the retry time. It never switches to a paid model, never uses extra keys.

## Adding job sources

Sources page → *Add a source*. See [`docs/SOURCES.md`](docs/SOURCES.md).

- **Greenhouse** — board token (`boards.greenhouse.io/<token>`), one source per company.
- **Lever** — site name (`jobs.lever.co/<site>`), global or EU region.
- **Remote OK** — the public feed; listings keep a link back to Remote OK as their terms require; fetched at most every 2 h.
- **RSS / Atom** — any public feed you add; private/internal addresses are refused (SSRF-safe).
- **Adzuna** (optional) — needs your own credentials *and* your confirmation of permitted use.
- **Manual** — *Add a job link*; the URL is stored, never crawled.

## E-mail alerts (SMTP)

Set `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` (port 465 → `MAIL_SCHEME=smtps`; port 587 uses STARTTLS). Then Settings → **Send test e-mail**. Delivery status and errors (secrets scrubbed) appear under *Activity & Diagnostics*; failed alerts are retried a bounded number of times. Alerts are de-duplicated per event.

## Optional Gmail inbox monitoring

Read-only (`gmail.readonly`), tokens encrypted at rest, disconnect = revoke at Google. Step-by-step Google Cloud setup (consent screen, test user, redirect URI, troubleshooting): [`docs/GMAIL_SETUP.md`](docs/GMAIL_SETUP.md).

## Commands

| Command | What it does |
|---|---|
| `php artisan app:create-owner` | Create the owner (only if none exists). |
| `php artisan app:reset-owner-password` | Set a new password; signs out all sessions. |
| `php artisan app:sync-job-sources [id] [--due] [--force]` | Fetch sources. Exit 1 only if *every* attempted source failed. |
| `php artisan app:sync-inbox` | Check Gmail (no-op if not connected). |
| `php artisan app:recalculate-matches [--force] [--pending-ai]` | Re-score (free) or queue postponed AI analyses. |
| `php artisan app:expire-jobs` | Expire stale jobs. |
| `php artisan app:send-reminders` | Interview reminders, follow-up nudges + bounded retry of failed alerts. |
| `php artisan app:send-digest [--force]` | Send the job digest if one is due (hourly in the scheduler); `--force` sends now. |
| `php artisan app:test-gemini` | One-call connectivity test. |

## Deployment

Hostinger **VPS** (Ubuntu + nginx + PHP-FPM + MySQL + cron/Supervisor): see [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md). Short version: document root → `public/`, `APP_DEBUG=false`, HTTPS, `composer install --no-dev -o`, `npm ci && npm run build`, `php artisan migrate --force`, `php artisan optimize`, **one cron line** `* * * * * php /path/artisan schedule:run`, back up the database and `.env`/`APP_KEY`.

If cron is unavailable, the app still works: the dashboard and Sources page have **Run discovery now**, Inbox has **Sync now**.

## Tests

```bash
php artisan test            # 298 tests; no live Gemini/Google/SMTP calls
vendor/bin/pint             # code style
```

Covered: owner auth & no registration; CV validation, private storage, parsing failures; connector normalisation (mocked HTTP) and isolation of failing sources; de-duplication and freshness; scoring weights, redistribution and eligibility (never guessed); Gemini errors/quota/timeout/budget/cache/JSON repair; redaction and prompt-injection handling; approval enforced server-side, "opened ≠ submitted", idempotent submission; Gmail unconfigured/OAuth/dedupe/ambiguity/time-zones; notification dedupe and failure/retry; SSRF protection (private IPv4/IPv6, bad schemes, unsafe redirects, size cap); dashboard, filters, tracker, data deletion; matching v2 (required vs preferred, TF-IDF, exclusion rules), saved searches and bulk actions, digest and follow-up nudges, Insights, PDF export, interview prep, data export.

## Known limitations

- **No OCR**: scanned/image-only PDFs are reported, not read.
- **No real submission integration** ships; applying is manual by design.
- Free-tier Gemini quota is small and Google-controlled; the app waits rather than overspends. Rule-based scoring (always on) is conservative — expect few "strong" matches until your profile is rich and AI is enabled.
- Remote OK's feed is limited to recent listings; Greenhouse/Lever need one source per company; Adzuna returns snippets only.
- Phone calls cannot be detected from e-mail — add them under Interviews.
- Verified live against Remote OK, Greenhouse and Lever. **Not verified in this build environment:** MySQL (tests run on SQLite), live Gemini calls, live Gmail OAuth, real SMTP delivery, Adzuna with real credentials — each is covered by mocked tests only; follow the verification steps in [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md#verify-after-deploying).

## Project layout

```
app/Services/Ai          Gemini service (single choke point), redaction, budget, prompts, schema validation
app/Services/Jobs        connectors, SSRF-safe HTTP (Services/Http), upserter, sync orchestration
app/Services/Matching    scorer, eligibility, skill catalogue, freelance fit, match service
app/Services/Applications  approval-first workflow, drafts, PDF/HTML rendering, follow-ups, interview prep, submission-gateway seam
app/Services/Insights    analytics for the Insights page
app/Services/Notifications  alerts, digest
app/Services/Mail        Gmail client/sync, classifier, time extraction
app/Services/Profile     CV parsing, local extraction, versions, redacted AI payload
docs/                    development note, deployment, sources, Gmail, privacy
tests/                   Feature + Unit (298 tests)
```
