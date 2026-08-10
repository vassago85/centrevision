<?php

namespace App\Support\Calendar\SouthAfrica;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * South African public holidays.
 *
 * Static data on purpose: the list is small, changes rarely (a new act of
 * Parliament, or a proclamation for a specific once-off day), and we don't
 * want an outbound HTTP call in a nightly enrichment job that then has to
 * handle "what if the calendar API is down". Extend by adding rows for new
 * years as they become known.
 *
 * Movable feasts (Good Friday, Family Day) are computed from Easter, which
 * PHP already ships (`easter_date`). Substitution days (Public Holidays Act
 * §2A) are handled here too: a public holiday that falls on a Sunday moves
 * to the following Monday.
 */
class PublicHolidays
{
    /**
     * Fixed-date holidays. Anything computed (Easter-relative) is added in
     * {@see forYear()}. Keeping fixed dates in a table keeps the intent
     * legible — anyone auditing the list can see it at a glance.
     *
     * @var array<int, array{month: int, day: int, name: string}>
     */
    private const FIXED = [
        ['month' => 1, 'day' => 1, 'name' => "New Year's Day"],
        ['month' => 3, 'day' => 21, 'name' => 'Human Rights Day'],
        ['month' => 4, 'day' => 27, 'name' => 'Freedom Day'],
        ['month' => 5, 'day' => 1, 'name' => "Workers' Day"],
        ['month' => 6, 'day' => 16, 'name' => 'Youth Day'],
        ['month' => 8, 'day' => 9, 'name' => "National Women's Day"],
        ['month' => 9, 'day' => 24, 'name' => 'Heritage Day'],
        ['month' => 12, 'day' => 16, 'name' => 'Day of Reconciliation'],
        ['month' => 12, 'day' => 25, 'name' => 'Christmas Day'],
        ['month' => 12, 'day' => 26, 'name' => 'Day of Goodwill'],
    ];

    /**
     * All ZA public holidays for a given calendar year, keyed by ISO date
     * string, value is the holiday name.
     *
     * @return array<string, string>
     */
    public static function forYear(int $year): array
    {
        $holidays = [];

        foreach (self::FIXED as $entry) {
            $date = CarbonImmutable::createFromDate($year, $entry['month'], $entry['day']);
            $holidays[$date->toDateString()] = $entry['name'];
        }

        // Easter-relative holidays. Good Friday = Easter - 2, Family Day =
        // Easter + 1 (Easter Monday under a South African name).
        $easter = self::easter($year);
        $holidays[$easter->subDays(2)->toDateString()] = 'Good Friday';
        $holidays[$easter->addDay()->toDateString()] = 'Family Day';

        // Public Holidays Act §2A: a Sunday holiday triggers a Monday
        // substitution. The original Sunday still counts as a holiday too
        // — retail closures often follow the substituted day, so shopping
        // traffic reflects both. We only add the Monday, leaving the
        // original entry in place.
        foreach ($holidays as $iso => $name) {
            $day = CarbonImmutable::parse($iso);

            if ($day->isSunday()) {
                $substitute = $day->addDay()->toDateString();

                // Never overwrite an existing holiday sitting on that Monday
                // — that day is already tagged and the more specific name wins.
                $holidays[$substitute] ??= $name.' (observed)';
            }
        }

        ksort($holidays);

        return $holidays;
    }

    /**
     * True if the given date is a South African public holiday (including
     * substitution days).
     */
    public static function isHoliday(CarbonInterface $date): bool
    {
        return self::nameFor($date) !== null;
    }

    /**
     * The holiday name for a given date, or null if it isn't one.
     */
    public static function nameFor(CarbonInterface $date): ?string
    {
        $holidays = self::forYear((int) $date->format('Y'));

        return $holidays[$date->toDateString()] ?? null;
    }

    /**
     * PHP's `easter_days()` returns the integer offset from March 21 in the
     * given year, which sidesteps every timezone concern that plagues its
     * `easter_date()` cousin (that one hands back a Unix timestamp in the
     * default timezone and is fun to reason about across systems).
     */
    private static function easter(int $year): CarbonImmutable
    {
        // easter_days handles the range we care about (roughly 4 CE and up),
        // but guard against silly inputs anyway.
        if ($year < 1583) {
            return CarbonImmutable::createFromDate($year, 4, 12);
        }

        return CarbonImmutable::createFromDate($year, 3, 21)
            ->addDays(easter_days($year));
    }
}
