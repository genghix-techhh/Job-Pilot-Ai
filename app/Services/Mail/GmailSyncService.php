<?php

namespace App\Services\Mail;

use App\Models\Application;
use App\Models\ConnectedAccount;
use App\Models\InboundMessage;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Polls Gmail (read-only), keeps only job-related mail and hands it to InboundProcessor.
 * Mail that is neither linked to an application nor looks like recruiting correspondence is
 * never stored; its id is remembered in the cache only so it is not downloaded again.
 */
class GmailSyncService
{
    private const MAX_PER_RUN = 60;

    public function __construct(
        private readonly GmailClient $gmail,
        private readonly InboundProcessor $processor,
        private readonly InboundClassifier $classifier,
        private readonly ApplicationMatcher $matcher,
    ) {}

    /**
     * @return array{checked: int, stored: int, needs_review: int, error: ?string}
     */
    public function sync(ConnectedAccount $account): array
    {
        $result = ['checked' => 0, 'stored' => 0, 'needs_review' => 0, 'error' => null];

        if (! $this->gmail->isConfigured()) {
            $result['error'] = 'Google OAuth is not configured.';

            return $result;
        }

        try {
            $token = $this->gmail->freshToken($account);

            // First sync looks back 21 days; later syncs only since the last one (with a day of overlap).
            $days = $account->last_synced_at ? max(1, (int) ceil($account->last_synced_at->diffInDays(now()) + 1)) : 21;
            $query = "newer_than:{$days}d -from:me -category:promotions -category:social -in:chats";
            $ids = $this->gmail->search($token, $query, self::MAX_PER_RUN);

            $apps = Application::query()->with(['job', 'user'])->whereNotNull('submitted_at')->latest('submitted_at')->limit(300)->get();

            foreach ($ids as $id) {
                if (Cache::has("gmail:seen:{$account->id}:{$id}")
                    || InboundMessage::query()->where('connected_account_id', $account->id)->where('provider_message_id', $id)->exists()) {
                    continue;
                }
                $result['checked']++;

                $meta = $this->gmail->metadata($token, $id);
                $match = $this->matcher->match($meta, $apps);

                if ($match['ranked'] === [] && ! $this->classifier->looksRecruiting((string) $meta['subject'], (string) $meta['snippet'])) {
                    Cache::put("gmail:seen:{$account->id}:{$id}", 1, now()->addDays(30));

                    continue;
                }

                $full = array_merge($meta, ['body' => $this->gmail->full($token, $id)['body'] ?? '']);
                $full['__owner_name'] = $account->user?->profile?->structured['name'] ?? $account->user?->name;
                $message = $this->processor->process($account, $full, $apps);

                Cache::put("gmail:seen:{$account->id}:{$id}", 1, now()->addDays(30));
                if ($message) {
                    $result['stored']++;
                    if ($message->review_status === 'needs_review') {
                        $result['needs_review']++;
                    }
                }
            }

            $account->forceFill(['last_synced_at' => now(), 'status' => 'connected', 'last_error' => null])->save();
        } catch (GmailException $e) {
            $result['error'] = $e->getMessage();
            $account->forceFill(['last_error' => $e->getMessage(), 'status' => $e->reauthRequired ? 'revoked' : $account->status])->save();
        } catch (Throwable $e) {
            report_safely($e, 'gmail sync');
            $result['error'] = 'Unexpected error during Gmail sync ('.class_basename($e).').';
            $account->forceFill(['last_error' => $result['error']])->save();
        }

        return $result;
    }
}
