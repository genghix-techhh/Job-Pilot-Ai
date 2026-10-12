<?php

namespace App\Services\Mail;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pulls explicit date/times out of an e-mail and converts them to UTC with explicit
 * time-zone handling. It never silently assumes a zone: results say whether the zone was
 * stated and whether the abbreviation is ambiguous (CST, IST ...). Everything it returns
 * is only ever stored as TENTATIVE until the owner confirms it.
 */
class TimeExtractor
{
    /** Abbreviation => offset in minutes. Ambiguous ones are flagged separately. */
    private const OFFSETS = [
        'UTC' => 0, 'GMT' => 0, 'Z' => 0, 'WET' => 0, 'WEST' => 60, 'BST' => 60, 'CET' => 60, 'CEST' => 120, 'EET' => 120, 'EEST' => 180,
        'MSK' => 180, 'EST' => -300, 'EDT' => -240, 'CST' => -360, 'CDT' => -300, 'MST' => -420, 'MDT' => -360, 'PST' => -480, 'PDT' => -420,
        'AKST' => -540, 'HST' => -600, 'PKT' => 300, 'IST' => 330, 'GST' => 240, 'SGT' => 480, 'HKT' => 480, 'JST' => 540, 'KST' => 540,
        'AEST' => 600, 'AEDT' => 660, 'ACST' => 570, 'AWST' => 480, 'NZST' => 720, 'NZDT' => 780, 'SAST' => 120, 'WAT' => 60, 'EAT' => 180,
    ];

    private const AMBIGUOUS = ['CST', 'IST', 'BST', 'GST', 'EST'];   // EST also = Australian Eastern in some mails; flagged conservatively

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * @return list<array{start: Carbon, tz_label: string, tz_stated: bool, ambiguous: bool}>
     */
    public function extract(string $text, Carbon $receivedAt, string $ownerTimezone): array
    {
        $text = Str::limit($text, 12000, '');
        $found = [];

        // 1) ISO-like: 2027-03-05 15:00 [UTC|+05:00|EST]
        preg_match_all('/(\d{4})-(\d{2})-(\d{2})[T ](\d{1,2}):(\d{2})(?::\d{2})?\s*(Z\b|UTC|GMT|[+-]\d{2}:?\d{2}|[A-Z]{2,5}\b)?/', $text, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $found[] = $this->build((int) $x[1], (int) $x[2], (int) $x[3], (int) $x[4], (int) $x[5], null, $x[6] ?? '', $ownerTimezone);
        }

        // 2) "March 5(, 2027)( at) 3:00 PM EST"
        $mon = '(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)';
        $time = '(\d{1,2})(?::(\d{2}))?\s*(am|pm|a\.m\.|p\.m\.)?';
        $tz = '(?:\s*(?:\(?)(Z\b|UTC[+-]\d{1,2}(?::\d{2})?|GMT[+-]\d{1,2}(?::\d{2})?|UTC|GMT|[A-Z]{2,5}\b|[+-]\d{2}:?\d{2}))?';

        preg_match_all('/\b'.$mon.'\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?(?:\s*,|\s+at|\s+@|\s+from|\s*[-–])?\s+'.$time.$tz.'/i', $text, $m2, PREG_SET_ORDER);
        foreach ($m2 as $x) {
            // groups: 1 month, 2 day, 3 year, 4 hour, 5 minute, 6 am/pm, 7 zone
            $found[] = $this->build(isset($x[3]) && $x[3] !== '' ? (int) $x[3] : null, $this->month($x[1]), (int) $x[2], (int) $x[4], isset($x[5]) && $x[5] !== '' ? (int) $x[5] : 0, ($x[6] ?? '') !== '' ? $x[6] : null, $x[7] ?? '', $ownerTimezone, $receivedAt);
        }

        // 3) "5 March(, 2027)( at) 15:00 GMT+5"
        preg_match_all('/\b(\d{1,2})(?:st|nd|rd|th)?\s+'.$mon.'\.?(?:,?\s+(\d{4}))?(?:\s*,|\s+at|\s+@|\s+from|\s*[-–])?\s+'.$time.$tz.'/i', $text, $m3, PREG_SET_ORDER);
        foreach ($m3 as $x) {
            // groups: 1 day, 2 month, 3 year, 4 hour, 5 minute, 6 am/pm, 7 zone
            $found[] = $this->build(isset($x[3]) && $x[3] !== '' ? (int) $x[3] : null, $this->month($x[2]), (int) $x[1], (int) $x[4], isset($x[5]) && $x[5] !== '' ? (int) $x[5] : 0, ($x[6] ?? '') !== '' ? $x[6] : null, $x[7] ?? '', $ownerTimezone, $receivedAt);
        }

        $out = [];
        foreach (array_filter($found) as $f) {
            $out[$f['start']->format('YmdHi')] = $f;
        }

        return array_slice(array_values($out), 0, 6);
    }

