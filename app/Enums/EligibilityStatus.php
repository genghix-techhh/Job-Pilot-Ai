<?php

namespace App\Enums;

enum EligibilityStatus: string
{
    case Eligible = 'eligible';
    case Unclear = 'unclear';
    case Restricted = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::Eligible => 'Open to your location',
            self::Unclear => 'Eligibility unclear',
            self::Restricted => 'Location restricted',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Eligible => 'emerald',
            self::Unclear => 'amber',
            self::Restricted => 'rose',
        };
    }
}
