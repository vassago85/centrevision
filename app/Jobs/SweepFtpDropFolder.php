<?php

namespace App\Jobs;

use App\Models\Camera;
use App\Models\Scopes\SiteScope;
use App\Services\Ingestion\DropFileParser;
use App\Services\Ingestion\PlateEventRecorder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Reliability fallback for the alert stream: sweeps the folder cameras FTP
 * their captures into, records anything new, and archives what it processed.
 *
 * Captures already recorded by the stream are dropped by the recorder's dedupe,
 * so running both paths at once is safe.
 */
class SweepFtpDropFolder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * A sweep that overruns its five-minute schedule should not stack up.
     */
    public int $uniqueFor = 600;

    /**
     * Extensions treated as capture files. A sidecar .xml of the same stem is
     * read alongside rather than swept on its own.
     */
    protected const CAPTURE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'xml'];

    public function __construct(public ?int $cameraId = null) {}

    public function uniqueId(): string
    {
        return (string) ($this->cameraId ?? 'all');
    }

    public function handle(PlateEventRecorder $recorder, DropFileParser $parser): void
    {
        $root = (string) config('trafficflow.plate_drop_path');

        if (! File::isDirectory($root)) {
            return;
        }

        foreach ($this->cameras() as $camera) {
            $this->sweepCamera($camera, $root, $recorder, $parser);
        }
    }

    /**
     * @return iterable<Camera>
     */
    protected function cameras(): iterable
    {
        return Camera::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('is_active', true)
            ->when($this->cameraId !== null, fn ($query) => $query->whereKey($this->cameraId))
            ->cursor();
    }

    /**
     * Each camera drops into its own subdirectory, named after the camera id.
     */
    protected function sweepCamera(Camera $camera, string $root, PlateEventRecorder $recorder, DropFileParser $parser): void
    {
        $directory = $root.DIRECTORY_SEPARATOR.$camera->getKey();

        if (! File::isDirectory($directory)) {
            return;
        }

        $archive = $directory.DIRECTORY_SEPARATOR.'processed';
        $quarantine = $directory.DIRECTORY_SEPARATOR.'failed';
        File::ensureDirectoryExists($archive);

        foreach (File::files($directory) as $file) {
            $extension = strtolower($file->getExtension());

            if (! in_array($extension, self::CAPTURE_EXTENSIONS, true)) {
                continue;
            }

            // An .xml alongside an image is that image's sidecar, not its own
            // capture, so let the image drive and skip the xml.
            if ($extension === 'xml' && $this->hasImageSibling($file->getPath(), $file->getFilenameWithoutExtension())) {
                continue;
            }

            // Archiving an image also moves its sidecar, which may already have
            // been listed for this pass.
            if (! File::exists($file->getPathname())) {
                continue;
            }

            $this->processFile($camera, $file->getPathname(), $archive, $quarantine, $recorder, $parser);
        }
    }

    protected function hasImageSibling(string $path, string $stem): bool
    {
        foreach (['jpg', 'jpeg', 'png'] as $extension) {
            if (File::exists($path.DIRECTORY_SEPARATOR.$stem.'.'.$extension)) {
                return true;
            }
        }

        return false;
    }

    protected function processFile(Camera $camera, string $path, string $archive, string $quarantine, PlateEventRecorder $recorder, DropFileParser $parser): void
    {
        $filename = basename($path);

        try {
            $capture = $parser->parse($filename, $this->sidecarFor($path));
        } catch (\Throwable $e) {
            // Never log the filename: it contains the plate.
            Log::warning('Failed to parse drop file', [
                'camera_id' => $camera->getKey(),
                'error' => $e->getMessage(),
            ]);

            $capture = null;
        }

        // Quarantine rather than archive, so an operator can see what the
        // camera is producing instead of it silently vanishing, and so the
        // next sweep does not reconsider it forever.
        if ($capture === null) {
            File::ensureDirectoryExists($quarantine);
            $this->move($path, $quarantine);

            return;
        }

        $recorder->record($camera, $capture);

        $this->move($path, $archive);
    }

    protected function sidecarFor(string $path): ?string
    {
        $sidecar = dirname($path).DIRECTORY_SEPARATOR
            .pathinfo($path, PATHINFO_FILENAME).'.xml';

        if ($sidecar === $path || ! File::exists($sidecar)) {
            return str_ends_with(strtolower($path), '.xml') ? File::get($path) : null;
        }

        return File::get($sidecar);
    }

    /**
     * Move the capture and any sidecar out of the way so the next sweep does
     * not reconsider them.
     */
    protected function move(string $path, string $destination): void
    {
        $stem = pathinfo($path, PATHINFO_FILENAME);
        $directory = dirname($path);

        foreach (File::glob($directory.DIRECTORY_SEPARATOR.$stem.'.*') as $related) {
            File::move($related, $destination.DIRECTORY_SEPARATOR.basename($related));
        }
    }
}
