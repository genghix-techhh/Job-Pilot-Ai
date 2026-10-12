<?php

namespace App\Services\Privacy;

use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\Interview;
use App\Models\JobMatch;
use App\Models\JobSource;
use App\Models\Resume;
use App\Models\SavedSearch;
use App\Models\User;

/**
 * "Export my data": a portable JSON copy of everything personal you entered or generated, for backup or
 * to move elsewhere. It deliberately EXCLUDES secrets: API keys, OAuth tokens, source credentials,
 * password hashes, and the CV files themselves (download those from CV & Profile).
 */
class DataExporter
{
    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        $profile = CandidateProfile::query()->with('versions')->where('user_id', $user->id)->first();

        return [
            'exported_at' => now()->toIso8601String(),
            'format' => 'jobpilot-export-1',
            'note' => 'Secrets (API keys, OAuth tokens, source credentials, password hash) and CV files are not included.',
            'account' => ['name' => $user->name, 'email' => $user->email, 'timezone' => $user->timezone, 'notification_email' => $user->notification_email],
            'settings' => settings()->all(),
            'profile' => $profile ? [
                'version' => $profile->profile_version,
                'roles' => $profile->roles, 'skills' => $profile->skills, 'years_experience' => $profile->years_experience,
                'experience_levels' => $profile->experience_levels, 'remote_preferences' => $profile->remote_preferences,
                'salary_preferences' => $profile->salary_preferences, 'employment_types' => $profile->employment_types,
                'structured' => $profile->structured,
                'ai_consent' => $profile->ai_consent, 'privacy_consent_at' => $profile->privacy_consent_at?->toIso8601String(),
                'versions' => $profile->versions->map(fn ($v) => ['version' => $v->version, 'reason' => $v->reason, 'created_at' => $v->created_at?->toIso8601String(), 'data' => $v->data])->all(),
            ] : null,
            'resumes' => Resume::query()->where('user_id', $user->id)->orderBy('version')->get()->map(fn (Resume $r) => [
                'version' => $r->version, 'filename' => $r->original_filename, 'sha256' => $r->checksum, 'size' => $r->size,
                'active' => $r->is_active, 'uploaded_at' => $r->created_at?->toIso8601String(), 'parsed_text' => $r->parsed_text,
            ])->all(),
            'saved_searches' => SavedSearch::query()->where('user_id', $user->id)->get(['name', 'filters', 'digest'])->toArray(),
            'sources' => JobSource::query()->get()->map(fn (JobSource $s) => ['type' => $s->type->value, 'name' => $s->name, 'target' => $s->target(), 'enabled' => $s->enabled])->all(),
            'applications' => Application::query()->with(['job', 'events', 'interviews'])->where('user_id', $user->id)->get()->map(fn (Application $a) => [
                'job' => ['title' => $a->job->title, 'company' => $a->job->company, 'url' => $a->job->url, 'location' => $a->job->location],
                'type' => $a->type, 'status' => $a->status->value, 'created_at' => $a->created_at?->toIso8601String(),
                'approved_at' => $a->approved_at?->toIso8601String(), 'approved_url' => $a->approved_url, 'approved_hashes' => $a->approved_hashes,
                'submitted_at' => $a->submitted_at?->toIso8601String(), 'submission_method' => $a->submission_method, 'external_reference' => $a->external_reference,
                'cover_letter' => $a->cover_letter, 'tailored_resume' => $a->tailored_resume, 'screening_answers' => $a->screening_answers,
                'notes' => $a->notes, 'next_task' => $a->next_task,
                'events' => $a->events->map(fn ($e) => ['type' => $e->type, 'from' => $e->from_status, 'to' => $e->to_status, 'actor' => $e->actor, 'at' => $e->occurred_at?->toIso8601String()])->all(),
                'interviews' => $a->interviews->map(fn ($i) => ['title' => $i->title, 'kind' => $i->kind, 'state' => $i->state, 'starts_at' => $i->starts_at?->toIso8601String(), 'timezone' => $i->source_timezone, 'meeting_url' => $i->meeting_url])->all(),
            ])->all(),
            'interviews_without_application' => Interview::query()->where('user_id', $user->id)->whereNull('application_id')->get()
                ->map(fn ($i) => ['title' => $i->title, 'kind' => $i->kind, 'state' => $i->state, 'starts_at' => $i->starts_at?->toIso8601String(), 'timezone' => $i->source_timezone])->all(),
            'match_scores' => JobMatch::query()->with('job:id,title,company,url')->where('user_id', $user->id)->orderByDesc('score')->limit(500)->get()
                ->map(fn ($m) => ['title' => $m->job?->title, 'company' => $m->job?->company, 'url' => $m->job?->url, 'score' => $m->score, 'tier' => $m->tier->value, 'eligibility' => $m->eligibility_status->value, 'exclusion' => $m->exclusion])->all(),
        ];
    }
}
