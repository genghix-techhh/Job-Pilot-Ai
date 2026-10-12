# Privacy note

JobPilot AI is a private, single-owner application that runs on **your** server. It has no analytics, trackers, third-party scripts or web fonts.

## What is stored locally

| Data | Where | Notes |
|---|---|---|
| CV files | `storage/app/private/resumes` (private disk, `serve=false`) | Random file names; downloaded only through an authenticated, owner-checked route; never reachable by URL. |
| CV text, reviewed profile, profile versions | Database | Versions are immutable snapshots. |
| Jobs, scores, explanations | Database | Public posting data from sources you enabled. |
| Applications, approvals, history | Database | Approval records who, when, exact job URL and SHA-256 of the approved documents. |
| Gmail (optional) | Database | OAuth tokens **encrypted** with `APP_KEY`; job-related mail metadata + 300-char snippets; bodies only if you opt in. |
| Logs | Database / `storage/logs` | AI call counts, status, latency, token counts (**never prompts**); notification delivery status; audit records. CV text, e-mail bodies, tokens and keys are never logged. |

## What the Gemini free tier can receive

Google's terms for the **unpaid** Gemini API state that prompts and responses may be used to improve Google's products and may be reviewed by humans. JobPilot therefore:

1. Parses the CV **locally**.
2. Sends **nothing** unless you gave consent (first-use screen; re-asked when the policy version changes; revocable in Settings).
3. Sends, **once on CV upload**, a *redacted* CV copy: your name, e-mail, phone, links, social handles, ID numbers, birth/family/nationality lines and address lines are removed. Employer, school and project names can still be present in that one copy (they cannot be reliably separated from free text). Decline AI if that is unacceptable — local parsing still works.
4. For matching/drafting sends only skills, years, role titles, experience bullets and project descriptions **without employer/school names**, plus the public job text.
5. For recruiter e-mail classification (only if Gmail is connected and AI consented) sends a short redacted excerpt (no addresses, phones, URLs; your name masked). Meeting links are extracted locally and never sent.
6. Runs a **final privacy scan** on every outgoing prompt and refuses to send if an e-mail address, phone number, ID number, API-key-like string or your name remains.
7. Never sends passwords, API keys, OAuth tokens, your mailbox, Settings, or the original CV file.

Hostile text in job posts or e-mails is treated as data: wrapped in delimiters the text cannot close, answers must match a strict JSON schema, cited evidence must exist in what was sent, free text is sanitised, classification ids must come from the list supplied, and nothing the model returns can trigger an action.

Free tier only: no paid model fallback, no search grounding or other billed tools, no extra keys, no quota workarounds. At the limit AI waits.

## Your controls

- **Settings → Privacy & AI**: turn AI off/on at any time.
- **Inbox**: disconnect & revoke; delete stored message bodies.
- **Settings → Delete my data**: removes profile + versions, CV files, matches, applications and history, interviews, stored e-mail data, notification and AI logs, cached AI results, and disconnects Gmail. Optionally also the fetched jobs. It requires typing `DELETE` and your password.
- **It cannot delete anything held by third parties** — your Gmail messages, your Google AI Studio project or anything Google already received, employers or job boards. Contact them directly for that.

## Security measures

CSRF protection; login throttling (5/min); sessions in the database (set `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`); password hashing; owner-only policies; strict upload validation (extension, real file signature, size, safe names); DOCX parsed as plain XML (no macros/embedded content, XXE/zip-bomb guards); all untrusted text HTML-escaped on output; SSRF-safe fetching; security headers (`X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `X-Robots-Tag: noindex`); secrets only in `.env`, source credentials and OAuth tokens encrypted at rest.
