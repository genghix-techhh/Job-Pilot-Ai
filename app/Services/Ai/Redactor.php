<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

/**
 * Strips directly identifying / private data from text before it can be sent to
 * the Gemini free tier (whose prompts may be used by Google and human-reviewed).
 *
 * This is defence in depth: payload builders already send structured, minimal data,
 * the redactor removes anything identifying that slipped through, and
 * {@see assertClean()} is the last gate before an HTTP request is made.
 */
class Redactor
{
    private const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    private const URL = '~(?:https?://|www\.)[^\s<>"\')\]]+~i';

    private const SOCIAL_HOST = '~\b(?:[a-z]{2,3}\.)?(?:linkedin|github|gitlab|bitbucket|behance|dribbble|twitter|facebook|instagram|medium|kaggle|stackoverflow|youtube|t)\.(?:com|co|me)(?:/[^\s<>"\')\]]*)?~i';

    private const HANDLE = '/(?<![\w@.])@[A-Za-z0-9_]{3,30}\b/';

    private const CNIC = '/\b\d{5}-\d{7}-\d\b/';

    private const SSN = '/\b\d{3}-\d{2}-\d{4}\b/';

    private const PASSPORT = '/passport\s*(?:no\.?|number|#)?\s*[:\-]?\s*[A-Z0-9]{6,9}\b/i';

    private const PRIVATE_LINE = '/^.*\b(?:date of birth|d\.?o\.?b\.?|born on|birth\s?date|birthday|marital status|nationality|citizenship|gender|sex|visa status|father\'?s? name|mother\'?s? name|religion|cnic|national id(?:entity)?(?: card)?|social security|ssn|nic no)\b.*$/im';

    private const ADDRESS_LINE = '/^.*\b(?:address|street|\bst\.|road|\brd\.|avenue|\bave\.?|lane|block\s+[a-z0-9]+|sector\s+[a-z0-9\-]+|house\s*(?:no\.?|#|number)|flat\s*(?:no\.?|#)|apartment|\bapt\.?|p\.?o\.? box|postal code|zip code)\b.*$/im';

    /**
     * Redact a CV. Removes contact details, links, handles, IDs, birth/family lines,
     * address lines and the candidate's own name wherever it appears.
     *
     * @param  list<string>  $knownNames  full name and any other names to mask
     */
    public function redactCv(string $text, array $knownNames = []): string
    {
        $text = preg_replace(self::PRIVATE_LINE, '[REMOVED]', $text) ?? $text;
        $text = preg_replace(self::ADDRESS_LINE, '[ADDRESS]', $text) ?? $text;
        $text = $this->redactGeneral($text);
        $text = preg_replace(self::SOCIAL_HOST, '[LINK]', $text) ?? $text;
        $text = preg_replace(self::CNIC, '[ID]', $text) ?? $text;
        $text = preg_replace(self::SSN, '[ID]', $text) ?? $text;
        $text = preg_replace(self::PASSPORT, '[ID]', $text) ?? $text;

        return $this->maskNames($text, $knownNames);
    }

    /** Redact free text such as job descriptions or e-mail excerpts. */
    public function redactGeneral(string $text): string
    {
        $text = preg_replace(self::EMAIL, '[EMAIL]', $text) ?? $text;
        $text = preg_replace(self::URL, '[URL]', $text) ?? $text;
        $text = preg_replace(self::HANDLE, '[HANDLE]', $text) ?? $text;
        $text = $this->redactPhones($text);

        return $text;
    }

    /** @param list<string> $names */
    public function maskNames(string $text, array $names): string
    {
        $tokens = [];
        foreach ($names as $name) {
            $name = trim($name);
            if (mb_strlen($name) < 3) {
                continue;
            }
            $tokens[] = $name;
            foreach (preg_split('/\s+/u', $name) ?: [] as $part) {
                if (mb_strlen($part) >= 3) {
                    $tokens[] = $part;
                }
            }
        }
        // Longest first so the full name is replaced before its parts.
        usort($tokens, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach (array_unique($tokens) as $token) {
            $text = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($token, '/').'(?![\p{L}\p{N}])/iu', '[NAME]', $text) ?? $text;
        }

        return $text;
    }

    public function redactPhones(string $text): string
    {
        return preg_replace_callback('/\+?\(?\d[\d\s().\-]{6,}\d/', function (array $m): string {
            $digits = preg_replace('/\D/', '', $m[0]) ?? '';
            if (strlen($digits) < 8 || strlen($digits) > 15) {
                return $m[0];
            }
            // Leave pure year ranges such as "2019 - 2021" or "2019-2021 2022" alone.
            $groups = preg_split('/\D+/', trim($m[0], " \t-().+")) ?: [];
            $allYears = $groups !== [] && count(array_filter($groups, fn ($g) => preg_match('/^(19|20)\d{2}$/', $g))) === count($groups);
            if ($allYears) {
                return $m[0];
            }

            return '[PHONE]';
        }, $text) ?? $text;
    }

    /**
     * Last gate before sending: returns human-readable reasons if anything identifying remains.
     *
     * @param  list<string>  $knownNames
     * @return list<string>
     */
    public function findLeaks(string $text, array $knownNames = []): array
    {
        $leaks = [];
        if (preg_match(self::EMAIL, $text)) {
            $leaks[] = 'an e-mail address';
        }
        if ($this->redactPhones($text) !== $text) {
            $leaks[] = 'a phone number';
        }
        if (preg_match(self::CNIC, $text) || preg_match(self::SSN, $text)) {
            $leaks[] = 'an identification number';
        }
        foreach ($knownNames as $name) {
            $name = trim($name);
            if (mb_strlen($name) < 3) {
                continue;
            }
            // Same whole-word rule as maskNames(): "Sam" must not trip on "Samsung".
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $text)) {
                $leaks[] = 'the owner\'s name';
                break;
            }
        }
        if (preg_match('/AIza[0-9A-Za-z_\-]{20,}/', $text)) {
            $leaks[] = 'an API key';
        }

        return $leaks;
    }

    /**
     * @param  list<string>  $knownNames
     *
     * @throws AiException
     */
    public function assertClean(string $text, array $knownNames = []): void
    {
        $leaks = $this->findLeaks($text, $knownNames);
        if ($leaks !== []) {
            throw AiException::privacy('the prompt still contained '.implode(', ', $leaks).'.');
        }
    }

    /** Remove secrets from an error message before it is stored or shown. */
    public static function scrubSecrets(?string $message): string
    {
        $message = (string) $message;
        $message = preg_replace('/AIza[0-9A-Za-z_\-]{20,}/', '[KEY]', $message) ?? $message;
        $message = preg_replace('/(key|token|secret|password)=([^&\s]+)/i', '$1=[REDACTED]', $message) ?? $message;
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/', 'Bearer [REDACTED]', $message) ?? $message;

        return Str::limit($message, 300, '…');
    }
}
