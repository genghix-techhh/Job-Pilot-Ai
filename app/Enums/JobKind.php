<?php

namespace App\Enums;

enum JobKind: string
{
    case Employment = 'employment';
    case Freelance = 'freelance';

    public function label(): string
    {
        return match ($this) {
            self::Employment => 'Employment',
            self::Freelance => 'Freelance project',
        };
    }
}
