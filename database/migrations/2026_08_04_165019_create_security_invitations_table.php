<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pending Security Operator invitations, scoped to the owning
     * organization rather than a single site — operators see every site an
     * owner runs, so the site-scoped invitation model used by shops does
     * not fit.
     */
    public function up(): void
    {
        Schema::create('security_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            // A given address can only have one pending invitation into the
            // same organization; a partial unique index would be tidier but
            // Laravel/Postgres treat NULL as distinct so a plain unique
            // over (organization_id, email, accepted_at) keeps duplicates
            // out until acceptance, then allows re-inviting after removal.
            $table->string('email', 255);
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_invitations');
    }
};
