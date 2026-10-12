# Job-source integration guide

Every connector implements `App\Services\Jobs\Connectors\SourceConnector`
(`validateConfig()`, `fetchJobs()`, `normalizeJob()`, `healthCheck()`, `minIntervalMinutes()`, `isFullListing()`).
Connectors only **read**. A failure in one source is recorded on that source and never stops the others.

All outbound requests go through `SafeHttp`: http/https only, ports 80/443, no credentials in URLs, every resolved IP must be public (loopback/private/link-local/CGNAT/reserved and IPv4-mapped forms are refused), the connection is pinned to the validated IP, redirects are followed manually (max 3) and re-validated at each hop, 15 s timeout, 8 MB cap.

## Greenhouse (public Job Board API)

- Endpoint: `GET https://boards-api.greenhouse.io/v1/boards/{token}/jobs?content=true` (no authentication for reads).
- **Add one source per company.** The token is the last part of `boards.greenhouse.io/<token>` or `job-boards.greenhouse.io/<token>`. There is no global discovery across all Greenhouse employers.
- Descriptions arrive HTML-entity-encoded; they are decoded and reduced to plain text.
- Applying through Greenhouse's API requires the **employer's** API key, so JobPilot never submits — use *Open official application page*.
- Wrong token → clear "Not found (HTTP 404)" error on the source.

## Lever (public Postings API)

- Endpoint: `GET https://api.lever.co/v0/postings/{site}?mode=json&limit=100&skip=N` (EU: `api.eu.lever.co`).
- Add one source per company site (`jobs.lever.co/<site>`). Pages of 100, max 6 pages.
- Lever's `country` and `workplaceType` are used for the remote/eligibility hints.
- Same rule as Greenhouse: no candidate-side submission without the employer's key.

## Remote OK

- Endpoint: `GET https://remoteok.com/api`. The first array element is a legal notice and is skipped.
- Their terms require linking back to the Remote OK listing and naming Remote OK as the source: the job's primary link is always the Remote OK URL and the UI shows "via Remote OK".
- Fetched at most every **2 hours**; optional keyword filter (title/tags).
- Their JSON often contains double-encoded text (`â\u0080\u0099`); JobPilot repairs it only when the result is clean UTF-8.

## RSS / Atom / RDF

- Owner-added URLs only. The URL is validated when saved **and** on every fetch.
- ETag / Last-Modified conditional requests; the feed's `<ttl>` extends the polling interval.
- XML with entity declarations is refused (XML-bomb/XXE hardening), 3 MB cap.
- Title patterns like `Acme: Laravel Developer` or `PHP Dev at Acme` are split into company + title; otherwise the *Default company* field is used.
- Mark a feed as *Freelance projects* to get freelance analysis.

## Adzuna (optional)

- Disabled until **you** supply an app id/key (stored encrypted) **and** tick that you checked Adzuna's terms and quota for your use. No free quota is assumed.
- Returns snippets and a redirect URL; the app shows how many requests it has made today so you can compare with Adzuna's dashboard. Polled at most every 6 hours, 1–3 pages.

## Manual import / bookmark

*Add a job link*: store a URL (never fetched) with optional pasted text. Without a title and description the job is saved as **needs details** and is not scored until completed.

## Normalised fields and de-duplication

Every record becomes: source, external id, canonical URL, company, title, plain-text description, location, remote flag/scope, eligible countries/regions, employment type, salary (min/max/currency/period), posted/updated/expires, first/last seen, skills, fingerprint, state.

Identity: `(source_id, external_id)` when the source has stable ids (unique index); otherwise `sha256(canonical URL | company | title)` within the source. **Title alone is never used.** The same posting from two sources is linked (`duplicate_of`) and hidden from the feed, not deleted. Jobs missing from a *full-listing* source (Greenhouse, Lever) are expired immediately; other jobs expire after `JOB_EXPIRY_DAYS` unseen.

## Not supported on purpose

LinkedIn, Indeed (no scraping, hidden APIs, cookies, login bots or CAPTCHA evasion) and Upwork/Fiverr (no automation). For these: open the site, use **Add a job link**, draft here, apply there.

## Adding a new connector

1. Create `app/Services/Jobs/Connectors/YourConnector.php` extending `AbstractConnector` (use `$this->get()` / `getJson()` so SSRF, size, timeout and error mapping apply).
2. Add a case to `App\Enums\SourceType` and register it in `ConnectorRegistry::MAP`.
3. Add a field block to `resources/views/sources/index.blade.php`.
4. Test with `Http::fake()` (see `tests/Feature/ConnectorsTest.php`).
