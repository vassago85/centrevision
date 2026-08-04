<?php

namespace App\Enums;

enum InvoiceLineKind: string
{
    case BaseFee = 'base_fee';
    case VariableFee = 'variable_fee';
    case CameraSurcharge = 'camera_surcharge';
    case ShopRevenueShare = 'shop_revenue_share';
    case ShopSubscription = 'shop_subscription';
    case SecurityOperatorSeats = 'security_operator_seats';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::BaseFee => 'Base fee',
            self::VariableFee => 'Variable fee',
            self::CameraSurcharge => 'Additional cameras',
            self::ShopRevenueShare => 'Shop revenue share',
            self::ShopSubscription => 'Shop subscription',
            self::SecurityOperatorSeats => 'Security operator seats',
            self::Adjustment => 'Adjustment',
        };
    }
}
