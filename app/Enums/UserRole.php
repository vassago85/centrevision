<?php

namespace App\Enums;

enum UserRole: string
{
    case PlatformAdmin = 'platform_admin';
    case OwnerAdmin = 'owner_admin';
    case ShopAdmin = 'shop_admin';
    case ShopViewer = 'shop_viewer';

    public function label(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'Platform admin',
            self::OwnerAdmin => 'Owner admin',
            self::ShopAdmin => 'Shop admin',
            self::ShopViewer => 'Shop viewer',
        };
    }

    /**
     * Roles that belong to a shop sub-account rather than the property owner.
     */
    public function isShopRole(): bool
    {
        return in_array($this, [self::ShopAdmin, self::ShopViewer], true);
    }

    public function isOwnerRole(): bool
    {
        return $this === self::OwnerAdmin;
    }

    public function isPlatformRole(): bool
    {
        return $this === self::PlatformAdmin;
    }

    /**
     * Shop viewers are read-only.
     */
    public function canManage(): bool
    {
        return $this !== self::ShopViewer;
    }
}
