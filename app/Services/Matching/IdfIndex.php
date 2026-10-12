<?php

namespace App\Services\Matching;

use App\Models\JobPosting;
use Illuminate\Support\Facades\Cache;

/**
 * Inverse-document-frequency weights learned from the jobs you have collected, so boilerplate
 * words that appear in every posting ("team", "experience", "remote") count for almost nothing
 * while distinctive ones ("laravel", "terraform", "pytorch") count for a lot.
 *
 * Needs a minimum corpus to be meaningful; below that the scorer falls back to plain overlap.
 */
class IdfIndex
{
    public const MIN_DOCS = 25;

    private const KEY = 'matching.idf.v1';

    /** @var array{n: int, idf: array<string, float>}|null */
    private ?array $memo = null;

    /** @return array{n: int, idf: array<string, float>} */
    public function get(): array
    {
        return $this->memo ??= Cache::remember(self::KEY, now()->addHours(6), fn () => $this->build());
    }

    public function usable(): bool
    {
        return $this->get()['n'] >= self::MIN_DOCS;
    }

    public function invalidate(): void
    {
        $this->memo = null;
        Cache::forget(self::KEY);
    }

    public function idf(string $term): float
    {
        $i = $this->get();

        return $i['idf'][$term] ?? log($i['n'] + 1);
    }

    /** @return array{n: int, idf: array<string, float>} */
    private function build(): array
    {
        $df = [];
        $n = 0;
        JobPosting::query()->where('state', 'active')->whereNull('duplicate_of')->orderByDesc('id')->limit(2500)
            ->select(['id', 'title', 'description'])
            ->chunkById(300, function ($jobs) use (&$df, &$n) {
                foreach ($jobs as $job) {
                    $n++;
                    foreach (array_unique(TextOverlap::tokens($job->title."\n".$job->description)) as $t) {
                        $df[$t] = ($df[$t] ?? 0) + 1;
                    }
                }
            });

        $idf = [];
        foreach ($df as $term => $count) {
            if ($count >= 2) {                       // singletons add memory, not signal
                $idf[$term] = log(($n + 1) / ($count + 0.5));
            }
        }
        arsort($idf);

        return ['n' => $n, 'idf' => array_slice($idf, 0, 30000, true)];
    }
}
