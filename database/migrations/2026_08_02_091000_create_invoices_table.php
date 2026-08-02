<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // Billable is an owner organization (consolidated across its sites)
            // or a shop organization.
            $table->morphs('billable');

            $table->string('number')->unique();
            $table->decimal('amount', 12, 2);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('draft')->index();
            $table->timestamp('paid_at')->nullable();

            $table->string('gateway_reference')->nullable();

            $table->timestamps();

            $table->index(['billable_type', 'billable_id', 'period_start']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();

            // Null for lines that are not attributable to a single site.
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();

            $table->string('kind');
            $table->string('label');
            $table->decimal('amount', 12, 2);

            // Shows the camera x sub-user x rate working on the Billing page.
            $table->json('meta')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
