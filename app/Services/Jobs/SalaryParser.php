<?php

namespace App\Services\Jobs;

/**
 * Very conservative salary extraction from free text. It only accepts an explicit
 * "<currency> amount – amount" range that sits near pay-related wording, so numbers
 * like "$5 billion revenue" are not mistaken for pay.
 */
class SalaryParser
{
    private const SYMBOLS = ['$' => 'USD', '€' => 'EUR', '£' => 'GBP'];

    /** @return array{min: int, max: int, currency: string, period: string}|null */
    public static function fromText(string $text): ?array
    {
        $re = '/(?<cur>[$€£]|USD|EUR|GBP|CAD|AUD)\s?(?<a>\d{1,3}(?:[,.]\d{3})+|\d{2,3}(?:\.\d)?\s?[kK]|\d{4,7})\s*(?:-|–|—|to)\s*(?:[$€£]|USD|EUR|GBP|CAD|AUD)?\s?(?<b>\d{1,3}(?:[,.]\d{3})+|\d{2,3}(?:\.\d)?\s?[kK]|\d{4,7})/u';
        if (! preg_match_all($re, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($all as $m) {
            $offset = $m[0][1];
            $window = mb_strtolower(substr($text, max(0, $offset - 120), 260));
            if (! preg_match('/salary|compensation|pay range|base pay|annual|per year|\/yr|\/year|hourly|per hour|\/hr|rate|range/', $window)) {
                continue;
            }
            $min = self::amount($m['a'][0]);
            $max = self::amount($m['b'][0]);
            if ($min === null || $max === null || $max < $min) {
                continue;
            }
            $cur = $m['cur'][0];
            $currency = self::SYMBOLS[$cur] ?? strtoupper($cur);
            $period = preg_match('/hour|\/hr|hourly/', $window) ? 'hour' : (preg_match('/month|\/mo\b/', $window) ? 'month' : 'year');

            // Sanity bounds so odd matches (phone fragments, ids) are ignored.
            if ($period === 'year' && ($max < 8000 || $max > 2_000_000)) {
                continue;
            }
            if ($period === 'hour' && ($max < 5 || $max > 1000)) {
                continue;
            }

            return ['min' => $min, 'max' => $max, 'currency' => $currency, 'period' => $period];
        }

        return null;
    }

    private static function amount(string $s): ?int
    {
        $s = trim($s);
        if (preg_match('/^(\d{2,3}(?:\.\d)?)\s?[kK]$/', $s, $m)) {
            return (int) round(((float) $m[1]) * 1000);
        }
        $digits = preg_replace('/[,.]/', '', $s) ?? '';

        return ctype_digit($digits) ? (int) $digits : null;
    }
}
