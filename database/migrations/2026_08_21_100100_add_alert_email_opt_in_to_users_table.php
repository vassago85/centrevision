<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('alert_email_opt_in')->default(false)->after('alerts_last_seen_at');
        });

        DB::table('users')
            ->where('role', UserRole::SecurityOperator->value)
            ->update(['alert_email_opt_in' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('alert_email_opt_in');
        });
    }
};
