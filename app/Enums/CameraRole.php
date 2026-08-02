<?php

namespace App\Enums;

enum CameraRole: string
{
    case Entrance = 'entrance';
    case Exit = 'exit';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Entrance => 'Entrance',
            self::Exit => 'Exit',
            self::Both => 'Entrance & exit',
        };
    }

    /**
     * The direction a capture from this camera implies when the payload does
     * not carry one of its own. A "both" camera cannot be inferred.
     */
    public function impliedDirection(): ?PlateDirection
    {
        return match ($this) {
            self::Entrance => PlateDirection::In,
            self::Exit => PlateDirection::Out,
            self::Both => null,
        };
    }
}
