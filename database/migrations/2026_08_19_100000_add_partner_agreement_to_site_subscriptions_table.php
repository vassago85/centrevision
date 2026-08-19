<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_subscriptions', function (Blueprint $table) {
            $table->foreignId('partner_id')
                ->nullable()
                ->after('variable_fee_cap')
                ->constrained('partners')
                ->nullOnDelete();
            $table->decimal('partner_amount', 12, 2)
                ->default(0)
                ->after('partner_id');
        });
    }

    public function down(): void
    {
        Schema::table('site_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
            $table->dropColumn('partner_amount');
        });
    }
};
