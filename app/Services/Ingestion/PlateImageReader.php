<?php

namespace App\Services\Ingestion;

/**
 * Reads a plate from the JPEGs a camera attached, when the camera's own OCR
 * produced nothing.
 */
interface PlateImageReader
{
    /**
     * @param  list<HikvisionAttachment>  $attachments
     */
    public function read(array $attachments): ?PlateImageRead;
}
