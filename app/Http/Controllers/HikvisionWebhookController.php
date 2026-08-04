<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessHikvisionWebhook;
use App\Models\Camera;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Front door for Hikvision cameras posting events over "HTTP Listening".
 *
 * The camera holds the HTTP request open until we answer, and back-off if we
 * take too long, so we do as little as possible here: authenticate (via
 * AuthenticateHikCamera middleware), stage the raw payload to disk, dispatch
 * a queued job to parse and record it, and return 200. All the work that
 * touches the database happens on the queue worker.
 */
class HikvisionWebhookController
{
    /** Where staged payloads land on the local disk, relative to storage/app/private. */
    public const INBOX_DIR = 'hikvision-webhook-inbox';

    public function __invoke(Request $request): Response
    {
        /** @var Camera $camera */
        $camera = $request->attributes->get('camera');

        $body = $request->getContent();
        $contentType = (string) $request->header('Content-Type');

        // PHP auto-parses `multipart/form-data` request bodies into $_POST /
        // $_FILES before user code runs, which drains php://input. That means
        // $request->getContent() comes back empty even though the camera did
        // send a real multipart payload. Reconstruct it from Laravel's parsed
        // representation so the downstream parser sees the shape it expects.
        if ($body === '' && $this->isMultipart($contentType)) {
            [$body, $contentType] = $this->reconstructMultipart($request, $contentType);
        }

        // Every request leaves a fingerprint in the log so we can tell, at a
        // glance, whether a camera is sending real events or just heartbeats.
        // Kept at info level: high volume is expected once a camera goes live.
        Log::info('Hikvision webhook received', [
            'camera_id' => $camera->getKey(),
            'bytes' => strlen($body),
            'content_type' => $contentType,
            'reconstructed' => $body !== '' && $request->getContent() === '',
        ]);

        // A camera that has just been configured (or one whose Alarm Server
        // is doing periodic heartbeat pings) fires a POST with no body. Bump
        // the last-seen timestamp so the UI can distinguish "camera is alive
        // but idle" from "camera is offline", and answer 200 so the camera
        // does not treat us as broken.
        if ($body === '') {
            $camera->forceFill(['webhook_last_seen_at' => now()])->saveQuietly();

            return response('', 200);
        }

        $inboxKey = self::INBOX_DIR.'/'.$camera->getKey().'/'.Str::ulid().'.bin';

        Storage::disk('local')->put($inboxKey, $body);

        ProcessHikvisionWebhook::dispatch(
            cameraId: $camera->getKey(),
            inboxKey: $inboxKey,
            contentType: $contentType,
            receivedAt: now()->toIso8601String(),
        );

        return response('', 200);
    }

    protected function isMultipart(string $contentType): bool
    {
        return str_starts_with(strtolower(ltrim($contentType)), 'multipart/form-data');
    }

    /**
     * Rebuild a valid `multipart/form-data` body from PHP's parsed $_POST and
     * $_FILES. The camera's original boundary is unrecoverable at this point
     * (PHP has thrown it away), so we invent a fresh one and rewrite the
     * Content-Type header to match. The parser downstream only cares about
     * the boundary in the returned Content-Type, not any specific value.
     *
     * @return array{0: string, 1: string} [reconstructed body, new content type]
     */
    protected function reconstructMultipart(Request $request, string $originalContentType): array
    {
        $post = $request->post();
        $files = $request->allFiles();

        if ($post === [] && $files === []) {
            // Nothing to reconstruct — the camera really did send an empty
            // multipart request (typical heartbeat). Leave the body empty so
            // the keepalive branch handles it.
            return ['', $originalContentType];
        }

        $boundary = 'CentreVisionRebuilt'.Str::random(24);
        $eol = "\r\n";
        $body = '';

        foreach ($post as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                continue;
            }

            $body .= '--'.$boundary.$eol;
            $body .= 'Content-Disposition: form-data; name="'.$name.'"'.$eol;
            $body .= 'Content-Type: '.$this->sniffTextContentType($value).$eol.$eol;
            $body .= $value.$eol;
        }

        foreach ($files as $name => $group) {
            $group = is_array($group) ? $group : [$group];

            foreach ($group as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $filename = $file->getClientOriginalName() !== ''
                    ? $file->getClientOriginalName()
                    : $name;
                $mime = $file->getClientMimeType() ?: 'application/octet-stream';

                $body .= '--'.$boundary.$eol;
                $body .= 'Content-Disposition: form-data; name="'.$name.'"; filename="'.$filename.'"'.$eol;
                $body .= 'Content-Type: '.$mime.$eol.$eol;
                $body .= (string) file_get_contents($file->getRealPath()).$eol;
            }
        }

        $body .= '--'.$boundary.'--'.$eol;

        return [$body, 'multipart/form-data; boundary='.$boundary];
    }

    /**
     * The Hikvision XML part comes in as a plain form field on this firmware,
     * so PHP doesn't know it's XML. Sniff the leading bytes so downstream
     * MIME logic still classifies it correctly.
     */
    protected function sniffTextContentType(string $value): string
    {
        $trimmed = ltrim($value, "\xEF\xBB\xBF \t\r\n");

        if (str_starts_with($trimmed, '<?xml') || str_starts_with($trimmed, '<EventNotificationAlert')) {
            return 'application/xml';
        }

        if ((str_starts_with($trimmed, '{') && str_ends_with(rtrim($trimmed), '}'))
            || (str_starts_with($trimmed, '[') && str_ends_with(rtrim($trimmed), ']'))) {
            return 'application/json';
        }

        return 'text/plain; charset=utf-8';
    }
}
