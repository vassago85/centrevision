<?php

use App\Support\Analytics\DateRange;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    Date::setTestNow('2026-08-24 15:30:00');
});

it('offers only today and last 7 days on the live dashboard', function () {
    // Longer windows (30d / 90d / month) live on Reports so Dashboard stays
    // "what is happening now" rather than a second historical view.
    expect(DateRange::options())->toBe([
        'today' => 'Today',
        '7d' => 'Last 7 days',
    ]);
});

it('keeps the wider reporting window list independent of the dashboard', function () {
    expect(DateRange::reportOptions())->toHaveKeys([
        'today', 'yesterday', '7d', '30d', 'this_month', 'last_month', 'custom',
    ]);
});

it('builds yesterday, this month and last month from the named keys', function () {
    $yesterday = DateRange::make('yesterday');
    $thisMonth = DateRange::make('this_month');
    $lastMonth = DateRange::make('last_month');

    expect($yesterday->from->toDateTimeString())->toBe('2026-08-23 00:00:00')
        ->and($yesterday->to->toDateTimeString())->toBe('2026-08-23 23:59:59')
        ->and($thisMonth->from->toDateTimeString())->toBe('2026-08-01 00:00:00')
        ->and($thisMonth->to->toDateTimeString())->toBe('2026-08-24 23:59:59')
        ->and($lastMonth->from->toDateTimeString())->toBe('2026-07-01 00:00:00')
        ->and($lastMonth->to->toDateTimeString())->toBe('2026-07-31 23:59:59');
});

it('builds a custom inclusive date window', function () {
    $range = DateRange::custom('2026-08-01', '2026-08-10');

    expect($range->key)->toBe('custom')
        ->and($range->from->toDateString())->toBe('2026-08-01')
        ->and($range->to->toDateString())->toBe('2026-08-10')
        ->and($range->days())->toBe(10);
});

it('picks hourly, daily or weekly grain from the window length', function () {
    expect(DateRange::make('today')->grain())->toBe('hour')
        ->and(DateRange::make('yesterday')->grain())->toBe('hour')
        ->and(DateRange::make('7d')->grain())->toBe('day')
        ->and(DateRange::make('30d')->grain())->toBe('day')
        ->and(DateRange::make('90d')->grain())->toBe('week');
});

it('can shift a window back a month or a year for comparison', function () {
    $range = DateRange::make('today');
    $previous = $range->comparisonRange('previous');

    expect($range->comparisonRange('none'))->toBeNull()
        ->and($previous)->not->toBeNull()
        ->and($previous->to->lte($range->from))->toBeTrue()
        ->and($range->comparisonRange('month')?->from->toDateString())->toBe('2026-07-24')
        ->and($range->comparisonRange('year')?->from->toDateString())->toBe('2025-08-24');
});
