<?php

namespace App\Services\Ingestion;

use App\Enums\PlateDirection;
use App\Support\PlateNumber;
use Carbon\CarbonInterface;

/**
 * One plate sighting, normalised out of whichever source produced it: the
 * ISAPI alert stream or a file in the camera drop folder.
 */
class PlateCapture
{
    public readonly string $plateNumber;

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        string $plateNumber,
        public readonly CarbonInterface $capturedAt,
        public readonly ?PlateDirection $direction = null,
        public readonly ?float $confidence = null,
        public readonly array $rawPayload = [],
    ) {
        $this->plateNumber = PlateNumber::normalise($plateNumber);
    }

    public function isUsable(): bool
    {
        return $this->plateNumber !== '';
    }
}
