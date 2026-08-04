<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rows a platform admin has to review before the action they describe
     * takes real effect. The first kind is `owner_registration` — new
     * owners land in `pending` and cannot use the app until a platform
     * admin approves. Partner sign-ups, invoice adjustments and high-value
     * shop invitations will hook into this table as later commits land.
     */
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();

            $table->string('kind')->index();
            $table->string('status')->default('pending')->index();

            // The record whose fate is being decided (an Organization row
            // for owner sign-ups, a ShopInvitation for high-value invites,
            // etc). Nullable because "manual invoice adjustment" style
            // approvals may not attach to a specific existing row.
            $table->nullableMorphs('subject');

            // Free-form context the inbox uses to render the row (proposed
            // amount, requested email, note from the applicant). JSON so
            // each `kind` can carry its own shape without bloating the
            // schema.
            $table->json('payload')->nullable();

            $table->foreignId('requested_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignId('reviewed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
