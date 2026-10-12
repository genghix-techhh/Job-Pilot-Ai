<?php

namespace App\Services\Profile;

use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\Resume;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProvider;
use App\Services\Ai\Prompts\CvExtractionPrompt;
use App\Services\Ai\Redactor;
use App\Services\AuditLogger;
use App\Services\Matching\SkillCatalog;
use App\Support\Text;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ResumeService
{
    public function __construct(
        private readonly ResumeInspector $inspector,
        private readonly ResumeParser $parser,
        private readonly LocalProfileExtractor $local,
        private readonly ProfileService $profiles,
        private readonly AiProvider $ai,
        private readonly Redactor $redactor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Validate, store privately, parse locally and stage the extracted data for review.
     *
     * @return array{resume: ?Resume, error: ?string}
     */
    public function handleUpload(User $user, UploadedFile $file): array
    {
        $check = $this->inspector->inspect($file);
        if (! $check['ok']) {
            return ['resume' => null, 'error' => $check['error']];
        }

        $ext = $check['extension'];
        $stored = Str::uuid()->toString().'.'.$ext;           // never the user-supplied name
        $relative = "u{$user->id}/{$stored}";
        Storage::disk('resumes')->putFileAs("u{$user->id}", $file, $stored);

        $absolute = Storage::disk('resumes')->path($relative);
        $parsed = $this->parser->parse($absolute, $ext);

        $resume = DB::transaction(function () use ($user, $file, $check, $relative, $absolute, $parsed) {
            $version = (int) Resume::query()->where('user_id', $user->id)->max('version') + 1;
            $makeActive = $parsed['status'] === 'parsed';
            if ($makeActive) {
                Resume::query()->where('user_id', $user->id)->update(['is_active' => false]);
            }

            return Resume::create([
                'user_id' => $user->id,
                'disk_path' => $relative,
                'original_filename' => Text::safeFilename($file->getClientOriginalName()),
                'mime' => $check['mime'],
                'size' => filesize($absolute) ?: $file->getSize(),
                'checksum' => hash_file('sha256', $absolute),
                'parsed_text' => $parsed['text'] !== '' ? $parsed['text'] : null,
                'parse_status' => $parsed['status'],
                'parse_error' => $parsed['error'],
                'version' => $version,
                'is_active' => $makeActive,
            ]);
        });

        $this->audit->record('resume.uploaded', $resume, ['version' => $resume->version, 'status' => $resume->parse_status, 'checksum' => $resume->checksum]);

        if ($resume->parse_status === 'parsed') {
            $this->stageExtraction($user, $resume);
        }

        return ['resume' => $resume, 'error' => null];
    }

    /** Parse the CV into editable profile fields; the owner must review before they are used. */
    public function stageExtraction(User $user, Resume $resume): void
    {
        $profile = $this->profiles->forUser($user);
        $local = $this->local->extract((string) $resume->parsed_text);
        $result = $local;
        $note = 'Parsed locally on this server. No CV text was sent anywhere.';

        if ($this->ai->availability()->available) {
            try {
                $names = array_filter([$local['structured']['name'] ?? '', $user->name]);
                $redacted = $this->redactor->redactCv((string) $resume->parsed_text, array_values($names));
                $ai = $this->ai->generateJson(CvExtractionPrompt::build($redacted));
                $result = $this->mergeAi($local, $ai);
                $note = 'Structured with Gemini from a redacted copy of your CV (name, contact details, links and IDs removed). Contact fields come from local parsing.';
            } catch (AiException $e) {
                $note = 'Parsed locally. AI structuring was skipped: '.$e->getMessage();
            }
        } else {
            $a = $this->ai->availability();
            $note = 'Parsed locally. AI structuring is not active ('.$a->message.')';
        }

        $profile->pending_extraction = [
            'resume_id' => $resume->id,
            'structured' => $result['structured'],
            'skills' => $result['skills'],
            'years_experience' => $result['years_experience'],
            'roles' => $result['roles'],
            'note' => $note,
            'created_at' => now()->toIso8601String(),
        ];
        $profile->save();
    }

    /**
     * @param  array<string, mixed>  $local
     * @param  array<string, mixed>  $ai
     * @return array<string, mixed>
     */
    private function mergeAi(array $local, array $ai): array
    {
        $structured = $local['structured'];
        $structured['headline'] = $ai['headline'] ?? $structured['headline'];
        $structured['summary'] = $ai['summary'] ?? $structured['summary'];
        foreach (['work_history', 'projects', 'education', 'certifications'] as $key) {
            if (! empty($ai[$key])) {
                $structured[$key] = $ai[$key];
            }
        }

        $skills = collect($ai['skills'] ?? [])->map(fn ($s) => SkillCatalog::canonical((string) $s))
            ->merge($local['skills'])->unique(fn ($s) => Str::lower($s))->values()->all();

        $years = isset($ai['years_experience']) && $ai['years_experience'] > 0
            ? (float) $ai['years_experience'] : $local['years_experience'];

        return [
            'structured' => $structured,
            'skills' => $skills,
            'years_experience' => $years,
            'roles' => $local['roles'],
        ];
    }

    public function activate(Resume $resume): void
    {
        abort_unless($resume->parse_status === 'parsed', 422, 'Only a successfully parsed CV can be active.');
        DB::transaction(function () use ($resume) {
            Resume::query()->where('user_id', $resume->user_id)->update(['is_active' => false]);
            $resume->update(['is_active' => true]);
        });
        $this->audit->record('resume.activated', $resume);
    }

    /** Remove a CV file and record. Refuses when applications reference it (they keep an immutable version). */
    public function delete(Resume $resume): bool
    {
        if ($resume->is_active || Application::query()->where('resume_id', $resume->id)->exists()) {
            return false;
        }
        Storage::disk('resumes')->delete($resume->disk_path);
        $this->audit->record('resume.deleted', $resume, ['version' => $resume->version]);
        $resume->delete();

        return true;
    }

    public function pending(CandidateProfile $profile): ?array
    {
        return $profile->pending_extraction ?: null;
    }
}
