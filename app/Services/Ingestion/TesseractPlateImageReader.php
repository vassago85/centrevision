<?php

namespace App\Services\Ingestion;

use App\Support\PlateNumber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Asks the bundled plate script to read one JPEG. The script finds the plate
 * in a close-up or in a wider scene photo; this class only accepts a result
 * that looks like a plate and clears the confidence floor.
 *
 * Missing Python or Tesseract is not a failure of the capture. The camera's
 * "unknown" stands, and the next deploy that has the tools starts reading.
 */
class TesseractPlateImageReader implements PlateImageReader
{
    public function read(array $attachments): ?PlateImageRead
    {
        if (! config('trafficflow.plate_image_read_enabled')) {
            return null;
        }

        $script = base_path('scripts/read_plate.py');

        if (! is_file($script)) {
            return null;
        }

        $ordered = $attachments;
        usort($ordered, fn (HikvisionAttachment $a, HikvisionAttachment $b): int => $this->cropRank($a) <=> $this->cropRank($b));

        foreach ($ordered as $attachment) {
            if (! str_starts_with(strtolower($attachment->contentType), 'image/')) {
                continue;
            }

            $read = $this->readOne($script, $attachment);

            if ($read !== null) {
                return $read;
            }
        }

        return null;
    }

    /**
     * Turn one line of script output into a plate, or null when it is not
     * confident enough to store.
     */
    public function interpret(string $output): ?PlateImageRead
    {
        $line = strtoupper(trim($output));

        if ($line === '' || $line === 'NONE') {
            return null;
        }

        if (preg_match('/^([A-Z0-9]{5,10})\s+([0-9.]+)$/', $line, $matches) !== 1) {
            return null;
        }

        $plate = $matches[1];
        $confidence = (float) $matches[2];

        if ($confidence > 1) {
            $confidence = $confidence / 100;
        }

        if ($confidence < (float) config('trafficflow.plate_image_read_min_confidence', 0.7)) {
            return null;
        }

        if (PlateNumber::isUnknown($plate)) {
            return null;
        }

        if (preg_match('/[A-Z]/', $plate) !== 1 || preg_match('/\d/', $plate) !== 1) {
            return null;
        }

        return new PlateImageRead($plate, $confidence);
    }

    protected function readOne(string $script, HikvisionAttachment $attachment): ?PlateImageRead
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cvplate');

        if ($tmp === false) {
            return null;
        }

        $imagePath = $tmp.'.jpg';

        try {
            if (file_put_contents($imagePath, $attachment->bytes) === false) {
                return null;
            }

            $result = Process::timeout(20)->run([
                (string) config('trafficflow.plate_image_read_python', 'python3'),
                $script,
                $imagePath,
            ]);
        } catch (Throwable $e) {
            Log::warning('Plate image reader could not start', [
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            @unlink($imagePath);
            @unlink($tmp);
        }

        if (! $result->successful()) {
            return null;
        }

        return $this->interpret($result->output());
    }

    /**
     * The plate close-up is a much easier read than the full scene photo, so
     * try it first when the camera labelled the file.
     */
    protected function cropRank(HikvisionAttachment $attachment): int
    {
        $name = strtolower((string) $attachment->filename);

        return str_contains($name, 'plate') ? 0 : 1;
    }
}
