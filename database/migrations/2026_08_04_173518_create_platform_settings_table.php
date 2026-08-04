<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A single key/value bag for platform-wide configuration a platform admin
     * can edit from the UI — API keys, contact addresses, feature flags,
     * billing knobs — without redeploying or SSH'ing to change .env.
     *
     * Only platform admins ever read or write this table; tenants never see
     * it, so no organization or site foreign key is needed.
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();

            // Encrypted at rest so a database backup does not leak a Mailgun
            // secret or a Paystack key. Non-sensitive values (a support
            // email, a boolean flag) pay the same tiny CPU cost — worth it
            // for one code path instead of two.
            $table->text('value')->nullable();

            // Platform admins expect to see who last touched a secret.
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
