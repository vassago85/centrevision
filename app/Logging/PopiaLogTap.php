<?php

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * Attaches the plate redaction processor to a log channel.
 */
class PopiaLogTap
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new RedactPlateNumbers);
    }
}
