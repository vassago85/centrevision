<?php

namespace App\Support;

/**
 * Camera payloads and pasted names sometimes arrive as Latin-1 or raw bytes.
 * Livewire then JSON-encodes the page snapshot and 500s with
 * "Malformed UTF-8 characters".
 */
final class Utf8
{
    public static function clean(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = mb_convert_encoding($value, 'UTF-8', 'UTF-8, Windows-1252, ISO-8859-1');

        return is_string($converted) ? $converted : '';
    }
}
