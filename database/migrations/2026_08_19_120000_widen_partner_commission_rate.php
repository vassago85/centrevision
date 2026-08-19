<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1/3 (0.333333) must round R1,500 to R500; four decimal places
        // stored 0.3333 and paid R499.95.
        DB::statement('ALTER TABLE partners ALTER COLUMN commission_rate TYPE numeric(8,6)');
        DB::statement('ALTER TABLE partner_payouts ALTER COLUMN commission_rate TYPE numeric(8,6)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE partners ALTER COLUMN commission_rate TYPE numeric(5,4)');
        DB::statement('ALTER TABLE partner_payouts ALTER COLUMN commission_rate TYPE numeric(5,4)');
    }
};
