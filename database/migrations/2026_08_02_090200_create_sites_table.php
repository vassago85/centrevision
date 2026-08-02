<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // Close the organizations -> sites loop now that both tables exist.
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign('parent_site_id')->references('id')->on('sites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['parent_site_id']);
        });

        Schema::dropIfExists('sites');
    }
};
