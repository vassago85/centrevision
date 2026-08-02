<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('plate_number', 32)->index();

            $table->foreignId('entry_event_id')->nullable()->constrained('plate_events')->nullOnDelete();
            $table->foreignId('exit_event_id')->nullable()->constrained('plate_events')->nullOnDelete();

            $table->timestamp('entered_at')->index();
            $table->timestamp('exited_at')->nullable();
            $table->unsignedInteger('dwell_minutes')->nullable();

            $table->enum('status', ['open', 'closed', 'orphaned'])->default('open')->index();
            $table->timestamps();

            // Finding the open visit to close on an "out" event.
            $table->index(['site_id', 'plate_number', 'status']);

            // Dashboard aggregates are always site + date-range scoped.
            $table->index(['site_id', 'entered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
