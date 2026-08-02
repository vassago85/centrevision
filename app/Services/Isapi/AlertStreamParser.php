<?php

namespace App\Services\Isapi;

use App\Enums\PlateDirection;
use App\Services\Ingestion\PlateCapture;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Incremental parser for Hikvision's ISAPI alertStream.
 *
 * The stream is an endless multipart body: chunks arrive at arbitrary
 * boundaries, so the parser buffers whatever it is fed and only emits captures
 * for parts it has received in full.
 */
class AlertStreamParser
{
    protected string $buffer = '';

    /**
     * Guards against a malformed stream growing the buffer without bound.
     */
    protected const MAX_BUFFER_BYTES = 1_048_576;

    /**
     * Feed the next chunk of the stream and take whatever complete ANPR events
     * it completed.
     *
     * @return list<PlateCapture>
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;

        if (strlen($this->buffer) > self::MAX_BUFFER_BYTES) {
            // Keep the tail: a valid part boundary is far more likely to be
            // near the end than in the megabyte of junk before it.
            $this->buffer = substr($this->buffer, -self::MAX_BUFFER_BYTES);
        }

        return $this->drain();
    }

    /**
     * Extract every complete <EventNotificationAlert> document in the buffer.
     *
     * Matching on the XML element rather than the MIME boundary means we do not
     * have to know the boundary token up front, and tolerate cameras that
     * announce a boundary they then fail to use consistently.
     *
     * @return list<PlateCapture>
     */
    protected function drain(): array
    {
        $captures = [];
        $closeTag = '</EventNotificationAlert>';

        while (($end = strpos($this->buffer, $closeTag)) !== false) {
            $documentEnd = $end + strlen($closeTag);
            $document = substr($this->buffer, 0, $documentEnd);
            $this->buffer = substr($this->buffer, $documentEnd);

            $start = strpos($document, '<EventNotificationAlert');

            if ($start === false) {
                continue;
            }

            $capture = $this->parse(substr($document, $start));

            if ($capture !== null) {
                $captures[] = $capture;
            }
        }

        return $captures;
    }

    /**
     * Turn one alert document into a capture, or null if it is not an ANPR
     * event or carries no plate.
     */
    public function parse(string $xml): ?PlateCapture
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $element = simplexml_load_string($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($element === false) {
            return null;
        }

        $data = json_decode((string) json_encode($element), true) ?: [];

        $anpr = $data['ANPR'] ?? null;

        if (! is_array($anpr)) {
            return null;
        }

        $plate = $anpr['licensePlate'] ?? null;

        if (! is_string($plate) || trim($plate) === '') {
            return null;
        }

        $capturedAt = $this->parseTimestamp(
            $anpr['captureTime'] ?? $data['dateTime'] ?? null
        );

        return new PlateCapture(
            plateNumber: $plate,
            capturedAt: $capturedAt,
            direction: PlateDirection::fromIsapi($anpr['direction'] ?? null),
            confidence: $this->parseConfidence($anpr['confidenceLevel'] ?? null),
            rawPayload: $data,
        );
    }

    protected function parseTimestamp(mixed $value): CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return Date::now();
        }

        try {
            return Date::parse($value);
        } catch (\Throwable) {
            return Date::now();
        }
    }

    /**
     * Hikvision reports confidence as 0-100; we store 0-1.
     */
    protected function parseConfidence(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $confidence = (float) $value;

        return $confidence > 1 ? round($confidence / 100, 4) : $confidence;
    }

    public function buffered(): string
    {
        return $this->buffer;
    }
}
