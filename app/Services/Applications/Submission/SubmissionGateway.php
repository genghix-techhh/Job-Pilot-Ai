<?php

namespace App\Services\Applications\Submission;

use App\Models\Application;

/**
 * An AUTHORISED way to submit an application programmatically (a documented employer or
 * platform API for which the owner holds credentials/permission).
 *
 * None ships with the app: public job-board APIs (Greenhouse, Lever, Remote OK ...) are
 * read-only for candidates, LinkedIn/Indeed/Upwork/Fiverr are intentionally not automated.
 * The interface exists so the approval + idempotency safeguards are enforced in one place
 * if the owner ever adds a legitimate integration.
 */
interface SubmissionGateway
{
    public function name(): string;

    /** Does this gateway have documented, authorised access for this specific application? */
    public function supports(Application $application): bool;

    /** Submit using $application->idempotency_key. Must only return success on a verified response. */
    public function submit(Application $application): SubmissionResult;
}
