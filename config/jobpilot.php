<?php

/*
|--------------------------------------------------------------------------
| JobPilot AI configuration
|--------------------------------------------------------------------------
| Everything here is overridable through .env. Owner-editable preferences
| (roles, weights, thresholds ...) live in the `settings` table; the values
| under `defaults` are only the initial values used until the owner saves.
*/

return [

    'name' => 'JobPilot AI',

    // Display time zone default for the owner. Datetimes are always STORED in UTC.
    'timezone' => env('JOBPILOT_TIMEZONE', 'Asia/Karachi'),

    // Bump when the privacy text / data sent to Gemini changes: forces re-consent.
    'privacy_version' => '2026-10-01',

    // One-time token that enables the optional web owner-setup page (/setup).
    // Leave empty to disable it and create the owner with `php artisan app:create-owner`.
    'setup_token' => env('OWNER_SETUP_TOKEN'),

    // When the queue is "database", the scheduler also drains it every minute so cron alone is enough.
    // Set false if you run a supervised `php artisan queue:work`.
    'scheduler_runs_queue' => filter_var(env('SCHEDULER_RUNS_QUEUE', true), FILTER_VALIDATE_BOOL),

    // Comma separated proxy IPs/CIDRs (or "*") whose X-Forwarded-* headers are trusted, e.g. a reverse proxy.
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    'scan_interval_minutes' => (int) env('SCAN_INTERVAL_MINUTES', 60),
    'job_expiry_days' => (int) env('JOB_EXPIRY_DAYS', 30),
    'source_request_delay_ms' => (int) env('SOURCE_REQUEST_DELAY_MS', 400),
    'max_jobs_per_source' => (int) env('MAX_JOBS_PER_SOURCE', 500),

    // Upper bound of jobs sent to Gemini per scan run (free-tier friendliness).
    'ai_jobs_per_scan' => (int) env('AI_JOBS_PER_SCAN', 8),
    // Seconds between queued AI analyses (free tier has a requests-per-minute limit).
    'ai_job_spacing_seconds' => (int) env('AI_JOB_SPACING_SECONDS', 8),

    'inbox_sync_interval_minutes' => (int) env('INBOX_SYNC_INTERVAL_MINUTES', 30),

    'http' => [
        'timeout' => (int) env('HTTP_TIMEOUT', 15),
        'max_bytes' => (int) env('HTTP_MAX_BYTES', 8 * 1024 * 1024),
        'max_redirects' => 3,
        'user_agent' => env('HTTP_USER_AGENT', 'JobPilotAI/1.0 (personal job-hunting tool; contact owner)'),
    ],

    'uploads' => [
        'max_kb' => (int) env('CV_MAX_KB', 5120),
        'extensions' => ['pdf', 'docx', 'txt'],
        // Max uncompressed size of word/document.xml we are willing to parse (zip-bomb guard).
        'docx_max_xml_bytes' => 12 * 1024 * 1024,
    ],

    'gemini' => [
        'enabled' => filter_var(env('GEMINI_ENABLED', true), FILTER_VALIDATE_BOOL),
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 2048),
        'temperature' => (float) env('GEMINI_TEMPERATURE', 0.2),
        // Local safety guard only. It is NOT a statement about Google's real quota.
        'daily_call_limit' => (int) env('GEMINI_DAILY_CALL_LIMIT', 50),
        'max_retries' => (int) env('GEMINI_MAX_RETRIES', 2),
        // Send responseJsonSchema with requests; falls back to prompt-only JSON when rejected.
        'use_response_schema' => filter_var(env('GEMINI_USE_RESPONSE_SCHEMA', true), FILTER_VALIDATE_BOOL),
        // Models documented with a free tier when this app was written. Anything else
        // shows a warning that free availability must be confirmed in AI Studio.
        'known_free_models' => [
            'gemini-3.5-flash-lite',
            'gemini-3.1-flash-lite',
            'gemini-2.5-flash-lite',
            'gemini-3.8-flash',
            'gemini-3.6-flash',
            'gemini-2.5-flash',
        ],
        // Time zone in which Google resets daily quotas.
        'quota_reset_timezone' => 'America/Los_Angeles',
    ],

    'adzuna' => [
        'app_id' => env('ADZUNA_APP_ID'),
        'app_key' => env('ADZUNA_APP_KEY'),
    ],

    'defaults' => [
        'target_roles' => [
            'Full-stack Developer',
            'AI/ML Developer',
            'Software Engineer',
            'Frontend Developer',
            'Backend Developer',
            'Freelance Projects',
        ],
        'location_preference' => 'Remote worldwide',
        'experience_levels' => ['junior', 'mid', 'senior'],
        'home_country' => '',
        'weights' => [
            'skills' => 30,
            'duties' => 25,
            'seniority' => 15,
            'projects' => 15,
            'salary' => 10,
            'freshness' => 5,
        ],
        'threshold_strong' => 80,
        'threshold_potential' => 65,
        // Rule-based pre-score (0-100) a job must reach before any Gemini call is spent on it.
        'threshold_ai_prefilter' => 40,
        'scan_frequency_minutes' => (int) env('SCAN_INTERVAL_MINUTES', 60),
        'notification_email' => null,
        'notify_strong_matches' => true,
        'notify_drafts_awaiting' => true,
        'notify_responses' => true,
        'notify_interviews' => true,
        'notify_deadlines' => true,
        'notify_source_failures' => true,
        'store_message_bodies' => false,
        'show_low_matches' => false,

        // Owner rules: matching jobs are scored but hidden from the feed, dashboard and alerts.
        'blocked_companies' => [],
        'exclude_keywords' => [],
        'exclude_below_min_salary' => false,

        // E-mail digest of the best new matches: off | daily | weekly (sent at digest_hour in your time zone).
        'digest_frequency' => 'daily',
        'digest_hour' => 8,
        'digest_max_items' => 10,

        // Nudge to follow up when a submitted application has had no reply for this many days (0 = off).
        'followup_days' => 7,
        'notify_followups' => true,
    ],
];
