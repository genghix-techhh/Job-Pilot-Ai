<?php

namespace App\Services\Interviews;

use App\Models\Interview;
use Illuminate\Support\Carbon;

/** RFC 5545 calendar file for one interview/assessment. Times are written in UTC ("Z"). */
class IcsGenerator
{
    public function forInterview(Interview $i): string
    {
        $start = $i->starts_at ?? $i->deadline_at;
        if (! $start) {
            throw new \InvalidArgumentException('This entry has no date/time to export.');
        }
        $end = $i->ends_at ?? $start->copy()->addHour();
        $desc = array_filter([
            $i->application?->job ? $i->application->job->title.($i->application->job->company ? ' at '.$i->application->job->company : '') : null,
            $i->state === 'tentative' ? 'STATUS: tentative — not yet confirmed in JobPilot.' : null,
            $i->meeting_url ? 'Meeting link: '.$i->meeting_url : null,
            $i->notes,
        ]);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//JobPilot AI//Interviews//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:jobpilot-interview-'.$i->id.'@'.parse_url(config('app.url'), PHP_URL_HOST),
            'DTSTAMP:'.Carbon::now('UTC')->format('Ymd\THis\Z'),
            'DTSTART:'.$start->copy()->utc()->format('Ymd\THis\Z'),
            'DTEND:'.$end->copy()->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.$this->escape(($i->state === 'tentative' ? '[Tentative] ' : '').$i->title),
            'DESCRIPTION:'.$this->escape(implode("\n", $desc)),
            'STATUS:'.($i->state === 'confirmed' ? 'CONFIRMED' : ($i->state === 'cancelled' ? 'CANCELLED' : 'TENTATIVE')),
        ];
        if ($i->meeting_url) {
            $lines[] = 'URL:'.$i->meeting_url;
            $lines[] = 'LOCATION:'.$this->escape($i->meeting_url);
        }
        array_push($lines, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Reminder', 'TRIGGER:-PT30M', 'END:VALARM', 'END:VEVENT', 'END:VCALENDAR');

        return implode("\r\n", array_map([$this, 'fold'], $lines))."\r\n";
    }

    private function escape(string $s): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $s);
    }

    /** Fold lines longer than 75 octets (continuation lines start with one space). */
    private function fold(string $line): string
    {
        $parts = [];
        $limit = 75;
        while (strlen($line) > $limit) {
            $cut = $limit;
            // Never split inside a multibyte UTF-8 sequence.
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $parts[] = substr($line, 0, $cut);
            $line = substr($line, $cut);
            $limit = 74; // continuation lines start with one space
        }
        $parts[] = $line;

        return implode("\r\n ", $parts);
    }
}
