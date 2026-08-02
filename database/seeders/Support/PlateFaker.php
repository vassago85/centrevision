<?php

namespace Database\Seeders\Support;

/**
 * Plate strings for demo data, in the normalised form TrafficFlow stores.
 *
 * The fixed sets are what the Security and staff-exclusion features need to
 * demonstrate themselves; shopper plates are generated at random.
 */
class PlateFaker
{
    protected const PROVINCES = ['GP', 'NW', 'MP', 'FS', 'WP', 'KZN'];

    protected const LETTERS = 'ABCDEFGHJKLMNPRSTVWXYZ';

    /**
     * Plates matching the mockup's "currently over threshold" table.
     *
     * @return list<string>
     */
    public static function overThreshold(): array
    {
        return ['BX91GP', 'FX22GP', 'KT07GP'];
    }

    /**
     * Plates matching the mockup's "odd-hour recurring visits" table.
     *
     * @return list<string>
     */
    public static function oddHour(): array
    {
        return ['TZ18GP', 'MP63GP'];
    }

    /**
     * Plates matching the mockup's "multiple entries today" table.
     *
     * @return list<string>
     */
    public static function multiEntry(): array
    {
        return ['JD45GP', 'HK12GP'];
    }

    /**
     * Staff and tenant vehicles, which TagRecurringPlates should flag.
     *
     * @return list<string>
     */
    public static function staff(): array
    {
        return ['SF01GP', 'SF02GP', 'SF03GP', 'SF04GP', 'SF05GP', 'SF06GP', 'SF07GP', 'SF08GP'];
    }

    /**
     * Roughly what share of shopper visits come from someone who will be back
     * inside the demo window. Drawing every plate from one pool would make
     * almost every shopper a repeat visitor, which no mall sees.
     */
    protected const REGULAR_VISIT_SHARE = 47;

    protected const REGULAR_POOL_SIZE = 1400;

    /**
     * A shopper plate: usually a face we never see again, sometimes one of the
     * centre's regulars. The mix lands the repeat-visitor metric near 18%.
     */
    public static function shopper(): string
    {
        static $regulars = null;
        static $seen = [];

        if ($regulars === null) {
            $regulars = [];

            while (count($regulars) < self::REGULAR_POOL_SIZE) {
                $plate = static::random();
                $regulars[$plate] = true;
                $seen[$plate] = true;
            }

            $regulars = array_keys($regulars);
        }

        if (random_int(1, 100) <= self::REGULAR_VISIT_SHARE) {
            return $regulars[array_rand($regulars)];
        }

        // One-off visitors must genuinely be one-offs, so keep drawing until
        // we land on a plate this run has not used.
        do {
            $plate = static::random();
        } while (isset($seen[$plate]));

        $seen[$plate] = true;

        return $plate;
    }

    public static function random(): string
    {
        $letters = static::LETTERS;

        return $letters[random_int(0, strlen($letters) - 1)]
            .$letters[random_int(0, strlen($letters) - 1)]
            .str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT)
            .self::PROVINCES[random_int(0, count(self::PROVINCES) - 1)];
    }
}
