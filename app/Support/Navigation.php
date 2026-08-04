<?php

namespace App\Support;

use App\Models\User;

/**
 * The tab bar, scoped by role.
 *
 * Shops deliberately see only Overview and Reports: no per-camera data, no
 * other shops, and no Security.
 */
class Navigation
{
    /**
     * @return list<array{label: string, route: string, icon: string, tone?: string}>
     */
    public static function for(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return match (true) {
            $user->isPlatformAdmin() => self::platform(),
            $user->isOwnerAdmin() => self::owner(),
            $user->isSecurityOperator() => self::securityOperator(),
            $user->isShopUser() => self::shop(),
            default => [],
        };
    }

    /**
     * The landing route for a user: their first tab, or the login screen for
     * guests. Drives both the root URL and the brand link in the topbar.
     */
    public static function homeRouteFor(?User $user): string
    {
        return self::for($user)[0]['route'] ?? 'login';
    }

    /**
     * @return list<array{label: string, route: string, icon: string, tone?: string}>
     */
    protected static function owner(): array
    {
        return [
            ['label' => 'Dashboard', 'route' => 'overview', 'icon' => 'squares-2x2'],
            ['label' => 'Sites', 'route' => 'sites', 'icon' => 'building-office-2'],
            ['label' => 'Cameras', 'route' => 'cameras', 'icon' => 'video-camera'],
            // Both Security and Watchlist paint red — they answer the
            // "is something wrong right now?" question, not "how is business?".
            ['label' => 'Security', 'route' => 'security', 'icon' => 'shield-exclamation', 'tone' => 'danger'],
            ['label' => 'Watchlist', 'route' => 'watchlist', 'icon' => 'bell-alert', 'tone' => 'danger'],
            ['label' => 'Sub-accounts', 'route' => 'shops', 'icon' => 'user-group'],
            ['label' => 'Reports', 'route' => 'reports', 'icon' => 'document-chart-bar'],
            ['label' => 'Billing', 'route' => 'billing', 'icon' => 'credit-card'],
            ['label' => 'Settings', 'route' => 'settings', 'icon' => 'cog-6-tooth'],
        ];
    }

    /**
     * @return list<array{label: string, route: string, icon: string}>
     */
    protected static function shop(): array
    {
        return [
            ['label' => 'Dashboard', 'route' => 'overview', 'icon' => 'squares-2x2'],
            ['label' => 'Reports', 'route' => 'reports', 'icon' => 'document-chart-bar'],
        ];
    }

    /**
     * A hired guard's toolset: watch plates, curate the watchlist, verify a
     * camera is alive. No sites, shops, billing or settings.
     *
     * @return list<array{label: string, route: string, icon: string, tone?: string}>
     */
    protected static function securityOperator(): array
    {
        return [
            ['label' => 'Dashboard', 'route' => 'overview', 'icon' => 'squares-2x2'],
            ['label' => 'Cameras', 'route' => 'cameras', 'icon' => 'video-camera'],
            ['label' => 'Security', 'route' => 'security', 'icon' => 'shield-exclamation', 'tone' => 'danger'],
            ['label' => 'Watchlist', 'route' => 'watchlist', 'icon' => 'bell-alert', 'tone' => 'danger'],
            ['label' => 'Reports', 'route' => 'reports', 'icon' => 'document-chart-bar'],
        ];
    }

    /**
     * @return list<array{label: string, route: string, icon: string}>
     */
    protected static function platform(): array
    {
        return [
            ['label' => 'Platform', 'route' => 'platform.overview', 'icon' => 'globe-alt'],
            ['label' => 'Owners', 'route' => 'platform.owners', 'icon' => 'building-office-2'],
            ['label' => 'Partners', 'route' => 'platform.partners', 'icon' => 'hand-raised'],
            ['label' => 'Approvals', 'route' => 'platform.approvals', 'icon' => 'clipboard-document-check'],
            ['label' => 'Settings', 'route' => 'platform.settings', 'icon' => 'cog-6-tooth'],
        ];
    }
}
