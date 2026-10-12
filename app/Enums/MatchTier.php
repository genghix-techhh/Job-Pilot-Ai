<?php

namespace App\Enums;

enum MatchTier: string
{
    case Strong = 'strong';
    case Potential = 'potential';
    case Low = 'low';
    case Unscored = 'unscored';

    public function label(): string
    {
        return match ($this) {
            self::Strong => 'Strong match',
            self::Potential => 'Potential match',
            self::Low => 'Low match',
            self::Unscored => 'Not scored',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Strong => 'emerald',
            self::Potential => 'amber',
            self::Low, self::Unscored => 'slate',
        };
    }
}
