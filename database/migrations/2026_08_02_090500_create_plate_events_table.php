<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plate_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('camera_id')->constrained('cameras')->cascadeOnDelete();

            // Normalised to uppercase alphanumerics by PlateEventRecorder.
            $table->string('plate_number', 32)->index();

            $table->enum('direction', ['in', 'out'])->nullable();
            $table->timestamp('captured_at')->index();
            $table->float('confidence')->nullable();
            $table->json('raw_payload')->nullable();

            // Set once MatchVisits has consumed the event. Without this an
            // unmatched "out" event would leave no trace of being seen.
            $table->timestamp('processed_at')->nullable();

            // Retained when fuzzy matching rewrote an OCR misread, so the
            // original capture is still auditable.
            $table->string('original_plate_number', 32)->nullable();

            $table->timestamps();

            // Same camera cannot report the same plate at the same instant twice.
            $table->unique(['camera_id', 'plate_number', 'captured_at'], 'plate_events_dedupe_unique');

            // Drives MatchVisits (unprocessed events in capture order).
            $table->index(['processed_at', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plate_events');
    }
};
