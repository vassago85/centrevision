<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Null only for platform admins, who sit above every tenant.
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained('organizations')->cascadeOnDelete();

            $table->enum('role', [
                'platform_admin',
                'owner_admin',
                'shop_admin',
                'shop_viewer',
            ])->default('owner_admin')->after('organization_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn('role');
        });
    }
};
