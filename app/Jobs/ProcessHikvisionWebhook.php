<?php

namespace App\Jobs;

use App\Models\Camera;
use App\Models\Scopes\SiteScope;
use App\Services\Ingestion\HikvisionAttachment;
use App\Services\Ingestion\HikvisionWebhookParser;
use App\Services\Ingestion\PlateCapture;
use App\Services\Ingestion\PlateEventRecorder;
use App\Services\Ingestion\PlateImageReader;
use App\Support\PlateNumber;
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

    /** Plate/vehicle JPEGs. PruneWebhookStaging deletes them after a day. */
    public const CAPTURES_DIR = 'plate-captures';

    /** Leftover unparseable bodies from when we used to quarantine them. */
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

    public function handle(HikvisionWebhookParser $parser, PlateEventRecorder $recorder, PlateImageReader $plates): void
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
            $disk->delete($this->inboxKey);

            if ($this->isKnownNonAnprAlert($body)) {
                Log::info('Discarded non-ANPR Hikvision webhook', [
                    'camera_id' => $this->cameraId,
                    'content_type' => $this->contentType,
                    'bytes' => strlen($body),
                ]);

                return;
            }

            // Do not log the request body: the XML contains the plate string,
            // which is personal data under POPIA.
            Log::warning('Unparseable Hikvision webhook payload', [
                'camera_id' => $this->cameraId,
                'content_type' => $this->contentType,
                'received_at' => $this->receivedAt,
            ]);

            return;
        }

        $capture = $this->recoverUnknownPlate($event->capture, $event->attachments, $plates);
        $plateEvent = $recorder->record($camera, $capture);

        if ($plateEvent !== null && $event->attachments !== []) {
            $this->storeAttachments($camera, $plateEvent->getKey(), $event->attachments);
        }

        $disk->delete($this->inboxKey);
    }

    /**
     * The camera said it could not read the plate. The JPEG is still in the
     * same post, so try that before the row is stored. A miss leaves the
     * capture as UNKNOWN.
     *
     * @param  list<HikvisionAttachment>  $attachments
     */
    protected function recoverUnknownPlate(PlateCapture $capture, array $attachments, PlateImageReader $plates): PlateCapture
    {
        if (! PlateNumber::isUnknown($capture->plateNumber) || $attachments === []) {
            return $capture;
        }

        try {
            $read = $plates->read($attachments);
        } catch (\Throwable $e) {
            Log::warning('Plate image read failed', [
                'camera_id' => $this->cameraId,
                'error' => $e->getMessage(),
            ]);

            return $capture;
        }

        if ($read === null) {
            return $capture;
        }

        return new PlateCapture(
            plateNumber: $read->plate,
            capturedAt: $capture->capturedAt,
            direction: $capture->direction,
            confidence: $read->confidence,
            rawPayload: $capture->rawPayload,
            originalPlateNumber: $capture->plateNumber,
        );
    }

    /**
     * Save the plate crop and vehicle snapshots next to the event. They
     * live under {camera}/{year}/{month}/{day}/ so a one-day prune can
     * drop a whole folder without walking every remaining event.
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
     * Garbage we do not recognise stays false so it is logged and deleted.
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
     * On terminal failure, drop the staged body. The camera already got 200;
     * we do not keep the payload on disk.
     */
    public function failed(\Throwable $exception): void
    {
        $disk = Storage::disk('local');

        if ($disk->exists($this->inboxKey)) {
            $disk->delete($this->inboxKey);
        }

        Log::error('Hikvision webhook job failed after retries', [
            'camera_id' => $this->cameraId,
            'content_type' => $this->contentType,
            'error' => $exception->getMessage(),
        ]);
    }
}
