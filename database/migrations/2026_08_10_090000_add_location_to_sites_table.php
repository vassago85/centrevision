<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Location context for sites, so downstream enrichment (weather lookups,
 * public/school holiday tagging) has something to key off of.
 *
 * Everything is nullable — existing sites keep working with no data — and
 * new sites default to South Africa because that is the only calendar the
 * app ships with today. Owners without coordinates simply see no weather
 * markers on their charts, no error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // 9,6 lets us store real GPS coordinates without loss (~11 cm at
            // the equator). Weather APIs happily accept two decimals; we keep
            // the extra precision because geocoding services return it and
            // we may as well not throw it away.
            $table->decimal('latitude', 9, 6)->nullable()->after('address');
            $table->decimal('longitude', 9, 6)->nullable()->after('latitude');

            // ISO 3166-1 alpha-2. Drives which national calendar we consult
            // — ZA in v1, room for others later without a schema change.
            $table->char('country_code', 2)->nullable()->after('longitude');

            // Full IANA name (Africa/Johannesburg) rather than a UTC offset:
            // day boundaries and DST rules for holiday matching depend on it.
            $table->string('timezone', 64)->nullable()->after('country_code');

            // Reserved for future provincial school-term precision. Left
            // nullable and unused in v1; the enrichment job treats absent
            // values as "use national defaults".
            $table->string('province_code', 8)->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'country_code', 'timezone', 'province_code']);
        });
    }
};
