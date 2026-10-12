<?php

namespace App\Enums;

enum MessageClass: string
{
    case RecruiterReply = 'recruiter_reply';
    case InterviewInvite = 'interview_invite';
    case Assessment = 'assessment';
    case Rejection = 'rejection';
    case Offer = 'offer';
    case InfoRequest = 'info_request';
    case Unrelated = 'unrelated';
    case Uncertain = 'uncertain';

    public function label(): string
    {
        return match ($this) {
            self::RecruiterReply => 'Recruiter reply',
            self::InterviewInvite => 'Interview invite',
            self::Assessment => 'Assessment / test',
            self::Rejection => 'Rejection',
            self::Offer => 'Offer',
            self::InfoRequest => 'Request for information',
            self::Unrelated => 'Unrelated',
            self::Uncertain => 'Uncertain',
        };
    }

    /** The application status this class suggests (only applied for a confidently matched application). */
    public function impliedStatus(): ?ApplicationStatus
    {
        return match ($this) {
            self::RecruiterReply, self::InfoRequest => ApplicationStatus::ResponseReceived,
            self::InterviewInvite => ApplicationStatus::Interview,
            self::Assessment => ApplicationStatus::Assessment,
            self::Rejection => ApplicationStatus::Rejected,
            self::Offer => ApplicationStatus::Offer,
            default => null,
        };
    }
}
