<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plate-free day rollup: one row per site per local calendar day.
 *
 * Two reasons this exists:
 *
 *  1. POPIA prune deletes visits/plate_events after retention. The dashboard
 *     used to lose historical trend context along with them; this table
 *     carries the aggregate numbers + weather + holiday flags forward, and
 *     is deliberately untouched by PrunePlateData because it holds no plate
 *     numbers at all.
 *
 *  2. It's a cheap join target for the "exclude holidays" toggle and the
 *     chart markers — no need to hit an external weather API per render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_day_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();

            // Local calendar date at the site's timezone, not UTC. Two sites
            // in different zones could disagree about "yesterday", and the
            // holiday calendar is anchored to the local date.
            $table->date('local_date');

            // Aggregate visit counts computed with the same "excluding
            // recurring plates" rule as TrafficAnalytics, so filtered chart
            // series match unfiltered ones down to the last shopper.
            $table->unsignedInteger('visits_count')->default(0);
            $table->unsignedInteger('unique_vehicles')->default(0);

            // Weather. All nullable because a site without coordinates
            // simply skips enrichment (no error, no marker on the chart).
            $table->decimal('temp_avg_c', 5, 2)->nullable();
            $table->decimal('precip_mm', 6, 2)->nullable();
            // WMO weather code from Open-Meteo (0 = clear, 61 = rain, ...).
            $table->unsignedSmallInteger('weather_code')->nullable();
            // Short human label derived from the WMO code; stored so the UI
            // doesn't need to know the mapping.
            $table->string('weather_label', 32)->nullable();

            // Calendar flags. Two independent booleans rather than an enum:
            // a day can be both (e.g. a public holiday during a school break)
            // and each is a separate filter dimension.
            $table->boolean('is_public_holiday')->default(false);
            $table->boolean('is_school_holiday')->default(false);
            $table->string('holiday_name', 120)->nullable();

            $table->timestamps();

            // Idempotency + primary lookup path: one row per site per day.
            $table->unique(['site_id', 'local_date']);

            // The dashboard filter joins on (site_id, local_date) and the
            // exclude toggle scans by holiday flag; the composite index
            // covers both. Postgres will pick the partial subset it needs.
            $table->index(['site_id', 'local_date', 'is_public_holiday'], 'site_day_stats_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_day_stats');
    }
};
