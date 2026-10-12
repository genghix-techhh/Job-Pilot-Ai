<?php

namespace App\Enums;

/**
 * Application pipeline. Transitions are explicit and enforced server-side by
 * App\Services\Applications\ApplicationWorkflow, never by controllers.
 */
enum ApplicationStatus: string
{
    case Discovered = 'discovered';
    case Matched = 'matched';
    case DraftReady = 'draft_ready';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case SubmissionInProgress = 'submission_in_progress';
    case Submitted = 'submitted';
    case ResponseReceived = 'response_received';
    case Assessment = 'assessment';
    case Interview = 'interview';
    case Offer = 'offer';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case SubmissionFailed = 'submission_failed';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Discovered => 'Discovered',
            self::Matched => 'Matched',
            self::DraftReady => 'Draft ready',
            self::AwaitingApproval => 'Awaiting approval',
            self::Approved => 'Approved (not submitted)',
            self::SubmissionInProgress => 'Submission in progress',
            self::Submitted => 'Submitted',
            self::ResponseReceived => 'Response received',
            self::Assessment => 'Assessment',
            self::Interview => 'Interview',
            self::Offer => 'Offer',
            self::Rejected => 'Rejected',
            self::Withdrawn => 'Withdrawn',
            self::SubmissionFailed => 'Submission failed',
            self::Closed => 'Closed',
        };
    }

    /** Colour family used by the badge component. */
    public function tone(): string
    {
        return match ($this) {
            self::Discovered, self::Matched, self::Closed, self::Withdrawn => 'slate',
            self::DraftReady, self::AwaitingApproval, self::Approved, self::SubmissionInProgress => 'amber',
            self::Submitted, self::ResponseReceived => 'sky',
            self::Assessment, self::Interview => 'violet',
            self::Offer => 'emerald',
            self::Rejected, self::SubmissionFailed => 'rose',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Discovered => [self::Matched, self::DraftReady, self::Closed],
            self::Matched => [self::DraftReady, self::Closed],
            self::DraftReady => [self::AwaitingApproval, self::Closed, self::Withdrawn],
            self::AwaitingApproval => [self::Approved, self::DraftReady, self::Closed],
            self::Approved => [self::SubmissionInProgress, self::Submitted, self::DraftReady, self::Withdrawn, self::Closed],
            self::SubmissionInProgress => [self::Submitted, self::SubmissionFailed],
            self::SubmissionFailed => [self::Approved, self::DraftReady, self::Closed],
            self::Submitted => [self::ResponseReceived, self::Assessment, self::Interview, self::Offer, self::Rejected, self::Withdrawn, self::Closed],
            self::ResponseReceived => [self::Assessment, self::Interview, self::Offer, self::Rejected, self::Withdrawn, self::Closed],
            self::Assessment => [self::ResponseReceived, self::Interview, self::Offer, self::Rejected, self::Withdrawn, self::Closed],
            self::Interview => [self::Assessment, self::ResponseReceived, self::Offer, self::Rejected, self::Withdrawn, self::Closed],
            self::Offer => [self::Rejected, self::Withdrawn, self::Closed],
            self::Rejected => [self::Closed],
            self::Withdrawn => [self::Closed],
            // Reopening is only allowed while nothing was ever submitted (checked in the workflow).
            self::Closed => [self::DraftReady],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** Statuses that mean the application really went out. */
    public function isPostSubmission(): bool
    {
        return in_array($this, [
            self::Submitted, self::ResponseReceived, self::Assessment,
            self::Interview, self::Offer, self::Rejected,
        ], true);
    }

    /** Statuses in which the draft documents may still be edited. */
    public function isEditable(): bool
    {
        return in_array($this, [
            self::Discovered, self::Matched, self::DraftReady, self::AwaitingApproval,
            self::Approved, self::SubmissionFailed,
        ], true);
    }
}
