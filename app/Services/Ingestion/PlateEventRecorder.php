<?php

namespace App\Services\Ingestion;

use App\Enums\VisitStatus;
use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\Scopes\SiteScope;
use App\Models\Visit;
use App\Support\PlateNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * The single funnel every plate capture passes through, whichever ingestion
 * path produced it.
 *
 * Two things happen here that must not be duplicated elsewhere: dedupe, so a
 * capture arriving on both the alert stream and the FTP sweep is stored once,
 * and fuzzy correction, so a single-character OCR misread is attributed to the
 * vehicle already on site rather than treated as a new plate.
 */
class PlateEventRecorder
{
    /**
     * Returns the stored event, or null when the capture was a duplicate or
     * carried no usable plate.
     */
    public function record(Camera $camera, PlateCapture $capture): ?PlateEvent
    {
        if (! $capture->isUsable()) {
            return null;
        }

        $plate = $capture->plateNumber;
        $corrected = $this->correctMisread($camera, $plate, $capture);

        if ($this->isDuplicate($camera, $corrected, $capture)) {
            $this->touchCamera($camera, $capture);

            return null;
        }

        $direction = $capture->direction ?? $camera->role->impliedDirection();

        try {
            $event = PlateEvent::query()->withoutGlobalScope(SiteScope::class)->create([
                'camera_id' => $camera->getKey(),
                'plate_number' => $corrected,
                'direction' => $direction,
                'captured_at' => $capture->capturedAt,
                'confidence' => $capture->confidence,
                'raw_payload' => $capture->rawPayload ?: null,
                'original_plate_number' => $corrected === $plate ? null : $plate,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The two ingestion paths raced for the same capture.
            $this->touchCamera($camera, $capture);

            return null;
        }

        $this->touchCamera($camera, $capture);

        return $event;
    }

    /**
     * A capture is a duplicate if the same camera already reported the same
     * plate within the dedupe window.
     */
    protected function isDuplicate(Camera $camera, string $plate, PlateCapture $capture): bool
    {
        $window = (int) config('trafficflow.dedupe_window_seconds');

        return PlateEvent::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('camera_id', $camera->getKey())
            ->where('plate_number', $plate)
            ->whereBetween('captured_at', [
                $capture->capturedAt->copy()->subSeconds($window),
                $capture->capturedAt->copy()->addSeconds($window),
            ])
            ->exists();
    }

    /**
     * If this plate is one character away from a vehicle currently on site,
     * assume the OCR misread and attribute the capture to the known plate.
     * Anything else would open a second visit and orphan the first.
     */
    protected function correctMisread(Camera $camera, string $plate, PlateCapture $capture): string
    {
        if (! config('trafficflow.fuzzy_match_enabled')) {
            return $plate;
        }

        // An exact match needs no correcting.
        $exists = Visit::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $camera->site_id)
            ->where('plate_number', $plate)
            ->where('status', VisitStatus::Open)
            ->exists();

        if ($exists) {
            return $plate;
        }

        $candidates = Visit::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $camera->site_id)
            ->where('status', VisitStatus::Open)
            ->where('entered_at', '<=', $capture->capturedAt)
            ->orderByDesc('entered_at')
            ->limit(200)
            ->pluck('plate_number')
            ->unique();

        $matches = $candidates
            ->filter(fn (string $known) => PlateNumber::isProbableMisread($plate, $known))
            ->values();

        // Two equally plausible corrections mean we cannot pick safely, so
        // keep the plate as read and let it stand on its own.
        if ($matches->count() !== 1) {
            return $plate;
        }

        return $matches->first();
    }

    /**
     * Keep the denormalised camera health current so the Cameras page does not
     * have to aggregate plate_events on every render.
     */
    protected function touchCamera(Camera $camera, PlateCapture $capture): void
    {
        if ($camera->last_event_at !== null && $camera->last_event_at->greaterThanOrEqualTo($capture->capturedAt)) {
            return;
        }

        $camera->forceFill([
            'last_event_at' => $capture->capturedAt,
            'last_probe_ok_at' => now(),
            'last_probe_error' => null,
        ])->saveQuietly();
    }

    /**
     * Record a batch, returning how many events were actually stored.
     *
     * @param  iterable<PlateCapture>  $captures
     */
    public function recordMany(Camera $camera, iterable $captures): int
    {
        $stored = 0;

        foreach ($captures as $capture) {
            try {
                if ($this->record($camera, $capture) !== null) {
                    $stored++;
                }
            } catch (\Throwable $e) {
                // Never log the plate itself: it is personal data under POPIA.
                Log::warning('Failed to record plate capture', [
                    'camera_id' => $camera->getKey(),
                    'captured_at' => $capture->capturedAt->toIso8601String(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stored;
    }
}
