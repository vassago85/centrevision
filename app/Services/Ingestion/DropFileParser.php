<?php

namespace App\Services\Ingestion;

use App\Enums\PlateDirection;
use App\Services\Isapi\AlertStreamParser;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Reads captures out of the files cameras drop over FTP.
 *
 * Two layouts are supported: a sidecar XML file carrying the same
 * EventNotificationAlert document the alert stream sends, and — when the camera
 * only uploads images — the encoded filename Hikvision uses:
 *
 *   ANPR_<plate>_<yyyyMMddHHmmss>_<direction>_<confidence>.jpg
 */
class DropFileParser
{
    public function __construct(protected AlertStreamParser $xmlParser = new AlertStreamParser) {}

    /**
     * @param  string|null  $sidecarXml  Contents of the matching .xml file.
     */
    public function parse(string $filename, ?string $sidecarXml = null): ?PlateCapture
    {
        if ($sidecarXml !== null && trim($sidecarXml) !== '') {
            $capture = $this->xmlParser->parse($sidecarXml);

            if ($capture !== null) {
                return $capture;
            }
        }

        return $this->parseFilename($filename);
    }

    protected function parseFilename(string $filename): ?PlateCapture
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $parts = explode('_', $stem);

        // Drop a leading marker such as "ANPR" or the channel name.
        if (count($parts) > 2 && ! preg_match('/^\d{14}$/', $parts[0])) {
            array_shift($parts);
        }

        $plate = $parts[0] ?? null;
        $timestamp = $parts[1] ?? null;

        if (! is_string($plate) || $plate === '' || ! is_string($timestamp)) {
            return null;
        }

        $capturedAt = $this->parseTimestamp($timestamp);

        if ($capturedAt === null) {
            return null;
        }

        return new PlateCapture(
            plateNumber: $plate,
            capturedAt: $capturedAt,
            direction: PlateDirection::fromIsapi($parts[2] ?? null),
            confidence: isset($parts[3]) && is_numeric($parts[3])
                ? round(((float) $parts[3]) / 100, 4)
                : null,
            rawPayload: ['source' => 'drop_folder', 'filename' => $filename],
        );
    }

    protected function parseTimestamp(string $value): ?CarbonInterface
    {
        if (preg_match('/^\d{14}$/', $value) !== 1) {
            return null;
        }

        try {
            return Date::createFromFormat('YmdHis', $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
