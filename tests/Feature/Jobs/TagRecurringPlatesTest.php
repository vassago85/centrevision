<?php

use App\Enums\PlateTagType;
use App\Enums\WatchlistKind;
use App\Jobs\TagRecurringPlates;
use App\Models\PlateTag;
use App\Models\Site;
use App\Models\Visit;
use App\Models\WatchlistPlate;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    // Pin the clock to a Friday so the 28-day window always contains the same
    // number of weekdays and the ratios in these tests stay stable.
    Date::setTestNow(Date::parse('2026-07-31 20:00:00'));

    $this->site = Site::factory()->create();
});

afterEach(fn () => Date::setTestNow());

/**
 * Create a weekday arrival history for one plate.
 *
 * @param  int  $arriveAtMinute  Minutes past the hour of the habitual arrival.
 * @param  int  $jitterMinutes  Maximum spread either side of that time.
 */
function arrivals(
    Site $site,
    string $plate,
    int $weekdays,
    int $arriveAtHour = 8,
    int $arriveAtMinute = 0,
    int $jitterMinutes = 0,
): void {
    $created = 0;
    $day = now()->startOfDay();

    while ($created < $weekdays) {
        $day = $day->subDay();

        if ($day->isWeekend()) {
            continue;
        }

        $offset = $jitterMinutes === 0
            ? 0
            : (($created % 2 === 0 ? 1 : -1) * $jitterMinutes);

        Visit::factory()->for($site)->plateNumber($plate)->create([
            'entered_at' => $day->copy()->setTime($arriveAtHour, $arriveAtMinute)->addMinutes($offset),
        ]);

        $created++;
    }
}

function isTagged(Site $site, string $plate): bool
{
    return PlateTag::query()
        ->where('site_id', $site->id)
        ->where('plate_number', $plate)
        ->where('tag', PlateTagType::RecurringPattern)
        ->exists();
}

it('tags a plate that arrives on most weekdays at a consistent time', function () {
    arrivals($this->site, 'SF01GP', weekdays: 19, arriveAtHour: 7, arriveAtMinute: 30, jitterMinutes: 6);

    TagRecurringPlates::dispatchSync();

    expect(isTagged($this->site, 'SF01GP'))->toBeTrue();
});

it('records the evidence behind a tag', function () {
    arrivals($this->site, 'SF01GP', weekdays: 19, arriveAtHour: 7, arriveAtMinute: 30, jitterMinutes: 6);

    TagRecurringPlates::dispatchSync();

    $evidence = PlateTag::query()->sole()->evidence;

    expect($evidence)->toHaveKeys(['weekdays_present', 'weekdays_in_window', 'arrival_stddev_minutes', 'window_days'])
        ->and($evidence['weekdays_present'])->toBe(19)
        ->and($evidence['arrival_stddev_minutes'])->toBeLessThan(30);
});

it('does not tag a plate present on too few weekdays', function () {
    // Present, and always at the same time, but only half the weekdays: a
    // part-timer or a frequent shopper, not a daily staff pattern.
    arrivals($this->site, 'JD45GP', weekdays: 10, arriveAtHour: 7, arriveAtMinute: 30, jitterMinutes: 4);

    TagRecurringPlates::dispatchSync();

    expect(isTagged($this->site, 'JD45GP'))->toBeFalse();
});

it('does not tag a frequent visitor whose arrival time is scattered', function () {
    // Present nearly every weekday but arriving anywhere across the day, which
    // is a loyal shopper rather than staff.
    $created = 0;
    $day = now()->startOfDay();
    $hours = [9, 14, 11, 17, 8, 19, 12, 16, 10, 18, 13, 20, 9, 15, 11, 17, 8, 19, 12];

    while ($created < 19) {
        $day = $day->subDay();

        if ($day->isWeekend()) {
            continue;
        }

        Visit::factory()->for($this->site)->plateNumber('HK12GP')->create([
            'entered_at' => $day->copy()->setTime($hours[$created], 15),
        ]);

        $created++;
    }

    TagRecurringPlates::dispatchSync();

    expect(isTagged($this->site, 'HK12GP'))->toBeFalse();
});

