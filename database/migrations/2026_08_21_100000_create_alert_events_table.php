<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every security alert email (or deferred/suppressed attempt) is one row.
 * Fingerprints dedupe; status drives the quiet-hours flusher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('rule', 32);
            $table->string('plate_number', 16);
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('watchlist_plate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fingerprint', 191);
            $table->string('status', 16);
            $table->json('payload')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('send_after')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'fingerprint']);
            $table->index(['status', 'send_after']);
            $table->index(['site_id', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
    }
};
