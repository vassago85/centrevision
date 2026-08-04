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
    public function streamDay(Site $site, CarbonInterface $date): StreamedResponse
    {
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        Log::info('Plate log CSV exported', [
            'site_id' => $site->getKey(),
            'date' => $date->toDateString(),
            'user_id' => auth()->id(),
        ]);

        $filename = 'plate-log-'
            .Str::slug($site->name)
            .'-'.$date->toDateString()
            .'.csv';

        return response()->streamDownload(function () use ($site, $start, $end): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Time', 'Plate', 'Camera', 'Direction', 'Confidence']);

            PlateEvent::query()
                ->forSite($site->getKey())
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
