<?php

namespace App\Services\Matching;

use App\Models\JobPosting;
use Illuminate\Support\Str;

/**
 * Freelance projects are judged differently from employment: what has to be delivered,
 * the budget, the deadline, the requested stack, what the client wants answered in the
 * proposal, and which verified portfolio items are relevant — plus an explicit list of
 * what the posting does NOT say. Purely rule-based; nothing is assumed.
 */
class FreelanceFitAnalyzer
{
    /** @return array<string, mixed> */
    public function analyze(JobPosting $job, ProfileSnapshot $p): array
    {
        $text = (string) $job->description;
        $lines = array_values(array_filter(array_map('trim', preg_split('/\n+/', $text) ?: [])));

        $deliverables = $this->deliverables($lines);
        $deadline = $this->deadline($text);
        $budget = $job->salaryLabel() ?: $this->budgetFromText($text);

        $stack = array_values(array_unique(array_map(fn ($s) => SkillCatalog::canonical((string) $s), (array) ($job->skills ?? []))));
        $have = array_values(array_filter($stack, fn ($s) => $p->hasSkill($s)));
        $lack = array_values(array_diff($stack, $have));

        $unknowns = [];
        if (! $budget) {
            $unknowns[] = 'Budget is not stated — ask before investing time in a proposal.';
        }
        if (! $deadline) {
            $unknowns[] = 'No deadline or timeline is stated.';
        }
        if ($deliverables === []) {
            $unknowns[] = 'Deliverables are not clearly listed; confirm scope in writing.';
        }
        if ($stack === []) {
            $unknowns[] = 'No specific technology stack is mentioned.';
        }

        return [
            'deliverables' => $deliverables,
            'budget' => $budget,
            'deadline' => $deadline,
            'stack' => $stack,
            'stack_matched' => $have,
            'stack_missing' => $lack,
            'proposal_questions' => $this->proposalQuestions($lines),
            'portfolio_items' => $this->portfolioHits($stack, $p),
            'unknowns' => $unknowns,
        ];
    }

    /** @param list<string> $lines @return list<string> */
    private function deliverables(array $lines): array
    {
        $out = [];
        $inSection = false;
        foreach ($lines as $line) {
            if (preg_match('/^(?:deliverables?|scope|what you.?ll (?:do|build)|requirements?|the task|project (?:scope|description)|you will)\b/i', $line)) {
                $inSection = true;
            }
            if ($inSection && preg_match('/^[-–•*]\s*(.{8,200})$/u', $line, $m)) {
                $out[] = trim($m[1]);
            }
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    private function deadline(string $text): ?string
    {
        if (preg_match('/(?:deadline|due(?: date)?|deliver(?:ed)? by|complete(?:d)? by|needed by|within)\s*[:\-]?\s*([^.\n]{3,60})/i', $text, $m)) {
            return trim($m[0]);
        }

        return null;
    }

    private function budgetFromText(string $text): ?string
    {
        if (preg_match('/budget\s*[:\-]?\s*((?:[$€£]|USD|EUR|GBP)\s?[\d,.]+(?:\s?[kK])?(?:\s*(?:-|–|to)\s*(?:[$€£]|USD|EUR|GBP)?\s?[\d,.]+(?:\s?[kK])?)?)/i', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /** @param list<string> $lines @return list<string> */
    private function proposalQuestions(array $lines): array
    {
        $qs = [];
        foreach ($lines as $line) {
            $isQuestion = Str::endsWith($line, '?') && mb_strlen($line) < 220;
            if ($isQuestion || preg_match('/(?:in your proposal|please (?:tell|describe|share|provide|include|explain))\b/i', $line)) {
                $qs[] = Str::limit($line, 200, '…');
            }
            if (count($qs) >= 6) {
                break;
            }
        }

        return $qs;
    }

    /**
     * Verified portfolio items (from the owner's own profile) that share technology with the request.
     *
     * @param  list<string>  $stack
     * @return list<string> e.g. "Invoice app (Laravel, MySQL)"
     */
    private function portfolioHits(array $stack, ProfileSnapshot $p): array
    {
        $want = array_map('strtolower', $stack);
        $hits = [];
        foreach ($p->projects as $project) {
            $shared = array_values(array_filter($project['skills'], fn ($s) => in_array(strtolower($s), $want, true)));
            if ($shared !== []) {
                $hits[] = $project['name'].' ('.implode(', ', array_slice($shared, 0, 5)).')';
            }
        }

        return array_slice($hits, 0, 6);
    }
}
