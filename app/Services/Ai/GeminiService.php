<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use App\Models\CandidateProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use JsonException;

/**
 * The single choke point for every Gemini call (free tier, text generation only).
 *
 * Guarantees, in order, for every call:
 *   1. an identical prompt is answered from cache without spending quota;
 *   2. AI must be enabled, keyed, consented and inside the local budget / pause window;
 *   3. the final prompt is scanned for identifying data and refused if any is found;
 *   4. only the configured model is used — no fallback to another (possibly paid) model,
 *      no grounding/search tools, no extra keys;
 *   5. responses are parsed and validated locally; one repair attempt at most;
 *   6. every HTTP attempt is logged (tokens, latency, status) WITHOUT the prompt.
 */
class GeminiService implements AiProvider
{
    private const CACHE_TTL_DAYS = 14;

    public function __construct(
        private readonly AiBudget $budget,
        private readonly Redactor $redactor,
        private readonly SchemaValidator $validator,
    ) {}

    public function model(): string
    {
        return (string) config('jobpilot.gemini.model');
    }

    public function availability(): AiAvailability
    {
        try {
            $this->guard();

            return AiAvailability::ready();
        } catch (AiException $e) {
            return AiAvailability::from($e);
        }
    }

    public function generateJson(PromptSpec $spec): array
    {
        $cacheKey = $spec->cacheKey($this->model());
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $this->guard();

        // Final privacy gate: the owner's name must never appear in what we send.
        $names = $this->ownerNames();
        $this->redactor->assertClean($spec->system."\n".$spec->user, $names);

        $result = $this->callWithRetries($spec, repair: null);

        Cache::put($cacheKey, $result, now()->addDays(self::CACHE_TTL_DAYS));

        return $result;
    }

