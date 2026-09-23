<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plate_events', function (Blueprint $table) {
            // A second photo of a drive-through that already opened or closed
            // a visit. Kept for the camera log, left out of pairing quality.
            $table->foreignId('superseded_by_event_id')
                ->nullable()
                ->after('processed_at')
                ->constrained('plate_events')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('plate_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superseded_by_event_id');
        });
    }
};
