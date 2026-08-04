<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessHikvisionWebhook;
use App\Models\Camera;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        $bodyLength = strlen($body);
        $contentType = (string) $request->header('Content-Type');

        // Every request now leaves a fingerprint in the log so we can tell,
        // at a glance, whether a camera is sending real events or just
        // heartbeats. Kept at info level: high volume is expected once a
        // camera goes live.
        Log::info('Hikvision webhook received', [
            'camera_id' => $camera->getKey(),
            'bytes' => $bodyLength,
            'content_type' => $contentType,
        ]);

        // A camera that has just been configured sometimes fires a keepalive
        // POST with no body before its first event. Silently accept so the
        // camera does not treat our endpoint as broken and go into back-off.
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
}
