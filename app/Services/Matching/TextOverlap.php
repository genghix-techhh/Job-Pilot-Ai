<?php

namespace App\Services\Matching;

use Illuminate\Support\Str;

/** Cheap lexical overlap used where no AI judgement is available. */
class TextOverlap
{
    private const STOP = [
        'about', 'above', 'after', 'again', 'also', 'and', 'any', 'are', 'because', 'been', 'before', 'being', 'between', 'both', 'but', 'can', 'could', 'does', 'doing',
        'during', 'each', 'few', 'for', 'from', 'further', 'had', 'has', 'have', 'having', 'here', 'how', 'into', 'its', 'just', 'more', 'most', 'must', 'not', 'now',
        'only', 'other', 'our', 'out', 'over', 'own', 'same', 'should', 'some', 'such', 'than', 'that', 'the', 'their', 'them', 'then', 'there', 'these', 'they', 'this',
        'those', 'through', 'too', 'under', 'until', 'very', 'was', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'whom', 'why', 'will', 'with', 'would', 'you',
        'your', 'work', 'working', 'team', 'teams', 'role', 'join', 'company', 'looking', 'experience', 'ability', 'strong', 'skills', 'great', 'good', 'well', 'new',
        'help', 'build', 'building', 'etc', 'including', 'include', 'across', 'based', 'high', 'year', 'years', 'plus', 'position', 'candidate', 'candidates', 'apply',
        'benefits', 'salary', 'remote', 'time', 'full', 'part', 'opportunity', 'equal', 'employer', 'life', 'people', 'world', 'make', 'using', 'used', 'use', 'get',
        'take', 'want', 'need', 'needs', 'like', 'love', 'able', 'within', 'without', 'ensure', 'responsible', 'responsibilities', 'requirements', 'qualifications',
    ];

    /**
     * Share of the job's most characteristic terms that also appear in the candidate's text (0..1).
     *
     * @return array{coverage: float, terms: list<string>, hits: list<string>}
     */
    public static function coverage(string $jobText, string $candidateCorpus, int $top = 40): array
    {
        $freq = [];
        foreach (self::tokens($jobText) as $t) {
            $freq[$t] = ($freq[$t] ?? 0) + 1;
        }
        if ($freq === []) {
            return ['coverage' => 0.0, 'terms' => [], 'hits' => []];
        }
        arsort($freq);
        $terms = array_slice(array_keys($freq), 0, $top);

        $have = array_flip(self::tokens($candidateCorpus));
        $hits = array_values(array_filter($terms, fn ($t) => isset($have[$t])));

        return ['coverage' => count($hits) / max(1, count($terms)), 'terms' => $terms, 'hits' => $hits];
    }

    /**
     * Cosine similarity of the two texts under TF-IDF weights (sub-linear term frequency), 0..1.
     * Distinctive shared terms dominate; boilerplate shared by every posting barely counts.
     */
    public static function tfidfSimilarity(string $a, string $b, IdfIndex $index): float
    {
        $va = self::vector($a, $index);
        $vb = self::vector($b, $index);
        if ($va === [] || $vb === []) {
            return 0.0;
        }
        $dot = 0.0;
        foreach ($va as $term => $w) {
            if (isset($vb[$term])) {
                $dot += $w * $vb[$term];
            }
        }
        $na = sqrt(array_sum(array_map(fn ($w) => $w * $w, $va)));
        $nb = sqrt(array_sum(array_map(fn ($w) => $w * $w, $vb)));

        return ($na > 0 && $nb > 0) ? min(1.0, $dot / ($na * $nb)) : 0.0;
    }

    /** @return array<string, float> */
    private static function vector(string $text, IdfIndex $index): array
    {
        $tf = [];
        foreach (self::tokens($text) as $t) {
            $tf[$t] = ($tf[$t] ?? 0) + 1;
        }
        $v = [];
        foreach ($tf as $term => $count) {
            $v[$term] = (1 + log($count)) * $index->idf($term);
        }

        return $v;
    }

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        $text = Str::lower($text);
        preg_match_all('/[\p{L}][\p{L}\p{N}+#.]{3,}/u', $text, $m);
        $out = [];
        foreach ($m[0] as $w) {
            $w = rtrim($w, '.');
            if (mb_strlen($w) >= 4 && ! in_array($w, self::STOP, true)) {
                // crude stem so "developing/developer/development" overlap
                $out[] = preg_replace('/(ing|ers?|ed|es|s|ment|ion)$/u', '', $w) ?: $w;
            }
        }

        return $out;
    }
}
