<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['owner', 'shop'])->index();

            // Set for shop organizations only; the foreign key is added once
            // the sites table exists, since sites point back at organizations.
            $table->foreignId('parent_site_id')->nullable()->index();

            $table->foreignId('referred_by_partner_id')->nullable()
                ->constrained('partners')->nullOnDelete();

            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
