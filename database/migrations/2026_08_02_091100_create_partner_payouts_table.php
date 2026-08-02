<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');

            // The settled revenue the commission was calculated from, kept so a
            // payout can be explained without recomputing history.
            $table->decimal('revenue_base', 12, 2)->default(0);
            $table->decimal('commission_rate', 5, 4);
            $table->decimal('commission_amount', 12, 2);

            $table->string('status')->default('pending')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_payouts');
    }
};
