<?php

use App\Support\Calendar\SouthAfrica\PublicHolidays;
use App\Support\Calendar\SouthAfrica\SchoolTerms;
use Carbon\CarbonImmutable;

it('marks known fixed South African public holidays', function () {
    // Anchor dates — anyone maintaining the calendar can eyeball these
    // against the Public Holidays Act. Christmas and New Year never move;
    // Freedom Day is 27 April.
    expect(PublicHolidays::isHoliday(CarbonImmutable::parse('2026-01-01')))->toBeTrue()
        ->and(PublicHolidays::isHoliday(CarbonImmutable::parse('2026-12-25')))->toBeTrue()
        ->and(PublicHolidays::isHoliday(CarbonImmutable::parse('2026-04-27')))->toBeTrue()
        ->and(PublicHolidays::isHoliday(CarbonImmutable::parse('2026-06-16')))->toBeTrue();
});

it('returns the holiday name for a matched date', function () {
    expect(PublicHolidays::nameFor(CarbonImmutable::parse('2026-04-27')))->toBe('Freedom Day')
        ->and(PublicHolidays::nameFor(CarbonImmutable::parse('2026-12-16')))->toBe('Day of Reconciliation');
});

it('leaves ordinary weekdays alone', function () {
    // 3 Feb 2026 is a Tuesday, no holiday.
    expect(PublicHolidays::isHoliday(CarbonImmutable::parse('2026-02-03')))->toBeFalse()
        ->and(PublicHolidays::nameFor(CarbonImmutable::parse('2026-02-03')))->toBeNull();
});

it('adds a Monday substitution when a public holiday falls on Sunday', function () {
    // 27 April 2025 was a Sunday. The substitution rule from the Public
    // Holidays Act §2A makes Monday 28 April an observed holiday too.
    expect(PublicHolidays::isHoliday(CarbonImmutable::parse('2025-04-27')))->toBeTrue()
        ->and(PublicHolidays::isHoliday(CarbonImmutable::parse('2025-04-28')))->toBeTrue();
});

it('derives Good Friday and Family Day from Easter', function () {
    // Easter 2026 = 5 April. Good Friday = 3 April, Family Day = 6 April.
    expect(PublicHolidays::nameFor(CarbonImmutable::parse('2026-04-03')))->toBe('Good Friday')
        ->and(PublicHolidays::nameFor(CarbonImmutable::parse('2026-04-06')))->toBe('Family Day');
});

it('flags days outside published school terms as school holidays', function () {
    // Mid-year winter break falls between terms 2 and 3 in the 2026
    // schedule (term 2 ends 26 Jun, term 3 starts 21 Jul). 5 July sits
    // squarely in the winter break.
    expect(SchoolTerms::isSchoolHoliday(CarbonImmutable::parse('2026-07-05')))->toBeTrue();

    // 20 March 2026 is a Friday inside term 1, so should NOT be a
    // school holiday even though it isn't a weekend.
    expect(SchoolTerms::isSchoolHoliday(CarbonImmutable::parse('2026-03-20')))->toBeFalse();
});

it('defaults to non-holiday for years without a published calendar', function () {
    // 2035 isn't in the shipped table — better to say "we don't know"
    // by returning false than to accidentally mark a whole year as
    // holidays.
    expect(SchoolTerms::isSchoolHoliday(CarbonImmutable::parse('2035-06-15')))->toBeFalse()
        ->and(SchoolTerms::hasDataForYear(2035))->toBeFalse();
});
