<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plate_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('plate_number', 32)->index();
            $table->enum('tag', ['recurring_pattern', 'blacklist', 'watch']);
            $table->timestamp('tagged_at');

            // Detection evidence for recurring_pattern tags (day count, stddev).
            $table->json('evidence')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'plate_number', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plate_tags');
    }
};