it('does not tag a near miss that is just outside the arrival deviation', function () {
    // Enough days, but arrival swings 45 minutes either side of the mean.
    arrivals($this->site, 'BX91GP', weekdays: 19, arriveAtHour: 8, arriveAtMinute: 0, jitterMinutes: 45);

    TagRecurringPlates::dispatchSync();

    expect(isTagged($this->site, 'BX91GP'))->toBeFalse();
});

it('does not tag a plate seen only once', function () {
    Visit::factory()->for($this->site)->plateNumber('TZ18GP')->create([
        'entered_at' => now()->subDays(3)->setTime(8, 0),
    ]);

    TagRecurringPlates::dispatchSync();

    expect(isTagged($this->site, 'TZ18GP'))->toBeFalse();
});

it('ignores weekend visits when judging the pattern', function () {
    // Weekend-only regular: never present on a weekday, so never tagged.
    for ($week = 0; $week < 4; $week++) {
        Visit::factory()->for($this->site)->plateNumber('MP63GP')->create([
            'entered_at' => now()->subWeeks($week)->startOfWeek()->addDays(5)->setTime(10, 0),
        ]);
        Visit::factory()->for($this->site)->plateNumber('MP63GP')->create([
            'entered_at' => now()->subWeeks($week)->startOfWeek()->addDays(6)->setTime(10, 0),
        ]);
    }

    TagRecurringPlates::dispatchSync();

    expect(isTagged($this->site, 'MP63GP'))->toBeFalse();
});

it('honours a site that tightens the thresholds', function () {
    arrivals($this->site, 'SF01GP', weekdays: 19, arriveAtHour: 7, arriveAtMinute: 30, jitterMinutes: 6);

    $this->site->update(['settings' => ['recurring_max_arrival_stddev_minutes' => 2]]);

    TagRecurringPlates::dispatchSync();

    expect(isTagged($this->site, 'SF01GP'))->toBeFalse();
});

it('removes the tag once the pattern stops', function () {
    arrivals($this->site, 'SF01GP', weekdays: 19, arriveAtHour: 7, arriveAtMinute: 30, jitterMinutes: 6);

    TagRecurringPlates::dispatchSync();
    expect(isTagged($this->site, 'SF01GP'))->toBeTrue();

    // The staff member leaves: their history ages out of the window.
    Visit::query()->where('plate_number', 'SF01GP')->delete();

    TagRecurringPlates::dispatchSync();

    expect(isTagged($this->site, 'SF01GP'))->toBeFalse();
});

it('does not duplicate a tag on a second run', function () {
    arrivals($this->site, 'SF01GP', weekdays: 19, arriveAtHour: 7, arriveAtMinute: 30, jitterMinutes: 6);

    TagRecurringPlates::dispatchSync();
    TagRecurringPlates::dispatchSync();

    expect(PlateTag::query()->count())->toBe(1);
});

it('leaves a manually managed watchlist entry alone', function () {
    // The recurring-pattern job speaks only its own language — a plate on the
    // user-managed watchlist must never be silently reclassified or removed.
    WatchlistPlate::factory()->watch()->create([
        'site_id' => $this->site->id,
        'plate_number' => 'JD45GP',
    ]);

    TagRecurringPlates::dispatchSync();

    expect(WatchlistPlate::query()->where('kind', WatchlistKind::Watch)->count())->toBe(1);
});

it('judges each site on its own history', function () {
    $otherSite = Site::factory()->create();

    arrivals($this->site, 'SF01GP', weekdays: 19, arriveAtHour: 7, arriveAtMinute: 30, jitterMinutes: 6);
    arrivals($otherSite, 'SF01GP', weekdays: 4, arriveAtHour: 7, arriveAtMinute: 30);

    TagRecurringPlates::dispatchSync();

    expect(isTagged($this->site, 'SF01GP'))->toBeTrue()
        ->and(isTagged($otherSite, 'SF01GP'))->toBeFalse();
});
