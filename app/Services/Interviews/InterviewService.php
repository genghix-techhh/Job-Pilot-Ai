<?php

namespace App\Services\Interviews;

use App\Models\Application;
use App\Models\Interview;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class InterviewService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Create an entry from the owner's form. The local date/time is interpreted in the chosen
     * time zone and stored in UTC together with that zone's name.
     *
     * @param  array{kind: string, title: string, starts_at_local?: ?string, ends_at_local?: ?string, deadline_local?: ?string, source_timezone: string, meeting_url?: ?string, notes?: ?string, state?: string, application_id?: ?int}  $d
     */
    public function create(User $user, array $d): Interview
    {
        $tz = in_array($d['source_timezone'], timezone_identifiers_list(), true) ? $d['source_timezone'] : $user->tz();

        $application = null;
        if (! empty($d['application_id'])) {
            $application = Application::query()->where('user_id', $user->id)->find($d['application_id']);
        }

        return Interview::create([
            'user_id' => $user->id,
            'application_id' => $application?->id,
            'kind' => in_array($d['kind'] ?? '', ['interview', 'assessment', 'follow_up'], true) ? $d['kind'] : 'interview',
            'title' => Str::limit(trim($d['title']), 190, ''),
            'starts_at' => $this->toUtc($d['starts_at_local'] ?? null, $tz),
            'ends_at' => $this->toUtc($d['ends_at_local'] ?? null, $tz),
            'deadline_at' => $this->toUtc($d['deadline_local'] ?? null, $tz),
            'source_timezone' => $tz,
            'meeting_url' => $this->url($d['meeting_url'] ?? null),
            'notes' => $d['notes'] ?? null,
            'state' => in_array($d['state'] ?? '', ['tentative', 'confirmed'], true) ? $d['state'] : 'tentative',
        ]);
    }

    /** @param array<string, mixed> $d */
    public function update(Interview $i, array $d): Interview
    {
        $tz = in_array($d['source_timezone'] ?? '', timezone_identifiers_list(), true) ? $d['source_timezone'] : $i->source_timezone;
        $i->fill([
            'kind' => in_array($d['kind'] ?? '', ['interview', 'assessment', 'follow_up'], true) ? $d['kind'] : $i->kind,
            'title' => Str::limit(trim((string) ($d['title'] ?? $i->title)), 190, ''),
            'starts_at' => array_key_exists('starts_at_local', $d) ? $this->toUtc($d['starts_at_local'], $tz) : $i->starts_at,
            'ends_at' => array_key_exists('ends_at_local', $d) ? $this->toUtc($d['ends_at_local'], $tz) : $i->ends_at,
            'deadline_at' => array_key_exists('deadline_local', $d) ? $this->toUtc($d['deadline_local'], $tz) : $i->deadline_at,
            'source_timezone' => $tz,
            'meeting_url' => array_key_exists('meeting_url', $d) ? $this->url($d['meeting_url']) : $i->meeting_url,
            'notes' => $d['notes'] ?? $i->notes,
        ]);
        if ($i->isDirty(['starts_at', 'deadline_at'])) {
            $i->reminder_sent_at = null;
        }
        $i->save();

        return $i;
    }

    /** The owner explicitly confirms a (previously tentative) time. */
    public function confirm(Interview $i): void
    {
        if (! $i->starts_at && ! $i->deadline_at) {
            throw new \DomainException('Add a date and time before confirming.');
        }
        $i->update(['state' => 'confirmed']);
        $this->notifications->interviewDetected($i->refresh());
    }

    public function cancel(Interview $i): void
    {
        $i->update(['state' => 'cancelled']);
    }

    public function complete(Interview $i): void
    {
        $i->update(['state' => 'completed']);
    }

    /** Send one reminder for confirmed items starting (or due) within the next 24 hours. */
    public function sendDueReminders(): int
    {
        $count = 0;
        Interview::query()->with('user')->where('state', 'confirmed')->whereNull('reminder_sent_at')
            ->where(function ($q) {
                $q->whereBetween('starts_at', [now(), now()->addDay()])
                    ->orWhere(fn ($q2) => $q2->whereNull('starts_at')->whereBetween('deadline_at', [now(), now()->addDay()]));
            })->get()->each(function (Interview $i) use (&$count) {
                if ($this->notifications->deadlineApproaching($i)) {
                    $count++;
                }
                $i->update(['reminder_sent_at' => now()]);
            });

        return $count;
    }

    private function toUtc(?string $local, string $tz): ?Carbon
    {
        $local = trim((string) $local);
        if ($local === '') {
            return null;
        }
        try {
            return Carbon::parse($local, $tz)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function url(?string $url): ?string
    {
        $url = trim((string) $url);

        return preg_match('~^https?://[^\s]+$~i', $url) ? Str::limit($url, 490, '') : null;
    }
}