    /**
     * Convert a model-proposed time (ISO string + zone label) to the same structure.
     *
     * @return array{start: Carbon, tz_label: string, tz_stated: bool, ambiguous: bool}|null
     */
    public function fromIso(string $iso, string $tzLabel, string $ownerTimezone): ?array
    {
        $iso = trim($iso);
        if ($iso === '') {
            return null;
        }
        try {
            $hasOffset = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $iso);
            if ($hasOffset) {
                return ['start' => Carbon::parse($iso)->utc(), 'tz_label' => $tzLabel !== '' ? $tzLabel : 'offset in message', 'tz_stated' => true, 'ambiguous' => false];
            }
            $local = Carbon::parse($iso, 'UTC');
            $b = $this->build((int) $local->year, (int) $local->month, (int) $local->day, (int) $local->hour, (int) $local->minute, null, $tzLabel, $ownerTimezone);

            return $b;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{start: Carbon, tz_label: string, tz_stated: bool, ambiguous: bool}|null */
    private function build(?int $year, int $month, int $day, int $hour, int $min, ?string $meridiem, string $tzRaw, string $ownerTz, ?Carbon $ref = null): ?array
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $min > 59) {
            return null;
        }
        if ($meridiem) {
            $pm = str_starts_with(strtolower($meridiem), 'p');
            if ($hour < 1 || $hour > 12) {
                return null;
            }
            $hour = ($hour % 12) + ($pm ? 12 : 0);
        } elseif ($hour > 23) {
            return null;
        }

        $year ??= $this->inferYear($month, $day, $ref ?? now());
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $tzRaw = trim($tzRaw);
        $stated = $tzRaw !== '';
        $label = $stated ? strtoupper($tzRaw) : $ownerTz.' (assumed)';
        $ambiguous = false;

        try {
            if (! $stated) {
                $start = Carbon::create($year, $month, $day, $hour, $min, 0, $ownerTz)->utc();
            } elseif (preg_match('/^(?:UTC|GMT)?([+-])(\d{1,2})(?::?(\d{2}))?$/i', $tzRaw, $o)) {
                $minutes = ((int) $o[2] * 60 + (int) ($o[3] ?? 0)) * ($o[1] === '-' ? -1 : 1);
                $start = Carbon::create($year, $month, $day, $hour, $min, 0, 'UTC')->subMinutes($minutes);
            } elseif (isset(self::OFFSETS[strtoupper($tzRaw)])) {
                $start = Carbon::create($year, $month, $day, $hour, $min, 0, 'UTC')->subMinutes(self::OFFSETS[strtoupper($tzRaw)]);
                $ambiguous = in_array(strtoupper($tzRaw), self::AMBIGUOUS, true);
            } elseif (in_array($tzRaw, timezone_identifiers_list(), true)) {
                $start = Carbon::create($year, $month, $day, $hour, $min, 0, $tzRaw)->utc();
            } else {
                // Unknown label (e.g. a stray word): treat as NOT stated rather than guess.
                $stated = false;
                $label = $ownerTz.' (assumed)';
                $start = Carbon::create($year, $month, $day, $hour, $min, 0, $ownerTz)->utc();
            }
        } catch (Throwable) {
            return null;
        }

        return ['start' => $start, 'tz_label' => $label, 'tz_stated' => $stated, 'ambiguous' => $ambiguous];
    }

    private function month(string $name): int
    {
        return self::MONTHS[strtolower(substr($name, 0, 4) === 'sept' ? 'sept' : substr($name, 0, 3))] ?? 0;
    }

    private function inferYear(int $month, int $day, Carbon $ref): int
    {
        $candidate = $ref->year;
        if (checkdate($month, $day, $candidate) && Carbon::create($candidate, $month, $day)->lt($ref->copy()->startOfDay()->subDay())) {
            $candidate++;
        }

        return $candidate;
    }
}
