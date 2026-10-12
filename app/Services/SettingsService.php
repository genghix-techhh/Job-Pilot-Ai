<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Owner-editable preferences stored as key/value rows. Defaults come from
 * config('jobpilot.defaults'). Reads are memoised per request.
 */
class SettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->memo === null) {
            $stored = [];
            try {
                $stored = Setting::query()->pluck('value', 'key')->all();
            } catch (\Throwable) {
                // Table may not exist yet (fresh install before migrations).
            }
            // Top-level replace (not recursive): a saved list such as target_roles must
            // fully override the default list rather than merge with it.
            $this->memo = array_replace(config('jobpilot.defaults', []), $this->unwrap($stored));
        }

        return $this->memo;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
        $this->memo = null;
    }

    /** @param array<string, mixed> $values */
    public function setMany(array $values): void
    {
        foreach ($values as $k => $v) {
            $this->set($k, $v);
        }
    }

    public function flush(): void
    {
        $this->memo = null;
    }

    /**
     * Score weights as positive numbers. They are normalised at scoring time,
     * so any positive set works (the UI recommends totalling 100).
     *
     * @return array<string, float>
     */
    public function weights(): array
    {
        $defaults = config('jobpilot.defaults.weights');
        $w = (array) $this->get('weights', $defaults);
        $out = [];
        foreach ($defaults as $k => $d) {
            $out[$k] = max(0.0, (float) ($w[$k] ?? $d));
        }

        return array_sum($out) > 0 ? $out : array_map('floatval', $defaults);
    }

    public function strongThreshold(): int
    {
        return (int) $this->get('threshold_strong', 80);
    }

    public function potentialThreshold(): int
    {
        return (int) $this->get('threshold_potential', 65);
    }

    public function aiPrefilterThreshold(): int
    {
        return (int) $this->get('threshold_ai_prefilter', 40);
    }

    /** Hash of everything that changes how a score is computed — stored with each match. */
    public function scoringHash(): string
    {
        return sha1(json_encode([
            $this->weights(),
            $this->strongThreshold(),
            $this->potentialThreshold(),
            $this->get('home_country'),
            $this->get('experience_levels'),
            $this->get('target_roles'),
            $this->get('blocked_companies'),
            $this->get('exclude_keywords'),
            $this->get('exclude_below_min_salary'),
        ]));
    }

    /** @param array<string, mixed> $stored */
    private function unwrap(array $stored): array
    {
        $out = [];
        foreach ($stored as $k => $v) {
            $out[$k] = is_array($v) && array_key_exists('v', $v) ? $v['v'] : $v;
        }

        return $out;
    }
}
