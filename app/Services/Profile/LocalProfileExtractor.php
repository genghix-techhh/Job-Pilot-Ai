<?php

namespace App\Services\Profile;

use App\Services\Matching\SkillCatalog;
use App\Support\Text;
use Illuminate\Support\Str;

/**
 * Heuristic CV → profile extraction that runs entirely on the server with no AI.
 * It is deliberately conservative: anything it cannot find is left empty for the
 * owner to fill in on the review screen. Nothing is invented.
 */
class LocalProfileExtractor
{
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    private const SECTION_PATTERNS = [
        'summary' => '/^(?:professional\s+)?(?:summary|profile|objective|about(?:\s+me)?|career\s+objective)$/i',
        'experience' => '/^(?:professional\s+|work\s+|relevant\s+)?(?:experience|employment(?:\s+history)?|work\s+history|career\s+history)$/i',
        'education' => '/^(?:education|academic(?:\s+background|\s+qualifications)?|qualifications)$/i',
        'projects' => '/^(?:personal\s+|selected\s+|key\s+|side\s+)?projects?$/i',
        'skills' => '/^(?:technical\s+|key\s+|core\s+)?(?:skills|competencies|technologies|tech\s+stack|skills\s*&\s*tools)$/i',
        'certifications' => '/^(?:certifications?|certificates?|licenses?|courses?|training)$/i',
        'other' => '/^(?:languages?|awards?|achievements?|references?|interests?|hobbies|volunteer(?:ing)?|publications?)$/i',
    ];

    private const DATE = '(?:(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?\s+\d{4}|\d{1,2}\/\d{4}|\d{4})';

