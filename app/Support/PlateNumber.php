<?php

namespace App\Support;

/**
 * Plate strings arrive from cameras with inconsistent spacing, punctuation and
 * casing. Everything stored and compared inside TrafficFlow uses the
 * normalised form; the display form is only rebuilt for the UI.
 */
class PlateNumber
{
    /**
     * Strip everything that is not a letter or digit and uppercase the rest.
     */
    public static function normalise(?string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $plate) ?? '');
    }

    /**
     * Cameras report "unknown" when OCR fails. That is not a vehicle, so it
     * must not sit on the security desk as a dwell alert.
     */
    public static function isUnknown(?string $plate): bool
    {
        return static::normalise($plate) === 'UNKNOWN';
    }

    /**
     * Re-space a normalised South African plate for display: JD45GP -> JD 45 GP.
     * Anything that does not match the common province format is returned as-is.
     */
    public static function forDisplay(?string $plate): string
    {
        $normalised = static::normalise($plate);

        if (preg_match('/^([A-Z]{2,3})(\d{2,3})([A-Z]{2})$/', $normalised, $matches) === 1) {
            return "{$matches[1]} {$matches[2]} {$matches[3]}";
        }

        return $normalised;
    }

    /**
     * A plate reduced to something diagnosable but not identifying: first and
     * last character kept, everything between replaced.
     *
     * Used wherever a plate would otherwise reach a log file, an exception
     * report or a support ticket, none of which are places personal
     * information belongs.
     */
    public static function mask(?string $plate): string
    {
        $normalised = static::normalise($plate);

        if (strlen($normalised) < 3) {
            return str_repeat('*', strlen($normalised));
        }

        return $normalised[0].str_repeat('*', strlen($normalised) - 2).$normalised[-1];
    }

    /**
     * Whether two plates are close enough to be the same vehicle misread by the
     * OCR. Up to two substituted, dropped, or extra characters qualify, on
     * plates long enough that those edits are unlikely to be a different
     * vehicle. Camera confidence is ignored.
     */
    public static function isProbableMisread(string $candidate, string $known): bool
    {
        $distance = static::editDistance($candidate, $known);

        return $distance !== null && $distance >= 1;
    }

    /**
     * The known plate to trust for this read.
     *
     * An exact plate wins. Otherwise the unique plate one character away wins.
     * A two-character plate is used only when nothing is closer. Two plates at
     * the same distance is not a match.
     *
     * @param  iterable<int, string>  $knownPlates
     */
    public static function closestPlate(string $candidate, iterable $knownPlates): ?string
    {
        $byDistance = [];

        foreach ($knownPlates as $known) {
            $distance = static::editDistance($candidate, (string) $known);

            if ($distance === null) {
                continue;
            }

            $byDistance[$distance][static::normalise($known)] = static::normalise($known);
        }

        ksort($byDistance);

        foreach ($byDistance as $plates) {
            if (count($plates) === 1) {
                return array_values($plates)[0];
            }

            return null;
        }

        return null;
    }

    /**
     * Edit distance between two plates, or null when they must not be compared.
     * Zero is an exact plate. Camera confidence is ignored.
     */
    public static function editDistance(string $candidate, string $known): ?int
    {
        $candidate = static::normalise($candidate);
        $known = static::normalise($known);

        if ($candidate === '' || $known === '' || static::isUnknown($candidate) || static::isUnknown($known)) {
            return null;
        }

        if ($candidate === $known) {
            return 0;
        }

        $minLength = (int) config('trafficflow.fuzzy_match_min_length', 5);
        $maxEdits = (int) config('trafficflow.fuzzy_match_max_edits', 2);

        if (strlen($candidate) < $minLength || strlen($known) < $minLength) {
            return null;
        }

        if (abs(strlen($candidate) - strlen($known)) > $maxEdits) {
            return null;
        }

        $distance = levenshtein($candidate, $known);

        return $distance <= $maxEdits ? $distance : null;
    }
}
