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
     * OCR. One substituted, dropped, or extra character qualifies, on plates
     * long enough that a single edit is unlikely to be a different vehicle.
     */
    public static function isProbableMisread(string $candidate, string $known): bool
    {
        $candidate = static::normalise($candidate);
        $known = static::normalise($known);

        if ($candidate === $known || static::isUnknown($candidate) || static::isUnknown($known)) {
            return false;
        }

        $minLength = (int) config('trafficflow.fuzzy_match_min_length', 5);

        if (strlen($candidate) < $minLength || strlen($known) < $minLength) {
            return false;
        }

        // One insert or delete changes the length by 1. Two edits, or a
        // longer gap, is a different vehicle.
        if (abs(strlen($candidate) - strlen($known)) > 1) {
            return false;
        }

        return levenshtein($candidate, $known) === 1;
    }
}