    /**
     * @return array{structured: array<string, mixed>, skills: list<string>, years_experience: ?float, roles: list<string>}
     */
    public function extract(string $text): array
    {
        $text = Text::normalizeWhitespace($text);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));

        $sections = $this->splitSections($lines);
        $header = $sections['_header'] ?? [];

        $work = $this->parseWork($sections['experience'] ?? []);
        $projects = $this->parseProjects($sections['projects'] ?? []);
        $education = $this->parseEducation($sections['education'] ?? []);
        $certs = $this->parseCertifications($sections['certifications'] ?? []);

        $skills = $this->skills($text, $sections['skills'] ?? []);
        $years = $this->yearsExperience($work, $text);

        $titles = implode(' ', array_column($work, 'title')).' '.($header[1] ?? '');

        return [
            'structured' => [
                'name' => $this->guessName($header),
                'headline' => $this->guessHeadline($header),
                'email' => $this->firstMatch('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text),
                'phone' => $this->guessPhone($header ? implode(' ', $header) : $text),
                'location' => '',
                'links' => $this->links($text),
                'summary' => trim(implode(' ', array_slice($sections['summary'] ?? [], 0, 6))),
                'work_history' => $work,
                'projects' => $projects,
                'education' => $education,
                'certifications' => $certs,
                'portfolio_urls' => array_values(array_filter(array_map(
                    fn ($l) => $l['url'],
                    array_filter($this->links($text), fn ($l) => ! in_array($l['label'], ['LinkedIn', 'GitHub'], true))
                ))),
            ],
            'skills' => $skills,
            'years_experience' => $years,
            'roles' => SkillCatalog::rolesIn($titles),
        ];
    }

    /** @param list<string> $lines @return array<string, list<string>> */
    private function splitSections(array $lines): array
    {
        $sections = ['_header' => []];
        $current = '_header';
        foreach ($lines as $line) {
            $key = $this->sectionKey($line);
            if ($key !== null) {
                $current = $key;
                $sections[$current] ??= [];

                continue;
            }
            $sections[$current][] = $line;
        }

        return $sections;
    }

    private function sectionKey(string $line): ?string
    {
        $clean = trim(rtrim(trim($line), ':'), " \t-_*#|");
        if ($clean === '' || mb_strlen($clean) > 40) {
            return null;
        }
        foreach (self::SECTION_PATTERNS as $key => $pattern) {
            if (preg_match($pattern, $clean)) {
                return $key;
            }
        }

        return null;
    }

    /** @param list<string> $header */
    private function guessName(array $header): string
    {
        foreach (array_slice($header, 0, 4) as $line) {
            if (preg_match('/^\p{Lu}[\p{L}\'’\-.]+(?:\s+\p{L}[\p{L}\'’\-.]*){1,3}$/u', $line)
                && ! preg_match('/\d|@|resume|curriculum|vitae|\bcv\b|profile/i', $line)) {
                return $line;
            }
        }

        return '';
    }

    /** @param list<string> $header */
    private function guessHeadline(array $header): string
    {
        foreach (array_slice($header, 0, 5) as $i => $line) {
            if ($i === 0 && $this->guessName([$line]) !== '') {
                continue;
            }
            if (mb_strlen($line) <= 90 && ! preg_match('/@|https?:|\d{5,}/', $line) && preg_match('/developer|engineer|designer|analyst|scientist|manager|specialist|consultant|architect|freelanc/i', $line)) {
                return $line;
            }
        }

        return '';
    }

    private function guessPhone(string $text): string
    {
        return preg_match('/\+?\(?\d[\d\s().\-]{7,}\d/', $text, $m) && strlen(preg_replace('/\D/', '', $m[0])) >= 8
            ? trim($m[0]) : '';
    }

    private function firstMatch(string $regex, string $text): string
    {
        return preg_match($regex, $text, $m) ? $m[0] : '';
    }

    /** @return list<array{label: string, url: string}> */
    private function links(string $text): array
    {
        preg_match_all('~(?:https?://|www\.)[^\s<>"\')\]]+|\b(?:linkedin|github|gitlab|behance)\.com/[^\s<>"\')\]]+~i', $text, $m);
        $out = [];
        foreach (array_unique($m[0]) as $url) {
            $url = rtrim($url, '.,;');
            $full = preg_match('~^https?://~i', $url) ? $url : 'https://'.$url;
            $label = match (true) {
                (bool) preg_match('/linkedin\.com/i', $url) => 'LinkedIn',
                (bool) preg_match('/github\.com/i', $url) => 'GitHub',
                default => 'Website',
            };
            $out[] = ['label' => $label, 'url' => $full];
        }

        return array_slice($out, 0, 8);
    }

    /** @param list<string> $skillLines @return list<string> */
    private function skills(string $fullText, array $skillLines): array
    {
        $found = SkillCatalog::extract($fullText);
        foreach ($skillLines as $line) {
            $line = preg_replace('/^[A-Za-z &\/]{2,30}:\s*/', '', $line) ?? $line; // "Languages: ..."
            foreach (preg_split('/[,;|•·●▪\/]|\s{2,}|\s-\s/u', $line) ?: [] as $token) {
                $token = trim($token, " \t-–•*");
                if ($token !== '' && mb_strlen($token) <= 30 && preg_match('/^[\p{L}0-9+#.\- ]+$/u', $token)) {
                    $found[] = SkillCatalog::canonical($token);
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** @param list<string> $lines @return list<array<string, mixed>> */
    private function parseWork(array $lines): array
    {
        $entries = [];
        $current = null;
        $prev = [];

        $flush = function () use (&$current, &$entries) {
            if ($current && ($current['title'] !== '' || $current['company'] !== '' || $current['bullets'])) {
                $entries[] = $current;
            }
            $current = null;
        };

        foreach ($lines as $line) {
            $range = $this->dateRange($line);
            if ($range) {
                $flush();
                $rest = trim(preg_replace('/\(?'.self::DATE.'\s*(?:-|–|—|to)\s*(?:present|current|now|ongoing|'.self::DATE.')\)?/i', '', $line) ?? '');
                $rest = trim($rest, " \t|,-–—·•");
                [$title, $company] = $this->splitTitleCompany($rest !== '' ? $rest : array_pop($prev) ?? '', $prev);
                $current = [
                    'title' => $title, 'company' => $company,
                    'start' => $range['start'], 'end' => $range['end'], 'current' => $range['current'],
                    'bullets' => [],
                ];
                $prev = [];

                continue;
            }

            $isBullet = (bool) preg_match('/^[-–—•*●▪·]\s*/u', $line);
            if ($current !== null && ($isBullet || mb_strlen($line) > 60)) {
                $current['bullets'][] = trim(preg_replace('/^[-–—•*●▪·]\s*/u', '', $line) ?? $line);
            } elseif ($current === null || mb_strlen($line) <= 60) {
                $prev[] = $line;
                $prev = array_slice($prev, -2);
                if ($current !== null && ! $isBullet && $current['bullets'] !== []) {
                    // A new short line after bullets: likely the next entry's title block.
                    $flush();
                }
            }
        }
        $flush();

        // Re-attach title lines that appeared BEFORE their date line (common CV layout).
        return array_slice($entries, 0, 12);
    }

    /** @return array{start: string, end: string, current: bool}|null */
    private function dateRange(string $line): ?array
    {
        $re = '/('.self::DATE.')\s*(?:-|–|—|to)\s*(present|current|now|ongoing|'.self::DATE.')/i';
        if (! preg_match($re, $line, $m)) {
            return null;
        }
        $isCurrent = (bool) preg_match('/present|current|now|ongoing/i', $m[2]);

        return ['start' => trim($m[1]), 'end' => $isCurrent ? '' : trim($m[2]), 'current' => $isCurrent];
    }

    /** @param list<string> $context @return array{0: string, 1: string} */
    private function splitTitleCompany(string $text, array $context): array
    {
        $text = trim($text);
        foreach ([' at ', ' @ ', ' | ', ' – ', ' — ', ' - ', ', '] as $sep) {
            if (str_contains($text, $sep)) {
                [$a, $b] = array_map('trim', explode($sep, $text, 2));

                return $this->looksLikeTitle($b) && ! $this->looksLikeTitle($a) ? [$b, $a] : [$a, $b];
            }
        }
        if ($context !== [] && $this->looksLikeTitle($text)) {
            return [$text, trim((string) end($context))];
        }

        return [$text, ''];
    }

    private function looksLikeTitle(string $s): bool
    {
        return (bool) preg_match('/developer|engineer|designer|analyst|scientist|manager|lead|architect|consultant|intern|specialist|administrator|officer|freelanc|programmer|devops|director|head of/i', $s);
    }

    /** @param list<string> $lines @return list<array<string, mixed>> */
    private function parseProjects(array $lines): array
    {
        $projects = [];
        $current = null;
        foreach ($lines as $line) {
            $isBullet = (bool) preg_match('/^[-–—•*●▪·]\s*/u', $line);
            if (! $isBullet && mb_strlen($line) <= 70 && ($current === null || $current['description'] !== '')) {
                if ($current) {
                    $projects[] = $this->finishProject($current);
                }
                $parts = preg_split('/\s[-–—|:]\s/u', $line, 2) ?: [$line];
                $current = ['name' => trim($parts[0]), 'description' => isset($parts[1]) ? trim($parts[1]) : '', 'technologies' => [], 'url' => ''];

                continue;
            }
            if ($current === null) {
                $current = ['name' => Str::limit($line, 60, ''), 'description' => '', 'technologies' => [], 'url' => ''];

                continue;
            }
            $clean = trim(preg_replace('/^[-–—•*●▪·]\s*/u', '', $line) ?? $line);
            $current['description'] = trim($current['description'].' '.$clean);
        }
        if ($current) {
            $projects[] = $this->finishProject($current);
        }

        return array_slice($projects, 0, 12);
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function finishProject(array $p): array
    {
        $p['technologies'] = SkillCatalog::extract($p['name'].' '.$p['description']);
        $p['description'] = Text::limit($p['description'], 600);

        return $p;
    }

    /** @param list<string> $lines @return list<array<string, string>> */
    private function parseEducation(array $lines): array
    {
        $out = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/bachelor|master|\bb\.?sc?\b|\bm\.?sc?\b|ph\.?d|\bb\.?tech|\bm\.?tech|diploma|\bmba\b|associate|degree|\bb\.?e\b|\bbs\b|\bms\b/i', $line)) {
                $year = preg_match('/(19|20)\d{2}(?!.*(19|20)\d{2})/', $line.' '.($lines[$i + 1] ?? ''), $m) ? $m[0] : '';
                $school = $lines[$i + 1] ?? '';
                if (preg_match('/bachelor|master|degree/i', $school)) {
                    $school = '';
                }
                $out[] = ['degree' => Text::limit($line, 120), 'school' => Text::limit($school, 120), 'year' => $year];
            }
        }

        return array_slice($out, 0, 6);
    }

    /** @param list<string> $lines @return list<array<string, string>> */
    private function parseCertifications(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $name = trim(preg_replace('/^[-–—•*●▪·]\s*/u', '', $line) ?? $line);
            if ($name !== '' && mb_strlen($name) <= 140) {
                $year = preg_match('/(19|20)\d{2}/', $name, $m) ? $m[0] : '';
                $out[] = ['name' => $name, 'issuer' => '', 'year' => $year];
            }
        }

        return array_slice($out, 0, 15);
    }

    /** @param list<array<string, mixed>> $work */
    private function yearsExperience(array $work, string $text): ?float
    {
        $ranges = [];
        foreach ($work as $w) {
            $s = $this->toMonthIndex((string) ($w['start'] ?? ''));
            $e = ! empty($w['current']) || ($w['end'] ?? '') === ''
                ? (int) now()->format('Y') * 12 + (int) now()->format('n')
                : $this->toMonthIndex((string) $w['end']);
            if ($s && $e && $e >= $s) {
                $ranges[] = [$s, $e];
            }
        }
        if ($ranges) {
            usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
            $months = 0;
            [$cs, $ce] = $ranges[0];
            foreach (array_slice($ranges, 1) as [$s, $e]) {
                if ($s <= $ce) {
                    $ce = max($ce, $e);
                } else {
                    $months += $ce - $cs;
                    [$cs, $ce] = [$s, $e];
                }
            }
            $months += $ce - $cs;

            return round(min(60, $months / 12), 1);
        }

        if (preg_match('/(\d{1,2})\+?\s*(?:years?|yrs?)(?:\s+of)?\s+(?:professional\s+|relevant\s+)?experience/i', $text, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    private function toMonthIndex(string $date): ?int
    {
        $date = strtolower(trim($date));
        if (preg_match('/^([a-z]{3})[a-z]*\.?\s+(\d{4})$/', $date, $m) && isset(self::MONTHS[$m[1]])) {
            return (int) $m[2] * 12 + self::MONTHS[$m[1]];
        }
        if (preg_match('/^(\d{1,2})\/(\d{4})$/', $date, $m)) {
            return (int) $m[2] * 12 + max(1, min(12, (int) $m[1]));
        }
        if (preg_match('/^(\d{4})$/', $date, $m)) {
            return (int) $m[1] * 12 + 6;
        }

        return null;
    }
}
