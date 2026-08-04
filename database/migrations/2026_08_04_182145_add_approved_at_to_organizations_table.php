<?php

use App\Enums\OrganizationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A new owner organization must be approved by a platform admin before
     * anyone in it can use the app; a stored timestamp records when that
     * approval happened and doubles as an audit trail.
     *
     * Existing organizations (seeded demo data, current tenants who
     * predate this feature) are stamped as approved on migration up so
     * nobody who could log in yesterday finds themselves locked out today.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('parent_site_id');
            $table->foreignId('approved_by_user_id')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
        });

        // Owner orgs that existed before this feature are grandfathered in;
        // shop orgs are always tied to an approved owner so their approval
        // status is not a gate.
        DB::table('organizations')
            ->where('type', OrganizationType::Owner->value)
            ->update(['approved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn('approved_at');
        });
    }
};
