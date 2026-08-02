<?php

namespace App\Enums;

enum PlateDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Entering',
            self::Out => 'Exiting',
        };
    }

    /**
     * Map the direction strings Hikvision ISAPI payloads use onto our own.
     */
    public static function fromIsapi(?string $value): ?self
    {
        return match (strtolower(trim((string) $value))) {
            'forward', 'in', 'entrance', 'enter', 'approach' => self::In,
            'reverse', 'out', 'exit', 'leave', 'away' => self::Out,
            default => null,
        };
    }
}
