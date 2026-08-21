<?php

namespace App\Jobs;

use App\Enums\AlertEventStatus;
use App\Mail\SecurityAlertMail;
use App\Models\AlertEvent;
use App\Support\Alerts\AlertRecipientResolver;
use App\Support\Alerts\AlertSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendAlertMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $alertEventId) {}

    public function handle(): void
    {
        $event = AlertEvent::query()->find($this->alertEventId);

        if ($event === null) {
            return;
        }

        if ($event->status === AlertEventStatus::Sent) {
            return;
        }

        $site = $event->site()->withoutGlobalScopes()->first();

        if ($site === null || ! AlertSettings::for($site)->enabled()) {
            $event->forceFill([
                'status' => AlertEventStatus::Suppressed,
                'error' => 'alerts_disabled',
            ])->save();

            return;
        }

        $recipients = AlertRecipientResolver::emails($site);

        if ($recipients === []) {
            $event->forceFill([
                'status' => AlertEventStatus::Suppressed,
                'error' => 'no_recipients',
            ])->save();

            return;
        }

        try {
            Mail::to($recipients)->send(new SecurityAlertMail($event, $site->name));

            $event->forceFill([
                'status' => AlertEventStatus::Sent,
                'sent_at' => now(),
                'error' => null,
            ])->save();
        } catch (Throwable $e) {
            $event->forceFill([
                'status' => AlertEventStatus::Failed,
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            throw $e;
        }
    }
}
