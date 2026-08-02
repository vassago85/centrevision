<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Pending => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Failed => 'Payment failed',
            self::Void => 'Void',
        };
    }

    /**
     * Only settled invoices feed partner commission.
     */
    public function isSettled(): bool
    {
        return $this === self::Paid;
    }
}
