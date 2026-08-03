<?php

namespace App\Services\Ingestion;

/**
 * A binary part of a Hikvision HTTP Listening POST, typically the plate crop
 * and vehicle snapshot the camera attaches to the XML alert.
 */
final class HikvisionAttachment
{
    public function __construct(
        public readonly string $bytes,
        public readonly ?string $filename,
        public readonly string $contentType,
    ) {}

    /**
     * The file extension implied by the Content-Type, so we do not trust the
     * filename Hikvision sends (which sometimes carries the plate string and
     * therefore must not land in a persisted path).
     */
    public function extensionFromContentType(): string
    {
        return match (strtolower($this->contentType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            default => 'bin',
        };
    }
}
