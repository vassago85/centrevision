<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table) {
            // 'webhook' is the new default. Existing rows are back-filled to
            // 'stream' below so the ISAPI listener keeps behaving as it did.
            $table->string('ingestion_mode', 16)->default('webhook')->after('is_active');

            // The camera authenticates itself against this via HTTP Basic when
            // it POSTs to /webhooks/hik/{id}. Stored encrypted like the ISAPI
            // password so a database dump does not leak per-device secrets.
            $table->text('webhook_secret')->nullable()->after('ingestion_mode');

            // Denormalised health for the webhook path, mirroring the existing
            // last_event_at / last_probe_ok_at pair the stream path writes.
            $table->timestamp('webhook_last_seen_at')->nullable()->after('last_probe_error');
        });

        // Anything that already exists in the DB predates the webhook path and
        // is therefore on the LAN via ISAPI; do not silently re-tag it as
        // "webhook" or the listener will stop reaching it.
        \DB::table('cameras')->update(['ingestion_mode' => 'stream']);
    }

    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table) {
            $table->dropColumn(['ingestion_mode', 'webhook_secret', 'webhook_last_seen_at']);
        });
    }
};
