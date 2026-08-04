<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track when each user last acknowledged their security alerts. The
     * dashboard's notification badge counts events that have occurred since
     * this timestamp, so visiting /security clears the badge until new
     * events arrive.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('alerts_last_seen_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('alerts_last_seen_at');
        });
    }
};
