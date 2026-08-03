<?php

namespace App\Services\Ingestion;

use App\Services\Isapi\AlertStreamParser;

/**
 * Turns a Hikvision "HTTP Listening" POST into a PlateCapture plus any image
 * parts the camera attached.
 *
 * The camera posts one of two shapes:
 *
 *  - multipart/form-data, with one text part carrying the alert XML and 0-3
 *    image parts (plate crop, vehicle snapshot, occasional full frame)
 *  - application/xml (or text/xml), with the alert XML as the whole body
 *
 * The XML itself is the same EventNotificationAlert document the ISAPI alert
 * stream sends, so we hand it straight to AlertStreamParser and reuse that
 * logic. This parser is only responsible for MIME plumbing.
 */
class HikvisionWebhookParser
{
    public function __construct(protected AlertStreamParser $xmlParser = new AlertStreamParser) {}

    public function parse(string $rawBody, ?string $contentType): ?ParsedHikvisionEvent
    {
        if ($rawBody === '') {
            return null;
        }

        $contentType = trim((string) $contentType);

        // Some firmware POSTs the XML as the whole body with no MIME wrapping.
        // Recognise it either by header or by sniffing the payload, since
        // reverse proxies have been known to strip the Content-Type.
        if ($this->looksLikeXml($contentType, $rawBody)) {
            $capture = $this->xmlParser->parse($rawBody);

            return $capture !== null ? new ParsedHikvisionEvent($capture, []) : null;
        }

        $boundary = $this->boundaryFrom($contentType);

        if ($boundary === null) {
            return null;
        }

        return $this->parseMultipart($rawBody, $boundary);
    }

    protected function parseMultipart(string $body, string $boundary): ?ParsedHikvisionEvent
    {
        $delimiter = '--'.$boundary;
        $parts = explode($delimiter, $body);

        $capture = null;
        $attachments = [];

        foreach ($parts as $part) {
            $part = ltrim($part, "\r\n");

            // Skip the preamble (before the first boundary) and the epilogue,
            // whose body starts with '--' (end marker) or is empty.
            if ($part === '' || str_starts_with($part, '--')) {
                continue;
            }

            [$headers, $partBody] = $this->splitHeadersAndBody($part);

            if ($partBody === null) {
                continue;
            }

            $partContentType = strtolower($headers['content-type'] ?? '');
            $filename = $this->filenameFrom($headers['content-disposition'] ?? '');

            if ($this->isXml($partContentType, $filename, $partBody)) {
                // First XML part wins. In practice the camera sends exactly one.
                $capture ??= $this->xmlParser->parse($partBody);

                continue;
            }

            if (str_starts_with($partContentType, 'image/')) {
                $attachments[] = new HikvisionAttachment(
                    bytes: $partBody,
                    filename: $filename,
                    contentType: $partContentType,
                );
            }
        }

        if ($capture === null) {
            return null;
        }

        return new ParsedHikvisionEvent($capture, $attachments);
    }

    protected function looksLikeXml(string $contentType, string $body): bool
    {
        // explode() with a non-empty string returns at least one element, so
        // the first index is always present — no need to coalesce.
        $normalised = strtolower(explode(';', $contentType, 2)[0]);

        if (in_array($normalised, ['application/xml', 'text/xml'], strict: true)) {
            return true;
        }

        // Camera dropped the header; sniff the body. The alert XML starts with
        // either a declaration or the root element, sometimes with a BOM.
        $trimmed = ltrim($body, "\xEF\xBB\xBF \t\r\n");

        return str_starts_with($trimmed, '<?xml')
            || str_starts_with($trimmed, '<EventNotificationAlert');
    }

    protected function boundaryFrom(string $contentType): ?string
    {
        if (! preg_match('/boundary\s*=\s*"?([^";]+)"?/i', $contentType, $matches)) {
            return null;
        }

        $boundary = trim($matches[1]);

        return $boundary === '' ? null : $boundary;
    }

    /**
     * @return array{0: array<string, string>, 1: string|null}
     */
    protected function splitHeadersAndBody(string $part): array
    {
        // Header/body separator is a blank line. Accept both CRLF and LF so a
        // proxy that rewrites line endings doesn't wreck parsing.
        $split = preg_split('/\r?\n\r?\n/', $part, 2);

        if ($split === false || count($split) !== 2) {
            return [[], null];
        }

        [$rawHeaders, $body] = $split;
        $headers = [];

        foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
            $pos = strpos($line, ':');

            if ($pos === false) {
                continue;
            }

            $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }

        // Trim the trailing CRLF that precedes the next boundary. Do not touch
        // interior whitespace; image binaries frequently contain 0x0A bytes.
        $body = preg_replace('/\r?\n$/', '', $body) ?? $body;

        return [$headers, $body];
    }

    protected function filenameFrom(string $contentDisposition): ?string
    {
        if (! preg_match('/filename\s*=\s*"?([^";]+)"?/i', $contentDisposition, $matches)) {
            return null;
        }

        $filename = trim($matches[1]);

        return $filename === '' ? null : $filename;
    }

    protected function isXml(string $contentType, ?string $filename, string $body): bool
    {
        if (str_contains($contentType, 'xml')) {
            return true;
        }

        // Fall back to filename or body sniff — Hikvision sometimes labels the
        // XML part as `application/octet-stream` even though it is clearly
        // text, and a reverse proxy occasionally strips the Content-Type
        // altogether.
        if ($filename !== null && str_ends_with(strtolower($filename), '.xml')) {
            return true;
        }

        $trimmed = ltrim($body, "\xEF\xBB\xBF \t\r\n");

        return str_starts_with($trimmed, '<?xml')
            || str_starts_with($trimmed, '<EventNotificationAlert');
    }
}
