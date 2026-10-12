<?php

namespace App\Services\Ai;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Every AI failure is mapped to one of these reasons so the UI can show a clear,
 * non-technical status and the queue knows whether retrying later makes sense.
 */
class AiException extends RuntimeException
{
    public const DISABLED = 'disabled';

    public const NOT_CONFIGURED = 'not_configured';

    public const NO_CONSENT = 'no_consent';

    public const BUDGET = 'budget_exhausted';

    public const PAUSED = 'paused';

    public const RATE_LIMITED = 'rate_limited';

    public const AUTH = 'auth';

    public const MODEL_NOT_FOUND = 'model_not_found';

    public const BAD_REQUEST = 'bad_request';

    public const SERVER = 'server_error';

    public const TIMEOUT = 'timeout';

    public const INVALID = 'invalid_response';

    public const BLOCKED = 'blocked';

    public const PRIVACY = 'privacy_guard';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?Carbon $retryAt = null,
    ) {
        parent::__construct($message);
    }

    /** Should the caller try again later (queue release / next scheduler run)? */
    public function isTemporary(): bool
    {
        return in_array($this->reason, [
            self::BUDGET, self::PAUSED, self::RATE_LIMITED, self::SERVER, self::TIMEOUT,
        ], true);
    }

    /** Failures that mean AI simply is not available right now (not an error in our data). */
    public function isUnavailable(): bool
    {
        return in_array($this->reason, [
            self::DISABLED, self::NOT_CONFIGURED, self::NO_CONSENT, self::BUDGET, self::PAUSED,
        ], true);
    }

    public static function disabled(): self
    {
        return new self(self::DISABLED, 'AI features are switched off (GEMINI_ENABLED=false).');
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, 'No Gemini API key is configured. Set GEMINI_API_KEY in the server environment.');
    }

    public static function noConsent(): self
    {
        return new self(self::NO_CONSENT, 'AI processing needs your privacy consent. Review it under Settings → Privacy & AI.');
    }

    public static function budget(int $limit, Carbon $resetsAt): self
    {
        return new self(self::BUDGET, "Local daily AI budget of {$limit} calls is used up. It resets after the quota day ends.", null, $resetsAt);
    }

    public static function paused(Carbon $until, string $why): self
    {
        return new self(self::PAUSED, "AI is paused until {$until->toDayDateTimeString()} UTC ({$why}).", null, $until);
    }

    public static function rateLimited(?Carbon $retryAt, string $detail = ''): self
    {
        return new self(self::RATE_LIMITED, trim('Gemini free-tier rate or quota limit reached. '.$detail), 429, $retryAt);
    }

    public static function auth(int $status): self
    {
        return new self(self::AUTH, 'Gemini rejected the API key or the request is not permitted (HTTP '.$status.'). Check GEMINI_API_KEY and the AI Studio project.', $status);
    }

    public static function modelNotFound(string $model): self
    {
        return new self(self::MODEL_NOT_FOUND, "Model \"{$model}\" was not found or is unavailable to this key. Check GEMINI_MODEL.", 404);
    }

    public static function badRequest(string $detail): self
    {
        return new self(self::BAD_REQUEST, 'Gemini rejected the request: '.$detail, 400);
    }

    public static function server(int $status): self
    {
        return new self(self::SERVER, "Gemini had a temporary server problem (HTTP {$status}).", $status);
    }

    public static function timeout(): self
    {
        return new self(self::TIMEOUT, 'The Gemini request timed out.');
    }

    public static function invalid(string $detail): self
    {
        return new self(self::INVALID, 'Gemini returned a response that did not match the expected format ('.$detail.').');
    }

    public static function blocked(string $why): self
    {
        return new self(self::BLOCKED, 'Gemini declined to process this request ('.$why.').');
    }

    public static function privacy(string $detail): self
    {
        return new self(self::PRIVACY, 'Blocked before sending: '.$detail);
    }
}
