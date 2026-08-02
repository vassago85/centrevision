<?php

namespace App\Logging;

use App\Support\PlateNumber;
use Monolog\LogRecord;

/**
 * Last line of defence for POPIA: strips plate numbers out of anything on its
 * way to a log file.
 *
 * Code is supposed to avoid logging plates in the first place, but log lines
 * are added in a hurry and exception messages quote whatever they were handed.
 * A processor cannot be forgotten.
 */
class RedactPlateNumbers
{
    /**
     * Context keys whose value is a plate, whatever it looks like.
     */
    protected const PLATE_KEYS = ['plate', 'plate_number', 'original_plate_number', 'plates'];

    /**
     * A South African plate in the format the cameras report, with or without
     * separators.
     */
    protected const PLATE_PATTERN = '/\b[A-Z]{2,3}[ -]?\d{2,3}[ -]?[A-Z]{2}\b/';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record
            ->with(message: $this->redactString($record->message))
            ->with(context: $this->redactArray($record->context));
    }

    /**
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    protected function redactArray(array $context): array
    {
        foreach ($context as $key => $value) {
            $context[$key] = match (true) {
                is_array($value) => $this->redactArray($value),
                in_array($key, self::PLATE_KEYS, true) && is_string($value) => PlateNumber::mask($value),
                is_string($value) => $this->redactString($value),
                default => $value,
            };
        }

        return $context;
    }

    protected function redactString(string $value): string
    {
        return preg_replace_callback(
            self::PLATE_PATTERN,
            fn (array $matches) => PlateNumber::mask($matches[0]),
            $value,
        ) ?? $value;
    }
}
