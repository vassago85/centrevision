<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->enum('base_tier', ['starter', 'standard', 'large', 'enterprise'])->default('starter');
            $table->decimal('base_fee', 12, 2)->default(0);
            $table->decimal('variable_rate_per_camera_per_subuser', 12, 2)->default(20.00);

            // Stops an unusually shop-dense mall producing a runaway bill.
            $table->decimal('variable_fee_cap', 12, 2)->nullable();

            $table->string('status')->default('trialing')->index();
            $table->timestamp('current_period_ends_at')->nullable();

            $table->string('gateway_customer_id')->nullable();
            $table->string('gateway_subscription_id')->nullable();

            $table->timestamps();

            $table->unique('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_subscriptions');
    }
};
