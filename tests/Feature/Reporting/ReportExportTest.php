<?php

use App\Enums\ReportSchedule;
use App\Jobs\SendScheduledReports;
use App\Mail\ScheduledReportMail;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Support\Analytics\DateRange;
use App\Support\Analytics\TrafficAnalytics;
use App\Support\Reporting\ReportExporter;
use App\Support\Reporting\TrafficReport;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Date::setTestNow('2026-07-15 12:00:00');

    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create(['name' => 'Mall A']);

    Visit::factory()->count(4)->for($this->site)->create([
        'entered_at' => now()->subDays(2)->setTime(10, 0),
        'exited_at' => now()->subDays(2)->setTime(11, 0),
        'dwell_minutes' => 60,
    ]);

    $this->user = actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
});

function trafficReport(string $rangeKey = '7d'): TrafficReport
{
    return new TrafficReport(app(TrafficAnalytics::class), DateRange::make($rangeKey), 'Mall A');
}

it('writes the summary and every breakdown into the csv', function () {
    $csv = app(ReportExporter::class)->csv(trafficReport());

    expect($csv)->toContain(config('app.name').' report — Mall A — Last 7 days')
        ->and($csv)->toContain('"Total visits",4')
        ->and($csv)->toContain('Visits per day')
        ->and($csv)->toContain('Visits by hour')
        ->and($csv)->toContain('Dwell distribution')
        ->and($csv)->toContain('10:00,4');
});

it('never puts a plate number in an export', function () {
    $plate = Visit::query()->first()->plate_number;

    expect(app(ReportExporter::class)->csv(trafficReport()))->not->toContain($plate);
});

it('renders a pdf', function () {
    $pdf = app(ReportExporter::class)->pdf(trafficReport());

    expect($pdf)->toStartWith('%PDF-');
});

it('names the file after the site and the period', function () {
    expect(trafficReport()->filename('csv'))->toBe('mall-a-last-7-days-20260715.csv');
});

it('downloads the report from the reports page', function () {
    app(Tenancy::class)->setCurrentSiteId($this->site->id);

    Livewire::test('pages::reports')
        ->set('rangeKey', '7d')
        ->call('exportCsv')
        ->assertFileDownloaded('mall-a-last-7-days-20260715.csv');
});

it('emails a site whose schedule is due today', function () {
    Mail::fake();
    Date::setTestNow('2026-07-20 06:00:00'); // A Monday.

    $this->site->update(['settings' => [
        'report_schedule' => ReportSchedule::Weekly->value,
        'report_recipients' => ['manager@example.com'],
    ]]);

    (new SendScheduledReports)->handle(app(Tenancy::class));

    Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $mail) => $mail->hasTo('manager@example.com')
        && $mail->siteName === 'Mall A');
});

it('does not email on a day the schedule does not fall on', function () {
    Mail::fake();
    Date::setTestNow('2026-07-21 06:00:00'); // A Tuesday.

    $this->site->update(['settings' => [
        'report_schedule' => ReportSchedule::Weekly->value,
        'report_recipients' => ['manager@example.com'],
    ]]);

    (new SendScheduledReports)->handle(app(Tenancy::class));

    Mail::assertNothingSent();
});

it('does not email a site with no recipients', function () {
    Mail::fake();
    Date::setTestNow('2026-07-20 06:00:00');

    $this->site->update(['settings' => ['report_schedule' => ReportSchedule::Weekly->value]]);

    (new SendScheduledReports)->handle(app(Tenancy::class));

    Mail::assertNothingSent();
});

it('reports on one site only, even when the owner runs several', function () {
    Mail::fake();
    Date::setTestNow('2026-08-01 06:00:00');

    $second = Site::factory()->for_($this->owner)->create(['name' => 'Mall B']);

    Visit::factory()->count(9)->for($second)->create([
        'entered_at' => now()->subDays(3)->setTime(14, 0),
        'exited_at' => now()->subDays(3)->setTime(15, 0),
        'dwell_minutes' => 60,
    ]);

    $second->update(['settings' => [
        'report_schedule' => ReportSchedule::Monthly->value,
        'report_recipients' => ['b@example.com'],
    ]]);

    (new SendScheduledReports)->handle(app(Tenancy::class));

    Mail::assertSent(ScheduledReportMail::class, function (ScheduledReportMail $mail) use ($second) {
        // The figures were gathered while the job was pinned to Mall B, so
        // reading them back needs the same pin.
        $total = app(Tenancy::class)->forSite($second, fn () => $mail->report->summary()['total']);

        return $mail->hasTo('b@example.com') && $mail->siteName === 'Mall B' && $total === 9;
    });
});

it('saves the schedule and normalises the recipient list', function () {
    Livewire::test('pages::settings')
        ->set('siteId', $this->site->id)
        ->set('reportSchedule', ReportSchedule::Monthly->value)
        ->set('reportRecipients', 'Manager@Example.com,  ops@example.com; manager@example.com')
        ->call('saveReportSchedule')
        ->assertHasNoErrors();

    expect($this->site->fresh()->reportRecipients())->toBe(['manager@example.com', 'ops@example.com'])
        ->and($this->site->fresh()->reportSchedule())->toBe(ReportSchedule::Monthly);
});

it('rejects a recipient that is not an email address', function () {
    Livewire::test('pages::settings')
        ->set('siteId', $this->site->id)
        ->set('reportSchedule', ReportSchedule::Weekly->value)
        ->set('reportRecipients', 'not-an-address')
        ->call('saveReportSchedule')
        ->assertHasErrors();

    expect($this->site->fresh()->reportRecipients())->toBe([]);
});

it('keeps the report settings when the thresholds form is saved', function () {
    $this->site->update(['settings' => [
        'report_schedule' => ReportSchedule::Weekly->value,
        'report_recipients' => ['manager@example.com'],
    ]]);

    Livewire::test('pages::settings')
        ->set('siteId', $this->site->id)
        ->set('name', 'Mall A')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->site->fresh()->reportRecipients())->toBe(['manager@example.com']);
});
