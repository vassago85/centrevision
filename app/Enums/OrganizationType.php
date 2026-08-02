<?php

namespace App\Enums;

enum OrganizationType: string
{
    case Owner = 'owner';
    case Shop = 'shop';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Property owner',
            self::Shop => 'Shop',
        };
    }
}
