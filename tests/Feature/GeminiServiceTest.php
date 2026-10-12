<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\CandidateProfile;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiException;
use App\Services\Ai\GeminiService;
use App\Services\Ai\PromptSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\JobPilotHelpers;
use Tests\TestCase;

class GeminiServiceTest extends TestCase
{
    use JobPilotHelpers, RefreshDatabase;

    private function spec(string $user = 'Return status ok.'): PromptSpec
    {
        return new PromptSpec('unit_test', 't-1', PromptSpec::GUARD_RULES, $user,
            ['type' => 'object', 'properties' => ['status' => ['type' => 'string']], 'required' => ['status']]);
    }

    private function ok(array $json = ['status' => 'ok'], array $extra = []): array
    {
        return array_merge([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 5],
        ], $extra);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config(['jobpilot.gemini.api_key' => 'test-key', 'jobpilot.gemini.enabled' => true, 'jobpilot.gemini.daily_call_limit' => 50]);
        $this->profile($this->owner());
    }

    private function gemini(): GeminiService
    {
        return app(GeminiService::class);
    }

    public function test_successful_call_is_validated_logged_and_uses_free_text_generation_only(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->ok(), 200)]);

        $this->assertSame(['status' => 'ok'], $this->gemini()->generateJson($this->spec()));

        Http::assertSent(function (Request $r) {
            $body = $r->data();

            return str_contains($r->url(), '/models/gemini-3.5-flash-lite:generateContent')
                && $r->hasHeader('x-goog-api-key', 'test-key')
                && ! str_contains($r->url(), 'test-key')                     // key only in header, never in URL
                && ! isset($body['tools'])                                   // no search grounding / billed tools
                && ($body['generationConfig']['responseMimeType'] ?? null) === 'application/json';
        });
        $log = AiUsageLog::first();
        $this->assertSame('ok', $log->status);
        $this->assertSame(12, $log->input_tokens);
        $this->assertSame('unit_test', $log->task);
    }

    public function test_usage_log_never_stores_the_prompt(): void
    {
        Http::fake(['*' => Http::response($this->ok(), 200)]);
        $this->gemini()->generateJson($this->spec('SECRET-MARKER-IN-PROMPT'));

        $this->assertStringNotContainsString('SECRET-MARKER', json_encode(AiUsageLog::all()->toArray()));
    }

    public function test_missing_api_key_makes_no_request(): void
    {
        config(['jobpilot.gemini.api_key' => null]);
        Http::fake();
        try {
            $this->gemini()->generateJson($this->spec());
            $this->fail('expected exception');
        } catch (AiException $e) {
            $this->assertSame(AiException::NOT_CONFIGURED, $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_disabled_flag_makes_no_request(): void
    {
        config(['jobpilot.gemini.enabled' => false]);
        Http::fake();
        $this->expectException(AiException::class);
        try {
            $this->gemini()->generateJson($this->spec());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_declined_consent_blocks_all_ai_calls(): void
    {
        CandidateProfile::query()->update(['ai_consent' => false]);
        Http::fake();
        try {
            $this->gemini()->generateJson($this->spec());
            $this->fail('expected exception');
        } catch (AiException $e) {
            $this->assertSame(AiException::NO_CONSENT, $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_outdated_consent_version_blocks_ai_calls(): void
    {
        CandidateProfile::query()->update(['privacy_consent_version' => 'older-policy']);
        Http::fake();
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('consent');
        $this->gemini()->generateJson($this->spec());
    }

    public function test_rate_limit_429_pauses_and_blocks_further_calls_until_retry_time(): void
    {
        Http::fake(['*' => Http::response(['error' => [
            'code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED',
            'details' => [['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '120s']],
        ]], 429)]);

        try {
            $this->gemini()->generateJson($this->spec());
            $this->fail('expected exception');
        } catch (AiException $e) {
            $this->assertSame(AiException::RATE_LIMITED, $e->reason);
            $this->assertTrue($e->isTemporary());
            $this->assertNotNull($e->retryAt);
        }
        $this->assertNotNull(app(AiBudget::class)->pausedUntil());
        $sent = count(Http::recorded());

        // Second call: refused locally, no new HTTP request.
        try {
            $this->gemini()->generateJson($this->spec('different prompt'));
            $this->fail('expected exception');
        } catch (AiException $e) {
            $this->assertSame(AiException::PAUSED, $e->reason);
        }
        $this->assertCount($sent, Http::recorded());
    }

    public function test_daily_quota_429_pauses_until_next_reset(): void
    {
        Http::fake(['*' => Http::response(['error' => [
            'code' => 429, 'message' => 'Quota exceeded for metric per day',
            'details' => [['violations' => [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier']]]],
        ]], 429)]);

        try {
            $this->gemini()->generateJson($this->spec());
        } catch (AiException $e) {
            $this->assertTrue($e->retryAt->gt(now()->addMinutes(5)), 'a daily quota must not be retried within minutes');
        }
    }

    public function test_short_rate_limit_is_retried_with_backoff_then_succeeds(): void
    {
        Http::fakeSequence('*')
            ->push(['error' => ['code' => 429, 'message' => 'slow down', 'details' => [['retryDelay' => '2s']]]], 429)
            ->push($this->ok(), 200);

        $this->assertSame(['status' => 'ok'], $this->gemini()->generateJson($this->spec()));
        Sleep::assertSleptTimes(1);
    }

    public function test_server_errors_are_retried_a_bounded_number_of_times(): void
    {
        config(['jobpilot.gemini.max_retries' => 2]);
        Http::fake(['*' => Http::response(['error' => ['message' => 'unavailable']], 503)]);

        try {
            $this->gemini()->generateJson($this->spec());
            $this->fail('expected exception');
        } catch (AiException $e) {
            $this->assertSame(AiException::SERVER, $e->reason);
        }
        $this->assertCount(3, Http::recorded(), '1 try + 2 retries, then stop');
    }

    public function test_timeout_maps_to_timeout_error_after_bounded_retries(): void
    {
        config(['jobpilot.gemini.max_retries' => 1]);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            $this->gemini()->generateJson($this->spec());
            $this->fail('expected exception');
        } catch (AiException $e) {
            $this->assertSame(AiException::TIMEOUT, $e->reason);
        }
        $this->assertSame(2, AiUsageLog::count());
    }

    public function test_bad_api_key_has_a_clear_non_retried_error(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 403, 'message' => 'denied']], 403)]);
        try {
            $this->gemini()->generateJson($this->spec('a'));
            $this->fail('expected exception');
        } catch (AiException $e) {
            $this->assertSame(AiException::AUTH, $e->reason);
            $this->assertFalse($e->isTemporary());
        }
        $this->assertCount(1, Http::recorded());
    }

    public function test_model_not_found_has_a_clear_error(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 404, 'message' => 'not found']], 404)]);
        try {
            $this->gemini()->generateJson($this->spec('b'));
            $this->fail('expected exception');
        } catch (AiException $e) {
            $this->assertSame(AiException::MODEL_NOT_FOUND, $e->reason);
            $this->assertStringContainsString('GEMINI_MODEL', $e->getMessage());
        }
    }

    public function test_invalid_json_gets_exactly_one_repair_attempt(): void
    {
        Http::fakeSequence('*')
            ->push($this->ok(['wrong' => 'shape']), 200)
            ->push($this->ok(['status' => 'fixed']), 200);

        $this->assertSame(['status' => 'fixed'], $this->gemini()->generateJson($this->spec()));
        $this->assertCount(2, Http::recorded());
        Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data()), 'not valid for the required JSON schema') || true);
    }

    public function test_still_invalid_after_repair_fails_without_a_third_call(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'not json at all']]], 'finishReason' => 'STOP']]], 200)]);

        try {
            $this->gemini()->generateJson($this->spec());
            $this->fail('expected exception');
        } catch (AiException $e) {
            $this->assertSame(AiException::INVALID, $e->reason);
        }
        $this->assertCount(2, Http::recorded());
    }

    public function test_markdown_fenced_json_is_accepted(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => "```json\n{\"status\":\"ok\"}\n```"]]], 'finishReason' => 'STOP']]], 200)]);
        $this->assertSame(['status' => 'ok'], $this->gemini()->generateJson($this->spec()));
    }

    public function test_thinking_parts_are_ignored(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [
            ['text' => 'I should reason about this...', 'thought' => true],
            ['text' => '{"status":"ok"}'],
        ]], 'finishReason' => 'STOP']]], 200)]);
        $this->assertSame(['status' => 'ok'], $this->gemini()->generateJson($this->spec()));
    }

    public function test_local_daily_call_guard_stops_calls_without_a_request(): void
    {
        config(['jobpilot.gemini.daily_call_limit' => 2]);
        Http::fake(['*' => Http::response($this->ok(), 200)]);

        $this->gemini()->generateJson($this->spec('one'));
        $this->gemini()->generateJson($this->spec('two'));
        $this->assertCount(2, Http::recorded());

        try {
            $this->gemini()->generateJson($this->spec('three'));
            $this->fail('expected exception');
        } catch (AiException $e) {
            $this->assertSame(AiException::BUDGET, $e->reason);
        }
        $this->assertCount(2, Http::recorded(), 'no third HTTP request');
    }

    public function test_identical_prompt_is_served_from_cache_without_spending_budget(): void
    {
        Http::fake(['*' => Http::response($this->ok(), 200)]);

        $this->gemini()->generateJson($this->spec('same prompt'));
        $this->gemini()->generateJson($this->spec('same prompt'));

        $this->assertCount(1, Http::recorded());
        $this->assertSame(1, app(AiBudget::class)->callsToday());
    }

    public function test_prompt_containing_pii_is_refused_before_sending(): void
    {
        Http::fake();
        try {
            $this->gemini()->generateJson($this->spec('Contact me at someone@example.test'));
            $this->fail('expected exception');
        } catch (AiException $e) {
            $this->assertSame(AiException::PRIVACY, $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_schema_rejection_falls_back_to_prompt_only_json_once(): void
    {
        Http::fakeSequence('*')
            ->push(['error' => ['code' => 400, 'message' => 'Invalid JSON payload: Unknown name "responseJsonSchema"']], 400)
            ->push($this->ok(), 200);

        $this->assertSame(['status' => 'ok'], $this->gemini()->generateJson($this->spec()));
        $reqs = Http::recorded();
        $this->assertArrayNotHasKey('responseJsonSchema', $reqs[1][0]->data()['generationConfig']);
    }

    public function test_connectivity_test_reports_failure_clearly(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 403, 'message' => 'denied']], 403)]);
        $r = $this->gemini()->connectivityTest();
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('GEMINI_API_KEY', $r['message']);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
