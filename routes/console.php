<?php

use App\Jobs\GenerateMonthlyInvoices;
use App\Jobs\GeneratePartnerPayouts;
use App\Jobs\MatchVisits;
use App\Jobs\PrunePlateData;
use App\Jobs\SendScheduledReports;
use App\Jobs\SweepFtpDropFolder;
use App\Jobs\TagRecurringPlates;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reliability fallback for cameras whose alert stream has dropped.
Schedule::job(new SweepFtpDropFolder)->everyFiveMinutes()->withoutOverlapping();

// Turn raw events into visits. Frequent enough that the Security view is
// meaningfully live, cheap because it only reads unprocessed events.
Schedule::job(new MatchVisits)->everyTwoMinutes()->withoutOverlapping();

// Staff and tenant detection, run overnight when the previous day is complete.
Schedule::job(new TagRecurringPlates)->dailyAt('02:15');

// POPIA retention.
Schedule::job(new PrunePlateData)->dailyAt('03:30');

// Each site decides for itself whether a report is due today.
Schedule::job(new SendScheduledReports)->dailyAt(sprintf('%02d:00', config('trafficflow.report_send_hour')));

// Bill for the month just finished, once its shop counts are final.
Schedule::job(new GenerateMonthlyInvoices)->monthlyOn(1, '04:00');

// Commission, a week later, so most of the month's invoices have settled.
Schedule::job(new GeneratePartnerPayouts)->monthlyOn(8, '04:30');
