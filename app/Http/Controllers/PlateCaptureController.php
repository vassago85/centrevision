<?php

namespace App\Http\Controllers;

use App\Models\PlateEvent;
use App\Support\Ingestion\PlateCaptureStore;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stream a plate/vehicle JPEG that is still on disk (kept for one day).
 *
 * Auth + the plate-data policy apply: shops never see these, and an owner
 * can only fetch events from sites they can reach.
 */
class PlateCaptureController
{
    public function __invoke(PlateEvent $event, int $index, PlateCaptureStore $store): Response
    {
        Gate::authorize('view', $event->loadMissing('camera'));

        $path = $store->pathFor($event, $index);

        abort_if($path === null, 404);

        $disk = Storage::disk('local');

        return response($disk->get($path), 200, [
            'Content-Type' => $this->contentType($path),
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    protected function contentType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }
}
