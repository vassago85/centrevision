<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cameras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name');
            $table->enum('role', ['entrance', 'exit', 'both'])->default('both');
            $table->string('ip_address');
            $table->string('isapi_username')->nullable();
            $table->text('isapi_password')->nullable();
            $table->unsignedSmallInteger('channel_id')->default(1);
            $table->boolean('is_active')->default(true)->index();

            // Denormalised health, written by the ingestion paths so the
            // Cameras page does not aggregate plate_events on every render.
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('last_probe_ok_at')->nullable();
            $table->string('last_probe_error')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'ip_address', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cameras');
    }
};
