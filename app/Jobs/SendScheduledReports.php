<?php

namespace App\Jobs;

use App\Mail\ScheduledReportMail;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\Analytics\DateRange;
use App\Support\Analytics\TrafficAnalytics;
use App\Support\Reporting\TrafficReport;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails each site's traffic report to the addresses configured in Settings.
 *
 * Runs daily and asks each site whether it is due, rather than keeping several
 * schedules: a site that switches from weekly to monthly then takes effect
 * immediately instead of on the next scheduler reload.
 */
class SendScheduledReports implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(public ?int $siteId = null) {}

    public function uniqueId(): string
    {
        return (string) ($this->siteId ?? 'all');
    }

    public function handle(Tenancy $tenancy): void
    {
        $today = Date::now();

        $sites = Site::query()
            ->withoutGlobalScope(SiteScope::class)
            ->when($this->siteId !== null, fn ($query) => $query->whereKey($this->siteId))
            ->get();

        foreach ($sites as $site) {
            $schedule = $site->reportSchedule();
            $recipients = $site->reportRecipients();

            if ($recipients === [] || ! $schedule->isDueOn($today)) {
                continue;
            }

            $this->send($tenancy, $site, $schedule->rangeKey(), $recipients);
        }
    }

    /**
     * @param  array<int, string>  $recipients
     */
    protected function send(Tenancy $tenancy, Site $site, string $rangeKey, array $recipients): void
    {
        // The analytics queries read through the tenant scope, so the job
        // borrows the site's context rather than reaching across every tenant.
        $tenancy->forSite($site, function () use ($site, $rangeKey, $recipients): void {
            $report = new TrafficReport(
                app(TrafficAnalytics::class),
                DateRange::make($rangeKey),
                $site->name,
            );

            Mail::to($recipients)->send(new ScheduledReportMail($report, $site->name));

            Log::info('Sent scheduled traffic report', [
                'site_id' => $site->getKey(),
                'recipients' => count($recipients),
            ]);
        });
    }
}
