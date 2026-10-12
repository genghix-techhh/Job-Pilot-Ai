<?php

namespace App\Services\Matching;

use App\Enums\EligibilityStatus;
use App\Models\JobPosting;
use Illuminate\Support\Str;

/**
 * Decides whether a remote job is open to the owner's country WITHOUT guessing.
 *
 *  - "Eligible"   only when the posting explicitly says worldwide/anywhere, or names the owner's
 *                 country (directly or through an unambiguous region such as EU).
 *  - "Restricted" only when the posting explicitly limits hiring to places that exclude the
 *                 owner's country, or is not remote at all.
 *  - "Unclear"    for everything else — including a bare "Remote", vague regions such as APAC /
 *                 EMEA, and whenever the owner has not stated their country yet.
 * Work authorisation, citizenship and relocation are never inferred.
 */
class EligibilityResolver
{
    /**
     * @param  array<string, mixed>|null  $ai  validated requirements (only used to ADD country lists)
     * @return array{status: EligibilityStatus, note: string}
     */
    public function resolve(JobPosting $job, ?string $homeCountry, ?array $ai = null): array
    {
        $scope = (string) $job->remote_scope;
        $countries = (array) ($job->eligible_countries ?? []);
        $tzNote = $this->timezoneNote((string) $job->description);

        // Use the model's country list only when our own parser found none.
        if ($countries === [] && ! empty($ai['eligible_countries']) && in_array($ai['remote_scope'] ?? '', ['country_restricted', 'region_restricted'], true)) {
            $countries = array_values($ai['eligible_countries']);
            $scope = $scope === 'worldwide' ? 'unclear' : ($scope === 'unclear' ? 'region' : $scope);
        }

        if (! $job->is_remote && in_array($scope, ['onsite', 'hybrid'], true)) {
            return $this->out(EligibilityStatus::Restricted, 'Posting is '.($scope === 'hybrid' ? 'hybrid' : 'on-site').', not fully remote.', $tzNote);
        }

        if ($scope === 'worldwide' && $countries === []) {
            return $this->out(EligibilityStatus::Eligible, 'Posting says work from anywhere / worldwide.', $tzNote);
        }

        if ($countries !== [] && ! $job->is_remote) {
            // A place name with no "remote" wording is a physical location, not a hiring-area restriction.
            $where = implode(', ', $countries);
            if ($homeCountry !== null && ! in_array($homeCountry, $countries, true)) {
                return $this->out(EligibilityStatus::Restricted, "Located in {$where} and not stated as remote; you are in {$homeCountry}.", $tzNote);
            }

            return $this->out(EligibilityStatus::Unclear, "Located in {$where}; the posting does not say it is remote. Check before applying.", $tzNote);
        }

        if ($countries !== []) {
            return $this->againstList($countries, $homeCountry, $tzNote);
        }

        $loc = (string) $job->location;
        if (Geo::hasVagueRegion($loc)) {
            return $this->out(EligibilityStatus::Unclear, "Region-limited (\"{$loc}\") — can't tell whether your country is included. Check the posting.", $tzNote);
        }

        if (! $job->is_remote) {
            return $this->out(EligibilityStatus::Unclear, 'The posting does not say it is remote. Check before applying.', $tzNote);
        }

        return $this->out(EligibilityStatus::Unclear, 'Marked remote but no eligible countries are stated. Check the posting before applying.', $tzNote);
    }

    /** @param list<string> $listed */
    private function againstList(array $listed, ?string $home, string $tzNote): array
    {
        $names = implode(', ', $listed);
        if ($home === null) {
            return $this->out(EligibilityStatus::Unclear, "Limited to {$names}. Set your country in Settings so this can be checked.", $tzNote);
        }

        $allowed = [];
        $vague = false;
        foreach ($listed as $token) {
            if ($c = Geo::canonicalCountry($token)) {
                $allowed[] = $c;
            } elseif ($members = Geo::regionMembers($token)) {
                $allowed = array_merge($allowed, $members);
            } else {
                $vague = true;
            }
        }

        if (in_array($home, $allowed, true)) {
            return $this->out(EligibilityStatus::Eligible, "Open to {$home} (posting lists: {$names}).", $tzNote);
        }
        if ($vague) {
            return $this->out(EligibilityStatus::Unclear, "Posting lists \"{$names}\"; cannot tell if {$home} is included.", $tzNote);
        }

        return $this->out(EligibilityStatus::Restricted, "Posting is limited to {$names}; that does not include {$home}.", $tzNote);
    }

    /** @return array{status: EligibilityStatus, note: string} */
    private function out(EligibilityStatus $status, string $note, string $tzNote): array
    {
        return ['status' => $status, 'note' => trim($note.($tzNote ? ' '.$tzNote : ''))];
    }

    private function timezoneNote(string $description): string
    {
        if (preg_match('/[^.\n]{0,60}\b(?:overlap|working hours|time ?zone|business hours)\b[^.\n]{0,80}/i', Str::limit($description, 8000, ''), $m)) {
            return 'Time-zone note from posting: "'.trim(Str::limit(preg_replace('/\s+/', ' ', $m[0]) ?? '', 140)).'".';
        }

        return '';
    }
}
