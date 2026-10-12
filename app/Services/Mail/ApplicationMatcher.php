<?php

namespace App\Services\Mail;

use App\Models\Application;
use App\Models\InboundMessage;
use App\Support\Text;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Decides which application an e-mail belongs to. It only auto-selects when ONE candidate is
 * clearly ahead; anything ambiguous is returned as a candidate list for the "Needs review" queue.
 */
class ApplicationMatcher
{
    private const GENERIC = ['inc', 'llc', 'ltd', 'limited', 'corp', 'corporation', 'company', 'co', 'gmbh', 'the', 'group', 'technologies', 'technology', 'labs', 'software', 'solutions', 'systems', 'global', 'international'];

    private const ATS_DOMAINS = ['greenhouse.io', 'lever.co', 'ashbyhq.com', 'workable.com', 'smartrecruiters.com', 'myworkday.com', 'workdaymail.com', 'icims.com', 'bamboohr.com', 'recruitee.com', 'teamtailor.com', 'jobvite.com', 'breezy.hr', 'gmail.com', 'outlook.com', 'yahoo.com'];

    /**
     * @param  Collection<int, Application>  $apps  submitted applications (with job)
     * @param  array<string, mixed>  $msg  from_email, from_name, subject, snippet, thread_id
     * @return array{ranked: list<int>, scores: array<int, int>, matched: ?int}
     */
    public function match(array $msg, Collection $apps): array
    {
        $domain = Str::after((string) $msg['from_email'], '@');
        $label = $this->registrableLabel($domain);
        $isShared = in_array(Str::lower($domain), self::ATS_DOMAINS, true) || str_ends_with(Str::lower($domain), 'greenhouse-mail.io');
        $hay = Str::lower(($msg['from_name'] ?? '').' '.($msg['subject'] ?? '').' '.($msg['snippet'] ?? ''));

        $threadApp = $msg['thread_id'] ?? null
            ? InboundMessage::query()->where('thread_id', $msg['thread_id'])->whereNotNull('application_id')->value('application_id')
            : null;

        $scores = [];
        foreach ($apps as $app) {
            $job = $app->job;
            $score = 0;
            $tokens = $this->companyTokens((string) $job->company);

            if ($threadApp === $app->id) {
                $score += 4;
            }
            if (! $isShared && $tokens && $this->domainMatches($label, $tokens)) {
                $score += 3;
            }
            foreach ($tokens as $t) {
                if ($t !== '' && str_contains($hay, $t)) {
                    $score += 2;
                    break;
                }
            }
            $titleWords = array_filter(array_unique(array_map(fn ($w) => Str::lower($w), preg_split('/[^\p{L}\p{N}+#]+/u', $job->title) ?: [])), fn ($w) => mb_strlen($w) >= 4);
            $hits = count(array_filter($titleWords, fn ($w) => str_contains($hay, $w)));
            if ($titleWords && $hits >= max(2, (int) ceil(count($titleWords) / 2))) {
                $score += 2;
            }

            if ($score > 0) {
                $scores[$app->id] = $score;
            }
        }

        arsort($scores);
        $ranked = array_keys($scores);
        $top = $scores[$ranked[0] ?? 0] ?? 0;
        $second = $scores[$ranked[1] ?? 0] ?? 0;
        $matched = $top >= 3 && ($top - $second) >= 2 ? $ranked[0] : null;

        return ['ranked' => array_slice($ranked, 0, 5), 'scores' => $scores, 'matched' => $matched];
    }

    /** "careers.mail.acme.com" -> "acme";  "jobs.acme.co.uk" -> "acme". */
    private function registrableLabel(string $domain): string
    {
        $parts = explode('.', Str::lower(trim($domain)));
        $n = count($parts);
        if ($n < 2) {
            return $parts[0] ?? '';
        }
        if ($n >= 3 && in_array($parts[$n - 2], ['co', 'com', 'org', 'net', 'ac', 'gov'], true) && strlen($parts[$n - 1]) === 2) {
            return $parts[$n - 3];
        }

        return $parts[$n - 2];
    }

    /** @return list<string> */
    private function companyTokens(string $company): array
    {
        $words = array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', Str::lower($company)) ?: [],
            fn ($w) => $w !== '' && ! in_array($w, self::GENERIC, true) && mb_strlen($w) >= 3
        );
        $joined = implode('', $words);

        return array_values(array_unique(array_filter(array_merge($words, [$joined]))));
    }

    /** @param list<string> $tokens */
    private function domainMatches(string $domainLabel, array $tokens): bool
    {
        $domainLabel = Text::normalizeKey($domainLabel);
        foreach ($tokens as $t) {
            if ($t !== '' && (str_contains($domainLabel, $t) || str_contains($t, $domainLabel) && mb_strlen($domainLabel) >= 4)) {
                return true;
            }
        }

        return false;
    }
}
