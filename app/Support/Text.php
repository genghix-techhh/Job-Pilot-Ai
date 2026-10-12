<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Small text utilities shared by connectors, parsers and prompts.
 * All job/email/CV text is treated as untrusted: it is reduced to plain text
 * on ingest and always HTML-escaped on output.
 */
final class Text
{
    /** Convert HTML (or HTML-escaped HTML) into readable plain text. Never returns markup. */
    public static function htmlToText(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $text = $html;

        // Some APIs (Greenhouse) return HTML that is itself entity-encoded.
        if (! str_contains($text, '<') && str_contains($text, '&lt;')) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Drop script/style blocks wholesale before stripping tags.
        $text = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', ' ', $text) ?? $text;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|div|h[1-6]|tr|ul|ol|section|article)>#i', "\n\n", $text) ?? $text;
        $text = preg_replace('#<li\b[^>]*>#i', "\n- ", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::normalizeWhitespace($text);
    }

    /**
     * Some sources (Remote OK, for one) deliver UTF-8 that was mistakenly decoded as Latin-1 and encoded
     * again ("you’ll" -> "youâ\u0080\u0099ll"). Undo that only when the result is clean UTF-8, so
     * genuine accented text such as "café" or "naïve" is never altered.
     */
    public static function repairMojibake(string $text): string
    {
        if (! preg_match('/[\x{00C2}-\x{00F4}][\x{0080}-\x{00BF}]/u', $text)) {
            return $text;
        }
        // A character above U+00FF means this is not a pure double-encoding; leave it alone.
        if (preg_match('/[^\x{0000}-\x{00FF}]/u', $text)) {
            return $text;
        }
        $bytes = mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : $text;
    }

    public static function normalizeWhitespace(string $text): string
    {
        $text = self::repairMojibake($text);
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[^\P{C}\n\t]+/u', '', $text) ?? $text;   // strip control chars
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    public static function limit(?string $text, int $chars): string
    {
        $text = (string) $text;

        return mb_strlen($text) > $chars ? rtrim(mb_substr($text, 0, $chars)).'…' : $text;
    }

    /** Lower-case, strip punctuation and collapse whitespace — for fingerprints and comparisons. */
    public static function normalizeKey(?string $text): string
    {
        $text = Str::lower((string) $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Canonicalise a URL for deduplication: lower-case host, drop fragments, common
     * tracking parameters and trailing slashes. Returns null for non-http(s) URLs.
     */
    public static function canonicalUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (! $parts || empty($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach (array_keys($query) as $k) {
                if (preg_match('/^(utm_|ref$|referrer$|source$|src$|gh_src$|lever-source|fbclid|gclid)/i', (string) $k)) {
                    unset($query[$k]);
                }
            }
            ksort($query);
        }

        $path = rtrim($parts['path'] ?? '', '/');
        $host = strtolower($parts['host']);
        $host = preg_replace('/^www\./', '', $host);

        return 'https://'.$host.($path === '' ? '' : $path).($query ? '?'.http_build_query($query) : '');
    }

    /** Extract the first http(s) URL from a string. */
    public static function firstUrl(string $text): ?string
    {
        return preg_match('~https?://[^\s<>"\')\]]+~i', $text, $m) ? rtrim($m[0], '.,;') : null;
    }

    /** Make a user-supplied filename safe for display (never used for storage paths). */
    public static function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N}._\- ()]+/u', '_', $name) ?? 'file';
        $name = trim($name, '. ');

        return Str::limit($name === '' ? 'file' : $name, 120, '');
    }
}
