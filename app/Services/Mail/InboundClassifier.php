<?php

namespace App\Services\Mail;

use App\Enums\MessageClass;
use Illuminate\Support\Str;

/** Rule-based e-mail classification: free, offline, and the fallback when Gemini is unavailable. */
class InboundClassifier
{
    /** Cheap gate: does this look like job-search correspondence at all? */
    public function looksRecruiting(string $subject, string $snippet): bool
    {
        return (bool) preg_match('/\b(application|applied|candidate|interview|recruit|hiring|position|role|vacancy|assessment|offer|resume|cv|talent|opportunity|screening|next steps)\b/i', $subject.' '.$snippet);
    }

    /**
     * @return array{class: MessageClass, confidence: float, signals: list<string>}
     */
    public function classify(string $subject, string $text): array
    {
        $t = Str::lower($subject."\n".Str::limit($text, 6000, ''));
        $signals = [];

        $has = function (string $label, string $regex) use ($t, &$signals): bool {
            if (preg_match($regex, $t)) {
                $signals[] = $label;

                return true;
            }

            return false;
        };

        $meeting = (bool) preg_match('~zoom\.us/j/|meet\.google\.com/|teams\.microsoft\.com/l/meetup|calendly\.com/|whereby\.com/|webex\.com/~', $t);

        return match (true) {
            $has('offer', '/pleased to offer|offer letter|would like to offer you|extend (?:you )?an offer|we(?:\'d| would) like to offer/') => $this->out(MessageClass::Offer, 0.85, $signals),
            $has('rejection', '/unfortunately|regret to inform|not (?:be )?moving forward|decided to (?:move|proceed) with other|will not be proceeding|position has been filled|not (?:be )?selected|other candidates/') => $this->out(MessageClass::Rejection, 0.85, $signals),
            $has('assessment', '/assessment|coding (?:test|challenge|exercise)|take-home|hackerrank|codility|codesignal|testgorilla|technical (?:test|exercise|task)|complete the (?:following )?(?:task|test)/') => $this->out(MessageClass::Assessment, 0.8, $signals),
            $has('interview', '/interview|schedule (?:a|an|our)? ?(?:call|chat|time|meeting)|invite you to (?:a |an )?(?:call|chat|meeting)|your availability|book a (?:time|slot)|calendly|phone screen|intro call/') || $meeting => $this->out(MessageClass::InterviewInvite, $meeting ? 0.85 : 0.72, $signals),
            $has('info_request', '/could you (?:please )?(?:provide|send|share|confirm)|please (?:provide|send|share|confirm)|additional information|salary expectations|notice period|right to work|references/') => $this->out(MessageClass::InfoRequest, 0.6, $signals),
            $has('acknowledgement', '/thank you for (?:your )?(?:applying|application|interest)|received your application|we(?:\'ve| have) (?:received|reviewed)|application (?:received|status)/') => $this->out(MessageClass::RecruiterReply, 0.6, $signals),
            default => $this->out(MessageClass::Uncertain, 0.2, $signals),
        };
    }

    /** Meeting links found in the original text (extracted locally; never sent to Gemini). */
    public function meetingUrl(string $text): ?string
    {
        if (preg_match('~https?://(?:[\w.-]*\.)?(?:zoom\.us/j/[^\s<>")]+|meet\.google\.com/[a-z\-]+[^\s<>")]*|teams\.microsoft\.com/l/meetup-join/[^\s<>")]+|calendly\.com/[^\s<>")]+|whereby\.com/[^\s<>")]+|[\w.-]*webex\.com/[^\s<>")]+)~i', $text, $m)) {
            return rtrim($m[0], '.,;');
        }

        return null;
    }

    /** @param list<string> $signals @return array{class: MessageClass, confidence: float, signals: list<string>} */
    private function out(MessageClass $c, float $conf, array $signals): array
    {
        return ['class' => $c, 'confidence' => $conf, 'signals' => $signals];
    }
}
