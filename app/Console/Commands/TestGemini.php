<?php

namespace App\Console\Commands;

use App\Services\Ai\GeminiService;
use Illuminate\Console\Command;

class TestGemini extends Command
{
    protected $signature = 'app:test-gemini';

    protected $description = 'One tiny connectivity call to the configured Gemini model (counts as 1 call; no fallback model, no search tools)';

    public function handle(GeminiService $gemini): int
    {
        $this->line('Model: '.$gemini->model().' (free tier only, no fallback model)');
        $a = $gemini->availability();
        if (! $a->available) {
            $this->error($a->message);

            return self::FAILURE;
        }

        $r = $gemini->connectivityTest();
        $r['ok'] ? $this->info($r['message']) : $this->error($r['message']);

        return $r['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
