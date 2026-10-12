<?php

namespace App\Services\Notifications;

use App\Enums\EligibilityStatus;
use App\Models\NotificationLog;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\Jobs\JobFeedQuery;
use App\Services\Matching\MatchService;
use Illuminate\Support\Carbon;

/**
 * One e-mail with the best NEW jobs since the last digest, instead of one e-mail per job.
 * Uses the same query as the feed (JobFeedQuery), so it respects your thresholds, exclusion rules,
 * hidden jobs and saved searches. Restricted-location jobs are left out. Nothing is sent when there is
 * nothing new.
 */
class DigestService
{
    public function __construct(
        private readonly JobFeedQuery $feed,
        private readonly MatchService $matches,
        private readonly NotificationService $notifications,
    ) {}

    public function isDue(User $user, ?Carbon $now = null): bool
    {
        $freq = (string) settings()->get('digest_frequency');
        if (! in_array($freq, ['daily', 'weekly'], true)) {
            return false;
        }
        $local = ($now ?? now())->copy()->setTimezone($user->tz());
        if ($local->hour < (int) settings()->get('digest_hour')) {
            return false;
        }
        if ($freq === 'weekly' && $local->dayOfWeekIso !== 1) {
            return false;
        }
        if ($last = $this->lastSentAt()) {
            $lastLocal = $last->copy()->setTimezone($user->tz());
            if ($freq === 'daily' ? $lastLocal->isSameDay($local) : $lastLocal->diffInDays($local, true) < 6) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{items: list<array{title: string, meta: string, url: string}>, total: int, since: Carbon}
     */
    public function collect(User $user, ?Carbon $since = null): array
    {
        $since ??= $this->lastSentAt() ?? now()->subDays(settings()->get('digest_frequency') === 'weekly' ? 7 : 1);
        $profile = $this->matches->activeProfile();
        if (! $profile) {
            return ['items' => [], 'total' => 0, 'since' => $since];
        }

        $max = max(1, (int) settings()->get('digest_max_items', 10));
        $seen = [];
        $items = [];
        $fresh = fn ($q) => $q->where('job_postings.first_seen_at', '>', $since)
            ->where('m.eligibility_status', '!=', 'restricted');

        $main = $fresh($this->feed->build($user, $profile, ['min_score' => settings()->potentialThreshold()]))
            ->orderByDesc('m.score')->limit($max)->get();
        foreach ($main as $job) {
            $seen[$job->id] = true;
            $items[] = $this->item($job);
        }

        $searches = SavedSearch::query()->where('user_id', $user->id)->where('digest', true)->orderBy('name')->get();
        foreach ($searches as $search) {
            $rows = $fresh($this->feed->build($user, $profile, $search->filters))->orderByDesc('m.score')->limit(5)->get();
            foreach ($rows as $job) {
                if (isset($seen[$job->id]) || count($items) >= $max + 10) {
                    continue;
                }
                $seen[$job->id] = true;
                $items[] = $this->item($job, $search->name);
            }
        }

        return ['items' => $items, 'total' => count($items), 'since' => $since];
    }

    /** Send if due (or when forced). Returns the notification row, or null when nothing was sent. */
    public function send(User $user, bool $force = false, ?Carbon $since = null): ?NotificationLog
    {
        if (! $force && ! $this->isDue($user)) {
            return null;
        }

        $local = now()->setTimezone($user->tz());
        $data = $this->collect($user, $since);
        // Advance the window even when empty, so tomorrow's digest only covers tomorrow's jobs.
        if (! $force) {
            settings()->set('digest_last_at', now()->toIso8601String());
        }
        if ($data['items'] === []) {
            return null;
        }

        $weekly = settings()->get('digest_frequency') === 'weekly';
        $key = $force
            ? 'digest:test:'.now()->format('YmdHis')
            : 'digest:'.$user->id.':'.($weekly ? $local->format('o-\WW') : $local->format('Y-m-d'));
        $n = count($data['items']);

        return $this->notifications->digest(
            $key,
            ($weekly ? 'Weekly' : 'Daily').' digest: '.$n.' new match'.($n === 1 ? '' : 'es'),
            ["Jobs first seen since {$data['since']->copy()->setTimezone($user->tz())->format('M j, g:i A')}, best first. Nothing has been applied for."],
            $data['items'],
            sendNow: $force,
        );
    }

    private function lastSentAt(): ?Carbon
    {
        $v = settings()->get('digest_last_at');

        return $v ? Carbon::parse($v) : null;
    }

    /** @return array{title: string, meta: string, url: string} */
    private function item($job, ?string $viaSearch = null): array
    {
        $elig = EligibilityStatus::tryFrom((string) ($job->match_eligibility ?? ''));
        $meta = array_filter([
            isset($job->match_score) ? round($job->match_score).'/100' : null,
            $elig?->label(),
            'via '.$job->source->name,
            $job->salaryLabel(),
            $viaSearch ? 'search: '.$viaSearch : null,
        ]);

        return [
            'title' => $job->title.($job->company ? ' — '.$job->company : ''),
            'meta' => implode(' · ', $meta),
            'url' => route('jobs.show', $job),
        ];
    }
}
