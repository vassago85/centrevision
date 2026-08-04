<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Extend the role/permission map with a Security Operator role.
     *
     * Security operators are staff the owner hires to watch the site (guard
     * companies, in-house security desks). They live inside the owner's
     * organization — no new tenancy plumbing — but with a permission set
     * that keeps them away from billing, sites, shops and camera config.
     *
     * Also splits out `manage watchlist` from `view security alerts`, so an
     * operator can add and remove plates without having to be handed every
     * other owner-level ability along with it.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('manage watchlist', 'web');

        // Existing owner_admin gains the new fine-grained permission so the
        // watchlist page keeps working for owners exactly as before.
        Role::findOrCreate('owner_admin', 'web')->givePermissionTo('manage watchlist');

        // Platform admins already have '*' via the original seed migration's
        // permission sync, but that migration ran before `manage watchlist`
        // existed. Grant it now so nothing new escapes them.
        Role::findOrCreate('platform_admin', 'web')->givePermissionTo('manage watchlist');

        // The new role itself.
        $operator = Role::findOrCreate('security_operator', 'web');
        $operator->syncPermissions([
            'view aggregate analytics',
            'view plate level data',
            'view security alerts',
            'manage watchlist',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::where('name', 'security_operator')->delete();
        Permission::where('name', 'manage watchlist')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