    /**
     * One tiny call to the configured model to prove key, model and quota work.
     * Counts as a single call against the local budget; there is no fallback model.
     *
     * @return array{ok: bool, message: string}
     */
    public function connectivityTest(): array
    {
        try {
            $r = $this->generateJson(new PromptSpec(
                'connectivity_test', 'test-1', PromptSpec::GUARD_RULES,
                'Return {"status":"ok"} and nothing else. This is a connectivity test and contains no personal data.',
                ['type' => 'object', 'properties' => ['status' => ['type' => 'string']], 'required' => ['status']],
                null, 64,
            ));

            return ['ok' => true, 'message' => 'Gemini ('.$this->model().') answered: '.json_encode($r)];
        } catch (AiException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @throws AiException */
    private function guard(): void
    {
        if (! config('jobpilot.gemini.enabled')) {
            throw AiException::disabled();
        }
        if (! config('jobpilot.gemini.api_key')) {
            throw AiException::notConfigured();
        }
        $profile = CandidateProfile::query()->first();
        if (! $profile || ! $profile->aiConsentGiven()) {
            throw AiException::noConsent();
        }
        if ($until = $this->budget->pausedUntil()) {
            throw AiException::paused($until, $this->budget->pauseReason() ?? 'rate limit');
        }
        if ($this->budget->exhausted()) {
            throw AiException::budget($this->budget->limit(), $this->budget->nextReset());
        }
    }

    /**
     * @param  ?array{bad: string, errors: list<string>}  $repair
     * @return array<string, mixed>
     */
    private function callWithRetries(PromptSpec $spec, ?array $repair): array
    {
        $maxRetries = max(0, (int) config('jobpilot.gemini.max_retries', 2));
        $useSchema = (bool) config('jobpilot.gemini.use_response_schema', true);
        $attempt = 0;

        while (true) {
            $attempt++;
            $started = microtime(true);
            $status = null;

            try {
                $response = $this->send($spec, $useSchema, $repair);
                $status = $response->status();
            } catch (ConnectionException $e) {
                $this->log($spec, 'error', null, 'timeout', Redactor::scrubSecrets($e->getMessage()), $started);
                if ($attempt <= $maxRetries) {
                    Sleep::for(2 ** ($attempt - 1))->seconds();

                    continue;
                }
                throw AiException::timeout();
            }

            if ($response->successful()) {
                return $this->handleSuccess($spec, $response, $started, $repair);
            }

            $error = $this->errorInfo($response);

            // Some models reject the schema keywords: retry once as prompt-only JSON.
            if ($status === 400 && $useSchema && $this->looksLikeSchemaRejection($error['message'])) {
                $this->log($spec, 'error', $status, 'schema_rejected', $error['message'], $started);
                $useSchema = false;

                continue;
            }

            if ($status === 429) {
                $this->log($spec, 'rate_limited', $status, 'rate_limited', $error['message'], $started);
                $retryAt = $this->handleRateLimit($error);
                $wait = $error['retry_seconds'];
                // Only wait inline for short, clearly temporary limits; otherwise pause and let the queue retry.
                if ($wait !== null && $wait <= 10 && ! $error['daily'] && $attempt <= $maxRetries) {
                    Sleep::for(max(1, (int) ceil($wait)))->seconds();
                    $this->budget->clearPause();

                    continue;
                }
                throw AiException::rateLimited($retryAt, $error['daily'] ? 'The daily free quota is exhausted.' : '');
            }

            if ($status >= 500) {
                $this->log($spec, 'error', $status, 'server_error', $error['message'], $started);
                if ($attempt <= $maxRetries) {
                    Sleep::for(2 ** ($attempt - 1))->seconds();

                    continue;
                }
                throw AiException::server($status);
            }

            $this->log($spec, 'error', $status, 'http_'.$status, $error['message'], $started);

            throw match (true) {
                in_array($status, [401, 403], true) => AiException::auth($status),
                $status === 404 => AiException::modelNotFound($this->model()),
                $status === 400 && Str::contains($error['message'], ['API key', 'API_KEY']) => AiException::auth(400),
                default => AiException::badRequest($error['message']),
            };
        }
    }

    /** @param ?array{bad: string, errors: list<string>} $repair */
    private function send(PromptSpec $spec, bool $useSchema, ?array $repair): Response
    {
        $model = $this->model();
        if (! preg_match('/^[a-z0-9][a-z0-9.\-_]{2,80}$/i', $model)) {
            throw AiException::badRequest('GEMINI_MODEL contains unexpected characters.');
        }

        $userText = $spec->user;
        if ($repair) {
            $userText .= "\n\nYour previous answer was not valid for the required JSON schema:\n- "
                .implode("\n- ", array_slice($repair['errors'], 0, 8))
                ."\nReturn the complete corrected JSON object only.";
        }

        $generation = [
            'temperature' => (float) config('jobpilot.gemini.temperature', 0.2),
            'maxOutputTokens' => $spec->maxOutputTokens ?? (int) config('jobpilot.gemini.max_output_tokens', 2048),
            'responseMimeType' => 'application/json',
        ];
        if ($useSchema) {
            $generation['responseJsonSchema'] = $spec->schema;
        } else {
            // Prompt-only fallback: describe the schema in words so the model still conforms.
            $userText .= "\n\nThe JSON must conform to this JSON Schema:\n".json_encode($spec->schema);
        }

        $body = [
            'systemInstruction' => ['parts' => [['text' => $spec->system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userText]]]],
            'generationConfig' => $generation,
            // Deliberately NO "tools" (no Google Search grounding, no code execution).
        ];

        $url = rtrim((string) config('jobpilot.gemini.base_url'), '/')."/models/{$model}:generateContent";

        return Http::withHeaders(['x-goog-api-key' => (string) config('jobpilot.gemini.api_key')])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('jobpilot.gemini.timeout', 30))
            ->connectTimeout(10)
            ->post($url, $body);
    }

