<?php

namespace App\Services\Matching;

use Illuminate\Support\Str;

/**
 * Minimal geography knowledge for remote-eligibility reasoning.
 * It only ever answers "this text explicitly names X"; it never guesses a country
 * from a time zone, a company, or a bare word like "Remote".
 */
class Geo
{
    /** canonical name => [aliases (lower-case), ISO-2 code] */
    private const COUNTRIES = [
        'United States' => [['usa', 'u.s.', 'u.s.a.', 'united states', 'united states of america', 'america'], 'US'],
        'Canada' => [['canada'], 'CA'], 'Mexico' => [['mexico', 'méxico'], 'MX'],
        'United Kingdom' => [['uk', 'u.k.', 'united kingdom', 'great britain', 'britain', 'england', 'scotland', 'wales'], 'GB'],
        'Ireland' => [['ireland'], 'IE'], 'Germany' => [['germany', 'deutschland'], 'DE'], 'France' => [['france'], 'FR'],
        'Spain' => [['spain', 'españa'], 'ES'], 'Portugal' => [['portugal'], 'PT'], 'Italy' => [['italy', 'italia'], 'IT'],
        'Netherlands' => [['netherlands', 'the netherlands', 'holland'], 'NL'], 'Belgium' => [['belgium'], 'BE'],
        'Switzerland' => [['switzerland'], 'CH'], 'Austria' => [['austria'], 'AT'], 'Sweden' => [['sweden'], 'SE'],
        'Norway' => [['norway'], 'NO'], 'Denmark' => [['denmark'], 'DK'], 'Finland' => [['finland'], 'FI'],
        'Poland' => [['poland'], 'PL'], 'Czech Republic' => [['czech republic', 'czechia'], 'CZ'], 'Romania' => [['romania'], 'RO'],
        'Bulgaria' => [['bulgaria'], 'BG'], 'Greece' => [['greece'], 'GR'], 'Hungary' => [['hungary'], 'HU'],
        'Ukraine' => [['ukraine'], 'UA'], 'Turkey' => [['turkey', 'türkiye', 'turkiye'], 'TR'], 'Estonia' => [['estonia'], 'EE'],
        'Lithuania' => [['lithuania'], 'LT'], 'Latvia' => [['latvia'], 'LV'], 'Croatia' => [['croatia'], 'HR'],
        'Slovakia' => [['slovakia'], 'SK'], 'Slovenia' => [['slovenia'], 'SI'], 'Serbia' => [['serbia'], 'RS'],
        'Luxembourg' => [['luxembourg'], 'LU'], 'Cyprus' => [['cyprus'], 'CY'], 'Malta' => [['malta'], 'MT'],
        'Pakistan' => [['pakistan'], 'PK'], 'India' => [['india'], 'IN'], 'Bangladesh' => [['bangladesh'], 'BD'],
        'Sri Lanka' => [['sri lanka'], 'LK'], 'Nepal' => [['nepal'], 'NP'], 'China' => [['china'], 'CN'],
        'Japan' => [['japan'], 'JP'], 'South Korea' => [['south korea', 'korea'], 'KR'], 'Singapore' => [['singapore'], 'SG'],
        'Malaysia' => [['malaysia'], 'MY'], 'Indonesia' => [['indonesia'], 'ID'], 'Philippines' => [['philippines'], 'PH'],
        'Vietnam' => [['vietnam'], 'VN'], 'Thailand' => [['thailand'], 'TH'], 'Taiwan' => [['taiwan'], 'TW'],
        'Hong Kong' => [['hong kong'], 'HK'], 'Australia' => [['australia'], 'AU'], 'New Zealand' => [['new zealand'], 'NZ'],
        'United Arab Emirates' => [['uae', 'u.a.e.', 'united arab emirates', 'dubai'], 'AE'], 'Saudi Arabia' => [['saudi arabia', 'ksa'], 'SA'],
        'Qatar' => [['qatar'], 'QA'], 'Israel' => [['israel'], 'IL'], 'Egypt' => [['egypt'], 'EG'], 'Jordan' => [['jordan'], 'JO'],
        'Nigeria' => [['nigeria'], 'NG'], 'Kenya' => [['kenya'], 'KE'], 'South Africa' => [['south africa'], 'ZA'],
        'Ghana' => [['ghana'], 'GH'], 'Morocco' => [['morocco'], 'MA'], 'Brazil' => [['brazil', 'brasil'], 'BR'],
        'Argentina' => [['argentina'], 'AR'], 'Chile' => [['chile'], 'CL'], 'Colombia' => [['colombia'], 'CO'],
        'Peru' => [['peru'], 'PE'], 'Uruguay' => [['uruguay'], 'UY'], 'Costa Rica' => [['costa rica'], 'CR'],
    ];

