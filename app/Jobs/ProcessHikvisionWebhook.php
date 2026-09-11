<?php

namespace App\Jobs;

use App\Models\Camera;
use App\Models\Scopes\SiteScope;
use App\Services\Ingestion\HikvisionAttachment;
use App\Services\Ingestion\HikvisionWebhookParser;
use App\Services\Ingestion\PlateEventRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Parses one staged Hikvision webhook payload and records the plate event.
 *
 * The controller stages the raw body to disk and dispatches this so it can
 * respond to the camera in a couple of milliseconds. Everything that touches
 * the database or the parser runs here on a queue worker, where we do not
 * hold the camera's HTTP connection hostage.
 */
class ProcessHikvisionWebhook implements ShouldQueue
{
    use Queueable;

    /** Attachments are stored under this directory on the local disk. */
    public const CAPTURES_DIR = 'plate-captures';

    /** Unparseable bodies move here so they are not retried forever. */
    public const QUARANTINE_DIR = 'hikvision-webhook-quarantine';

    /**
     * How many attempts the worker gets. The controller has already returned
     * 200 to the camera, so a failure here does not lose the payload from
     * the camera's point of view — we can retry safely from disk.
     */
    public int $tries = 3;

    public function __construct(
        public int $cameraId,
        public string $inboxKey,
        public string $contentType,
        public string $receivedAt,
    ) {}

    public function handle(HikvisionWebhookParser $parser, PlateEventRecorder $recorder): void
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($this->inboxKey)) {
            // A retry after a successful run finds nothing to do. Return
            // cleanly so the queue does not treat this as failure.
            return;
        }

        $camera = Camera::query()
            ->withoutGlobalScope(SiteScope::class)
            ->find($this->cameraId);

        if ($camera === null) {
            // The camera was deleted after the webhook queued. Clean up the
            // staging file so retention pruning does not have to.
            $disk->delete($this->inboxKey);

            return;
        }

        $body = $disk->get($this->inboxKey);

        if ($body === null || $body === '') {
            $disk->delete($this->inboxKey);

            return;
        }

        // The camera reached us and sent bytes, so it is alive regardless of
        // whether we can parse the payload. Tick the health timestamp up
        // front so unparseable alerts (motion, video-loss, vehicle detected
        // without a plate, etc.) still count as proof of life on the
        // Cameras page — the alternative was the operator seeing "Last
        // Seen: 2h ago" for a camera that had been pinging every minute.
        $camera->forceFill(['webhook_last_seen_at' => now()])->saveQuietly();

        $event = $parser->parse($body, $this->contentType);

        if ($event === null) {
            // Motion / video-loss / tamper alerts are valid Hikvision XML
            // but not plates. A busy site with HTTP Listening ticked on
            // those rules would otherwise park a JPEG-bearing body in
            // quarantine on every motion tick and never delete it.
            if ($this->isKnownNonAnprAlert($body)) {
                $disk->delete($this->inboxKey);

                Log::info('Discarded non-ANPR Hikvision webhook', [
                    'camera_id' => $this->cameraId,
                    'content_type' => $this->contentType,
                    'bytes' => strlen($body),
                ]);

                return;
            }

            // Unparseable payloads are quarantined next to the inbox so we
            // can inspect what the camera actually sent without leaving them
            // to be reprocessed forever. PruneWebhookStaging expires them.
            $quarantineKey = self::QUARANTINE_DIR.'/'
                .$this->cameraId.'/'
                .basename($this->inboxKey);

            $disk->move($this->inboxKey, $quarantineKey);

            // Do not log the request body: the XML contains the plate string,
            // which is personal data under POPIA.
            Log::warning('Unparseable Hikvision webhook payload', [
                'camera_id' => $this->cameraId,
                'content_type' => $this->contentType,
                'received_at' => $this->receivedAt,
            ]);

            return;
        }

        $plateEvent = $recorder->record($camera, $event->capture);

        if ($plateEvent !== null && $event->attachments !== []) {
            $this->storeAttachments($camera, $plateEvent->getKey(), $event->attachments);
        }

        $disk->delete($this->inboxKey);
    }

    /**
     * Save the plate crop and vehicle snapshots alongside the event they
     * belong to, using a directory scheme that keeps retention pruning
     * predictable ({camera}/{year}/{month}/{day}/).
     *
     * @param  list<HikvisionAttachment>  $attachments
     */
    protected function storeAttachments(Camera $camera, int $plateEventId, array $attachments): void
    {
        $disk = Storage::disk('local');
        $day = now()->format('Y/m/d');

        $maxAttachmentBytes = (int) config('trafficflow.webhook_max_attachment_bytes');

        foreach ($attachments as $index => $attachment) {
            if ($maxAttachmentBytes > 0 && strlen($attachment->bytes) > $maxAttachmentBytes) {
                continue;
            }

            $key = self::CAPTURES_DIR
                .'/'.$camera->getKey()
                .'/'.$day
                .'/'.$plateEventId.'-'.$index.'.'.$attachment->extensionFromContentType();

            $disk->put($key, $attachment->bytes);
        }
    }

    /**
     * True when the body is a Hikvision EventNotificationAlert that is not
     * a plate read (VMD, videoloss, linedetection, heartbeats with XML).
     * Garbage we do not recognise stays false so it is still quarantined.
     */
    protected function isKnownNonAnprAlert(string $body): bool
    {
        if (! str_contains($body, '<EventNotificationAlert') && ! str_contains($body, '<eventType>')) {
            return false;
        }

        if (str_contains($body, '<ANPR>') || str_contains($body, '<licensePlate>')) {
            return false;
        }

        return true;
    }

    /**
     * On terminal failure (all retries exhausted), quarantine so an operator
     * can review. We still return cleanly rather than throwing so the queue
     * does not hold the payload against retention limits.
     */
    public function failed(\Throwable $exception): void
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($this->inboxKey)) {
            return;
        }

        $disk->move(
            $this->inboxKey,
            self::QUARANTINE_DIR.'/'.$this->cameraId.'/'.basename($this->inboxKey),
        );

        Log::error('Hikvision webhook job failed after retries', [
            'camera_id' => $this->cameraId,
            'content_type' => $this->contentType,
            'error' => $exception->getMessage(),
        ]);
    }
}
