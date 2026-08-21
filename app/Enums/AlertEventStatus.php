<?php

namespace App\Enums;

enum AlertEventStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Suppressed = 'suppressed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Sent',
            self::Suppressed => 'Suppressed',
            self::Failed => 'Failed',
        };
    }
}
