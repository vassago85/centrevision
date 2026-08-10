<?php

namespace App\Support\Calendar\SouthAfrica;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * South African national school terms.
 *
 * Convention: this class returns TRUE for {@see isSchoolHoliday()} on any day
 * that falls *outside* an official school term — the mid-term breaks and the
 * summer/winter/spring recesses that drive weekday mall traffic. Weekends
 * inside a term are NOT school holidays; the flag is meant to answer "would
 * schoolchildren normally be at school today?".
 *
 * Provincial dates diverge (inland vs coastal) by roughly a week — the
 * Department of Basic Education publishes two schedules. v1 uses the
 * inland schedule as the default; sites.province_code is reserved for a
 * later refinement.
 *
 * Term dates are the DBE-published official start/end days for each year.
 * Extend by adding a row to the constant as each year is published.
 */
class SchoolTerms
{
    /**
     * Inland provinces school term windows, inclusive of both endpoints.
     * Weekends inside these ranges are still term time.
     *
     * Sources: DBE published school calendars. Verify against the DBE press
     * releases for each new year before shipping.
     *
     * @var array<int, array<int, array{start: string, end: string}>>
     */
    private const INLAND_TERMS = [
        2025 => [
            ['start' => '2025-01-15', 'end' => '2025-03-28'],
            ['start' => '2025-04-08', 'end' => '2025-06-27'],
            ['start' => '2025-07-22', 'end' => '2025-10-03'],
            ['start' => '2025-10-13', 'end' => '2025-12-10'],
        ],
        2026 => [
            ['start' => '2026-01-14', 'end' => '2026-03-27'],
            ['start' => '2026-04-13', 'end' => '2026-06-26'],
            ['start' => '2026-07-21', 'end' => '2026-10-02'],
            ['start' => '2026-10-12', 'end' => '2026-12-09'],
        ],
        2027 => [
            ['start' => '2027-01-13', 'end' => '2027-04-01'],
            ['start' => '2027-04-13', 'end' => '2027-06-25'],
            ['start' => '2027-07-20', 'end' => '2027-10-01'],
            ['start' => '2027-10-11', 'end' => '2027-12-08'],
        ],
    ];

    /**
     * True if the given date falls outside every published school term for
     * that year. Days in years we haven't cached yet default to FALSE so
     * we don't accidentally mark all of 2028 as a school holiday until the
     * calendar is added.
     */
    public static function isSchoolHoliday(CarbonInterface $date): bool
    {
        $year = (int) $date->format('Y');
        $terms = self::INLAND_TERMS[$year] ?? null;

        if ($terms === null) {
            return false;
        }

        $iso = $date->toDateString();

        foreach ($terms as $term) {
            if ($iso >= $term['start'] && $iso <= $term['end']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The list of published school-term windows for a year, mainly useful
     * for surfacing "why we don't know yet" in tests and admin UIs.
     *
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public static function termsForYear(int $year): array
    {
        $terms = self::INLAND_TERMS[$year] ?? [];

        return array_map(fn (array $t) => [
            'start' => CarbonImmutable::parse($t['start']),
            'end' => CarbonImmutable::parse($t['end']),
        ], $terms);
    }

    /**
     * Whether we ship an official calendar for the given year. The enrichment
     * job can log a warning once a year to remind us to add the next one
     * rather than silently underreporting.
     */
    public static function hasDataForYear(int $year): bool
    {
        return isset(self::INLAND_TERMS[$year]);
    }
}
