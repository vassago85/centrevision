<?php

namespace App\Enums;

enum VisitStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Orphaned = 'orphaned';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'On site',
            self::Closed => 'Departed',
            self::Orphaned => 'No exit recorded',
        };
    }
}
