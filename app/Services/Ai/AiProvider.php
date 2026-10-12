<?php

namespace App\Services\Ai;

/**
 * Narrow seam so a different model provider could be swapped in later.
 * Only Gemini is implemented.
 */
interface AiProvider
{
    /** Cheap check used by the UI/queue: can a call be made right now? */
    public function availability(): AiAvailability;

    /**
     * Run one task and return the decoded, schema-validated JSON.
     *
     * @return array<string, mixed>
     *
     * @throws AiException
     */
    public function generateJson(PromptSpec $spec): array;
}