    private const EU = ['Germany', 'France', 'Spain', 'Portugal', 'Italy', 'Netherlands', 'Belgium', 'Austria', 'Sweden', 'Denmark', 'Finland', 'Ireland', 'Poland', 'Czech Republic', 'Romania', 'Bulgaria', 'Greece', 'Hungary', 'Estonia', 'Lithuania', 'Latvia', 'Croatia', 'Slovakia', 'Slovenia', 'Luxembourg', 'Cyprus', 'Malta'];

    /** Regions whose membership is unambiguous enough to decide on. */
    public static function regionMembers(string $region): ?array
    {
        return match (Str::lower(trim($region))) {
            'eu', 'european union' => self::EU,
            'europe', 'european', 'emea europe' => array_merge(self::EU, ['United Kingdom', 'Switzerland', 'Norway', 'Ukraine', 'Serbia', 'Turkey']),
            'north america' => ['United States', 'Canada', 'Mexico'],
            'latam', 'latin america' => ['Mexico', 'Brazil', 'Argentina', 'Chile', 'Colombia', 'Peru', 'Uruguay', 'Costa Rica'],
            default => null,
        };
    }

    /** Region words that are too vague to decide eligibility on. */
    public const VAGUE_REGIONS = ['apac', 'emea', 'asia', 'mena', 'middle east', 'africa', 'oceania', 'americas', 'cet', 'est', 'pst', 'gmt', 'utc'];

    /** @return list<string> */
    public static function countryNames(): array
    {
        $names = array_keys(self::COUNTRIES);
        sort($names);

        return $names;
    }

    public static function canonicalCountry(?string $text): ?string
    {
        $t = Str::lower(trim((string) $text));
        if ($t === '') {
            return null;
        }
        foreach (self::COUNTRIES as $name => [$aliases, $iso]) {
            if ($t === Str::lower($name) || in_array($t, $aliases, true) || $t === Str::lower($iso)) {
                return $name;
            }
        }

        return null;
    }

    /** ISO-2 code (e.g. Lever's `country` field) to canonical name. */
    public static function fromIso(?string $iso): ?string
    {
        $iso = strtoupper(trim((string) $iso));
        foreach (self::COUNTRIES as $name => [, $code]) {
            if ($code === $iso) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Countries explicitly named in a short location string (not prose).
     * Two-letter codes are only honoured when written upper-case ("US", "UK").
     *
     * @return list<string>
     */
    public static function countriesIn(string $location): array
    {
        $found = [];
        $lower = Str::lower($location);
        foreach (self::COUNTRIES as $name => [$aliases, $iso]) {
            foreach ($aliases as $alias) {
                if (preg_match('/(?<![\p{L}])'.preg_quote($alias, '/').'(?![\p{L}])/u', $lower)) {
                    $found[$name] = true;
                    break;
                }
            }
            if (! isset($found[$name]) && preg_match('/(?<![A-Za-z])'.$iso.'(?![A-Za-z])/', $location)) {
                $found[$name] = true;
            }
        }

        return array_keys($found);
    }

    /** @return list<string> region words (eu, europe, latam ...) named in the text */
    public static function regionsIn(string $text): array
    {
        $lower = Str::lower($text);
        $out = [];
        foreach (['eu', 'european union', 'europe', 'north america', 'latam', 'latin america'] as $r) {
            if (preg_match('/(?<![\p{L}])'.preg_quote($r, '/').'(?![\p{L}])/u', $lower)) {
                $out[] = $r;
            }
        }

        return $out;
    }

    public static function hasVagueRegion(string $text): bool
    {
        $lower = Str::lower($text);
        foreach (self::VAGUE_REGIONS as $r) {
            if (preg_match('/(?<![\p{L}])'.preg_quote($r, '/').'(?![\p{L}])/u', $lower)) {
                return true;
            }
        }

        return false;
    }
}
