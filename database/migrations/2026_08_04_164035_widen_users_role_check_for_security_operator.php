<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original users-tenancy migration declared `role` as a Blueprint
     * enum, which on Postgres materialises as a CHECK constraint named
     * users_role_check. Adding a new UserRole case (security_operator)
     * without widening that constraint causes every insert to fail.
     *
     * Rather than dropping and re-adding the column (which would lose data
     * and default values), we replace the check constraint in place.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role::text = ANY (ARRAY['platform_admin'::character varying::text, 'owner_admin'::character varying::text, 'security_operator'::character varying::text, 'shop_admin'::character varying::text, 'shop_viewer'::character varying::text]))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role::text = ANY (ARRAY['platform_admin'::character varying::text, 'owner_admin'::character varying::text, 'shop_admin'::character varying::text, 'shop_viewer'::character varying::text]))");
    }
};
