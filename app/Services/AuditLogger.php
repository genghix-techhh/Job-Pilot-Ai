<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal security/approval audit trail. Metadata must never contain secrets,
 * CV text or e-mail bodies — callers pass identifiers and hashes only.
 */
class AuditLogger
{
    /** @param array<string, mixed> $metadata */
    public function record(string $action, ?Model $subject = null, array $metadata = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }
}
