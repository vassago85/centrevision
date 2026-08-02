<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Abilities that differ between roles. Anything not listed here is decided
     * by a policy rather than a flat permission.
     */
    protected const PERMISSIONS = [
        'view aggregate analytics',
        'view plate level data',
        'manage cameras',
        'view security alerts',
        'manage shops',
        'manage billing',
        'manage site settings',
        'manage users',
        'view all tenants',
    ];

    protected const ROLE_PERMISSIONS = [
        'platform_admin' => '*',
        'owner_admin' => [
            'view aggregate analytics',
            'view plate level data',
            'manage cameras',
            'view security alerts',
            'manage shops',
            'manage billing',
            'manage site settings',
            'manage users',
        ],
        // Shops see their site's aggregate numbers only: no plate rows, no
        // cameras, no security, no other shops.
        'shop_admin' => [
            'view aggregate analytics',
            'manage users',
        ],
        'shop_viewer' => [
            'view aggregate analytics',
        ],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (UserRole::cases() as $role) {
            $model = Role::findOrCreate($role->value, 'web');

            $permissions = self::ROLE_PERMISSIONS[$role->value];

            $model->syncPermissions($permissions === '*' ? self::PERMISSIONS : $permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::whereIn('name', array_column(UserRole::cases(), 'value'))->delete();
        Permission::whereIn('name', self::PERMISSIONS)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
