<?php

namespace App\Services\Ingestion;

/**
 * A plate recovered from a camera JPEG, with the reader's own confidence (0-1).
 */
final class PlateImageRead
{
    public function __construct(
        public readonly string $plate,
        public readonly float $confidence,
    ) {}
}
