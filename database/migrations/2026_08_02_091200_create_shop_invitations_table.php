<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('shop_name');
            $table->string('email');
            $table->string('token', 64)->unique();

            // The monthly rate the owner set when inviting this shop.
            $table->decimal('monthly_amount', 12, 2);

            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();

            // Populated once the invite is redeemed.
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();

            $table->timestamps();

            $table->index(['site_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_invitations');
    }
};
