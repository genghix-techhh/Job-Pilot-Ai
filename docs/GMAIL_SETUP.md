# Optional: Gmail inbox monitoring (read-only)

JobPilot can look for recruiter replies, interview invitations and assessments in your Gmail. It requests **one** permission, `https://www.googleapis.com/auth/gmail.readonly`: it can read mail but **cannot send, reply, accept invitations, label or delete** anything. Skip this whole page if you don't want it — nothing else depends on it.

What it does with a message:

- Only job-related mail is kept (it matched one of your *submitted* applications, or looks like recruiting correspondence). Everything else is ignored and not stored.
- Stored: sender, subject, a 300-character snippet, classification, extracted dates/links, a link to open the original in Gmail. **Bodies are not stored** unless you enable it in Settings (and you can purge them any time).
- Ambiguous mail (could belong to several applications) goes to **Needs review**; you pick the application.
- Interview/assessment times become **tentative** entries — never confirmed automatically. Missing or ambiguous time zones (e.g. "IST", "CST") are flagged.
- With Gemini enabled, a short **redacted** excerpt may be classified by AI; meeting links and your name never leave the server.

## 1. Google Cloud project

1. Go to <https://console.cloud.google.com/> and create a project (e.g. "JobPilot").
2. **APIs & Services → Library → Gmail API → Enable.**

## 2. OAuth consent screen

1. **APIs & Services → OAuth consent screen** → User type **External** → Create.
2. App name, support e-mail, developer e-mail. (No logo/domain needed for personal use.)
3. **Scopes → Add or remove scopes** → add `…/auth/gmail.readonly` (listed as "View your email messages and settings").
4. **Test users → Add users** → add **your own Gmail address**. While the app is in *Testing*, only listed test users can connect.

> In *Testing* mode Google expires refresh tokens after **7 days**, so you'd have to reconnect weekly. For a personal single-user tool you can click **Publish app** (status "In production"); Google shows an "unverified app" warning that you click through for your own account. Sensitive-scope verification is only needed for apps used by other people.

## 3. OAuth client

1. **Credentials → Create credentials → OAuth client ID → Web application.**
2. **Authorised redirect URI:** `https://jobs.example.com/inbox/callback` (your exact `APP_URL` + `/inbox/callback`; for local development `http://127.0.0.1:8000/inbox/callback`).
3. Copy the client ID and secret.

## 4. Configure JobPilot

In the server's `.env`:

```
GOOGLE_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=xxxxxxxx
GOOGLE_REDIRECT_URI=https://jobs.example.com/inbox/callback
```
Run `php artisan config:clear` (or `config:cache`). Open **Inbox Monitoring → Connect Gmail (read-only)**, sign in, tick the Gmail permission, return. Click **Sync now**. The scheduler then checks every `INBOX_SYNC_INTERVAL_MINUTES` (default 30).

Disconnect any time (**Disconnect & revoke**) — the token is revoked at Google and wiped locally. You can also remove access at <https://myaccount.google.com/permissions>.

## Troubleshooting

| Message | Fix |
|---|---|
| `redirect_uri_mismatch` | The URI in Google Cloud must match `GOOGLE_REDIRECT_URI` (or `APP_URL/inbox/callback`) character for character, including `https` and no trailing slash. |
| "Access blocked: app has not completed verification" | Add yourself as a **Test user**, or publish the app (see note above). |
| "Read-only Gmail access was not granted" | On the consent screen the Gmail checkbox was unticked. Connect again and tick it. |
| "Google did not return a refresh token" | Remove JobPilot at myaccount.google.com/permissions, then connect again. |
| "Access was revoked or expired. Reconnect Gmail." | Testing-mode token expiry (7 days) or revoked access → reconnect. |
| 403 "Gmail API has not been used…" | Enable the Gmail API in the same Google Cloud project as the OAuth client. |
| Nothing is detected | Only mail from the last 21 days (first sync) is scanned; it must look like recruiting correspondence or match a **submitted** application. |
