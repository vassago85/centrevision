<?php

namespace App\Jobs;

use App\Enums\AlertEventStatus;
use App\Models\AlertEvent;
use App\Models\Scopes\SiteScope;
use App\Support\Alerts\AlertSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FlushPendingAlertEvents implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return 'flush-pending-alerts';
    }

    public function handle(): void
    {
        AlertEvent::query()
            ->where('status', AlertEventStatus::Pending)
            ->where(function ($q) {
                $q->whereNull('send_after')->orWhere('send_after', '<=', now());
            })
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (AlertEvent $event): void {
                $site = $event->site()->withoutGlobalScope(SiteScope::class)->first();

                if ($site === null || ! AlertSettings::for($site)->enabled()) {
                    $event->forceFill([
                        'status' => AlertEventStatus::Suppressed,
                        'error' => 'alerts_disabled',
                    ])->save();

                    return;
                }

                SendAlertMail::dispatch($event->getKey());
            });
    }
}
