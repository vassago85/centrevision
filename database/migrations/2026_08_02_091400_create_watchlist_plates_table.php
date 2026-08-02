<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plates a client explicitly wants flagged: banned troublemakers, plates the
 * security team wants notified about, or VIP regulars who should be handed
 * personal attention. Distinct from the behavioural dwell / odd-hour signals
 * on the Security page — those answer "is anything anomalous?", the watchlist
 * answers "is this specific plate here?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlist_plates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Plates are stored uppercase-normalised so a hit test is a direct
            // equality lookup against plate_events.plate_number.
            $table->string('plate_number', 16);

            $table->string('kind', 16);

            // Free text so operators can capture why the plate is on the list
            // without us prescribing a taxonomy. Shown in tooltips and hits.
            $table->string('reason', 255)->nullable();

            // Optional — a VIP campaign or a temporary ban that should self-clear.
            $table->timestamp('expires_at')->nullable();

            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One entry per (site, plate). If the kind needs to change, the row
            // is updated in place rather than growing a history — the reason
            // field is expected to be edited alongside.
            $table->unique(['site_id', 'plate_number']);
            $table->index(['site_id', 'kind']);
            $table->index('plate_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_plates');
    }
};
