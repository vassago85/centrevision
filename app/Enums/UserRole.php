<?php

namespace App\Enums;

enum UserRole: string
{
    case PlatformAdmin = 'platform_admin';
    case OwnerAdmin = 'owner_admin';
    case SecurityOperator = 'security_operator';
    case ShopAdmin = 'shop_admin';
    case ShopViewer = 'shop_viewer';

    public function label(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'Platform admin',
            self::OwnerAdmin => 'Owner admin',
            self::SecurityOperator => 'Security operator',
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

    /**
     * Owner-admin only. Security operators live inside the same organization
     * but are deliberately excluded here so `isOwnerRole()` remains a check
     * for full owner authority rather than "any user in the owner org".
     */
    public function isOwnerRole(): bool
    {
        return $this === self::OwnerAdmin;
    }

    /**
     * Guards and other security-desk staff hired by the owner. They can see
     * plate-level data and manage the watchlist but cannot change billing,
     * cameras, sites, or bring on shops.
     */
    public function isSecurityRole(): bool
    {
        return $this === self::SecurityOperator;
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
