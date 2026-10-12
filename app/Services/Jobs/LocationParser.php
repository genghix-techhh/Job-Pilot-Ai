<?php

namespace App\Services\Jobs;

use App\Services\Matching\Geo;
use Illuminate\Support\Str;

/**
 * Turns a location string (+ optional description) into remote flags and an
 * explicit country list. "Remote" alone is NOT treated as "worldwide".
 */
class LocationParser
{
    /**
     * @return array{is_remote: bool, scope: string, countries: list<string>}
     */
    public function parse(?string $location, string $description = '', ?string $declaredWorkplace = null): array
    {
        $loc = Str::lower((string) $location);
        $declared = Str::lower((string) $declaredWorkplace);
        $head = Str::lower(Str::limit($description, 1500, ''));

        $isRemote = (bool) preg_match('/\bremote\b|work from home|work-from-home|\bwfh\b|distributed|work from anywhere/', $loc)
            || $declared === 'remote'
            || (bool) preg_match('/\b(fully|100%|completely)\s+remote\b|\bremote[- ]first\b|\bremote position\b|\bthis is a remote\b/', $head);

        $hybrid = $declared === 'hybrid' || (bool) preg_match('/\bhybrid\b/', $loc);
        $onsite = in_array($declared, ['on-site', 'onsite'], true) || (bool) preg_match('/\bon-?site\b|\bin[- ]office\b/', $loc);

        $worldwide = (bool) preg_match('/\bworldwide\b|\banywhere\b|\bglobal(ly)?\b|\bwork from anywhere\b|\bany location\b|\banywhere in the world\b/', $loc)
            || (bool) preg_match('/\bwork(ing)? from anywhere\b|\banywhere in the world\b|\bworldwide\b(?! (leader|company|brand))|\bglobally distributed\b|\bopen to candidates (globally|worldwide)\b/', $head);

        $countries = Geo::countriesIn((string) $location);

        // Restrictive phrases in the description ("must be located in the US").
        $restrict = '/(?:must|need to|required to)\s+(?:be\s+)?(?:located|based|reside|living|resident)\s+in\s+(?:the\s+)?([a-z .,&\/-]{3,60}?)(?:[.;\n]|$)'
            .'|(?:only|exclusively)\s+(?:open\s+)?(?:to|for)\s+(?:candidates|applicants|residents)\s+(?:in|from|based in)\s+(?:the\s+)?([a-z .,&\/-]{3,60}?)(?:[.;\n]|$)'
            .'|(?:us|u\.s\.|uk|eu)[- ]based(?: candidates| applicants)?(?: only)?/i';
        if (preg_match_all($restrict, Str::limit($description, 6000, ''), $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $chunk = ($hit[1] ?? '').' '.($hit[2] ?? '').' '.($hit[0]);
                foreach (Geo::countriesIn($chunk) as $c) {
                    $countries[] = $c;
                }
            }
        }
        // Region words (EU, Europe, LATAM ...) are kept alongside countries so the
        // eligibility step can decide only where membership is unambiguous.
        $regions = array_map(fn ($r) => strlen($r) <= 3 ? Str::upper($r) : Str::title($r), Geo::regionsIn((string) $location));
        $countries = array_values(array_unique(array_merge($countries, $regions)));

        $scope = match (true) {
            $hybrid => 'hybrid',
            $onsite && ! $isRemote => 'onsite',
            $worldwide && $countries === [] => 'worldwide',
            $regions !== [] || count($countries) > 1 => 'region',
            $countries !== [] => 'country',
            default => 'unclear',
        };

        return ['is_remote' => $isRemote && ! $hybrid, 'scope' => $scope, 'countries' => $countries];
    }
}