    /**
     * @param  ?array{bad: string, errors: list<string>}  $repair
     * @return array<string, mixed>
     */
    private function handleSuccess(PromptSpec $spec, Response $response, float $started, ?array $repair): array
    {
        $json = $response->json() ?? [];
        $usage = $json['usageMetadata'] ?? [];
        $in = isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null;
        $out = isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null;

        if (! empty($json['promptFeedback']['blockReason'])) {
            $this->log($spec, 'invalid', 200, 'blocked', 'blockReason='.$json['promptFeedback']['blockReason'], $started, $in, $out);
            throw AiException::blocked((string) $json['promptFeedback']['blockReason']);
        }

        $candidate = $json['candidates'][0] ?? null;
        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (! empty($part['thought'])) {
                continue; // never use "thinking" parts as output
            }
            $text .= (string) ($part['text'] ?? '');
        }
        $finish = (string) ($candidate['finishReason'] ?? '');

        $decoded = $this->decode($text);
        $errors = $decoded === null
            ? [$finish === 'MAX_TOKENS' ? 'output was cut off (token budget)' : 'not valid JSON']
            : $this->validator->validate($decoded, $spec->schema);

        if ($errors === []) {
            $this->log($spec, 'ok', 200, null, null, $started, $in, $out);

            return $decoded;
        }

        $this->log($spec, 'invalid', 200, 'invalid_json', implode('; ', array_slice($errors, 0, 3)), $started, $in, $out);

        if ($repair === null && $finish !== 'MAX_TOKENS') {
            // One repair attempt, never more.
            $this->guard();

            return $this->callWithRetries($spec, ['bad' => Str::limit($text, 200, ''), 'errors' => $errors]);
        }

        throw AiException::invalid(implode('; ', array_slice($errors, 0, 3)));
    }

    /** @return array<string, mixed>|null */
    private function decode(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        // Tolerate accidental markdown fences.
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @return array{message: string, retry_seconds: ?float, daily: bool}
     */
    private function errorInfo(Response $response): array
    {
        $json = $response->json();
        $err = is_array($json) ? ($json['error'] ?? []) : [];
        $message = Redactor::scrubSecrets($err['message'] ?? ('HTTP '.$response->status()));

        $retry = null;
        $daily = false;
        foreach ($err['details'] ?? [] as $detail) {
            if (isset($detail['retryDelay']) && preg_match('/^([\d.]+)s$/', (string) $detail['retryDelay'], $m)) {
                $retry = (float) $m[1];
            }
            foreach ($detail['violations'] ?? [] as $v) {
                if (stripos((string) ($v['quotaId'] ?? ''), 'PerDay') !== false) {
                    $daily = true;
                }
            }
        }
        if (stripos($message, 'per day') !== false || stripos($message, 'daily') !== false) {
            $daily = true;
        }

        return ['message' => $message, 'retry_seconds' => $retry, 'daily' => $daily];
    }

    /** @param array{message: string, retry_seconds: ?float, daily: bool} $error */
    private function handleRateLimit(array $error): Carbon
    {
        $until = $error['daily']
            ? $this->budget->nextReset()
            : now()->addSeconds((int) ceil($error['retry_seconds'] ?? 60));

        $this->budget->pause($until, $error['daily'] ? 'daily free quota reached' : 'rate limited by Gemini');

        return $until;
    }

    private function looksLikeSchemaRejection(string $message): bool
    {
        return (bool) preg_match('/schema|responseJsonSchema|response_json_schema|response_mime_type/i', $message);
    }

    private function log(PromptSpec $spec, string $status, ?int $http, ?string $code, ?string $message, float $started, ?int $in = null, ?int $out = null): void
    {
        AiUsageLog::create([
            'provider' => 'gemini',
            'model' => $this->model(),
            'task' => $spec->task,
            'prompt_version' => $spec->version,
            'called_on' => now('UTC')->toDateString(),
            'input_tokens' => $in,
            'output_tokens' => $out,
            'status' => $status,
            'http_status' => $http,
            'error_code' => $code,
            'error_message' => $message ? Redactor::scrubSecrets($message) : null,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'subject_id' => $spec->subjectId,
        ]);
    }

    /** @return list<string> */
    private function ownerNames(): array
    {
        $profile = CandidateProfile::query()->with('user')->first();
        $names = array_filter([
            $profile?->structured['name'] ?? null,
            $profile?->user?->name,
        ]);

        return array_values(array_unique(array_map('strval', $names)));
    }
}
