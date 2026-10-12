<?php

namespace App\Services\Privacy;

use App\Models\AiUsageLog;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\ConnectedAccount;
use App\Models\InboundMessage;
use App\Models\Interview;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\NotificationLog;
use App\Models\Resume;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Mail\GmailClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * "Delete my data": removes everything personal that this installation stores locally.
 * It cannot delete anything at Google or anywhere else — the UI says so explicitly.
 */
class DataDeleter
{
    public function __construct(
        private readonly GmailClient $gmail,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, int> what was removed
     */
    public function deleteAll(User $user, bool $includeJobs = false): array
    {
        $counts = [];

        // Revoke Google access first (best effort), then drop the stored tokens.
        foreach (ConnectedAccount::query()->where('user_id', $user->id)->get() as $account) {
            $this->gmail->revoke($account);
        }

        DB::transaction(function () use ($user, $includeJobs, &$counts) {
            $counts['resume_files'] = 0;
            foreach (Resume::query()->where('user_id', $user->id)->get() as $resume) {
                Storage::disk('resumes')->delete($resume->disk_path);
                $counts['resume_files']++;
            }
            Storage::disk('resumes')->deleteDirectory("u{$user->id}");

            $counts['interviews'] = Interview::query()->where('user_id', $user->id)->delete();
            $counts['inbound_messages'] = InboundMessage::query()->whereIn('connected_account_id', ConnectedAccount::query()->where('user_id', $user->id)->select('id'))->delete();
            $counts['connected_accounts'] = ConnectedAccount::query()->where('user_id', $user->id)->delete();
            $counts['applications'] = Application::query()->where('user_id', $user->id)->delete();   // events cascade
            $counts['job_matches'] = JobMatch::query()->where('user_id', $user->id)->delete();
            $counts['resumes'] = Resume::query()->where('user_id', $user->id)->delete();
            $counts['profiles'] = CandidateProfile::query()->where('user_id', $user->id)->delete();  // versions cascade
            $counts['notifications'] = NotificationLog::query()->delete();
            $counts['ai_usage_logs'] = AiUsageLog::query()->delete();
            // AI results cached from redacted prompts are derived from your data too.
            DB::table(config('cache.stores.database.table', 'cache'))->where('key', 'like', '%ai:%')->delete();
            if ($includeJobs) {
                $counts['jobs'] = JobPosting::query()->delete();
            } else {
                JobPosting::query()->update(['ai_requirements' => null, 'ai_requirements_checksum' => null, 'user_status' => 'new']);
            }
        });

        try {
            Cache::flush();
        } catch (\Throwable) {
            // some cache stores cannot be flushed; the targeted deletion above is what matters
        }

        $this->audit->record('privacy.data_deleted', null, ['include_jobs' => $includeJobs, 'counts' => $counts]);

        return $counts;
    }
}
