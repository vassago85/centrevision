<?php

namespace App\Support\Analytics;

use App\Models\PlateEvent;
use App\Models\Site;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams every plate detection for one site on one day as CSV.
 *
 * Owner-only — the security page's route middleware already enforces that,
 * and SitePolicy::viewSecurity is checked by the caller. This is the one
 * place plate numbers cross the trust boundary out of the app, so the export
 * is logged for audit and the caller is responsible for authorisation.
 */
class SecurityLogExporter
{
    /**
     * Stream the day's plate events as CSV. Passing a camera id narrows
     * the export to just that camera, so a "download log" click on a
     * filtered view matches what's on screen. The camera is checked
     * against the site to keep a tampered id from producing another
     * tenant's rows.
     */
    public function streamDay(Site $site, CarbonInterface $date, ?int $cameraId = null): StreamedResponse
    {
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        // Silently ignore a camera id that doesn't belong to this site
        // rather than throwing — an out-of-scope filter should degrade
        // to "all cameras" rather than 500 the export.
        if ($cameraId !== null && ! $site->cameras()->whereKey($cameraId)->exists()) {
            $cameraId = null;
        }

        Log::info('Plate log CSV exported', [
            'site_id' => $site->getKey(),
            'camera_id' => $cameraId,
            'date' => $date->toDateString(),
            'user_id' => auth()->id(),
        ]);

        $filename = 'plate-log-'
            .Str::slug($site->name)
            .($cameraId !== null ? '-camera-'.$cameraId : '')
            .'-'.$date->toDateString()
            .'.csv';

        return response()->streamDownload(function () use ($site, $start, $end, $cameraId): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Time', 'Plate', 'Camera', 'Direction', 'Confidence']);

            PlateEvent::query()
                ->forSite($site->getKey())
                ->when($cameraId, fn ($q, $id) => $q->where('camera_id', $id))
                ->with('camera:id,name')
                ->whereBetween('captured_at', [$start, $end])
                ->orderBy('captured_at')
                ->chunk(500, function ($events) use ($handle): void {
                    foreach ($events as $event) {
                        fputcsv($handle, [
                            $event->captured_at->format('Y-m-d H:i:s'),
                            $event->plate_number,
                            $event->camera?->name ?? '',
                            $event->direction?->value ?? '',
                            $event->confidence !== null
                                ? number_format($event->confidence * 100, 1).'%'
                                : '',
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
