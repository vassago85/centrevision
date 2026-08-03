<?php

namespace App\Services\Ingestion;

/**
 * The two things every Hikvision HTTP Listening POST decomposes into: a plate
 * capture the recorder can consume, and any image parts the camera included.
 */
final class ParsedHikvisionEvent
{
    /**
     * @param  list<HikvisionAttachment>  $attachments
     */
    public function __construct(
        public readonly PlateCapture $capture,
        public readonly array $attachments = [],
    ) {}
}
