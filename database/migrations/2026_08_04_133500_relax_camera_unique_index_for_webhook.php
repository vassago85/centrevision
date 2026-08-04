<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Webhook cameras dial home, so their `ip_address` column is a meaningless
 * placeholder (usually 0.0.0.0). The old blanket unique constraint on
 * (site_id, ip_address, channel_id) therefore prevents an operator from
 * adding more than one webhook camera to a site — the second insert crashes
 * with a duplicate-key violation. Relax it to a partial unique index that
 * only enforces uniqueness for pull-mode cameras where the IP + channel
 * pair is a real network address the ingestion path has to reach.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop both the constraint and its backing index. Belt and braces:
        // depending on how the table was created, one or the other may be
        // the actual object that owns the name.
        DB::statement('ALTER TABLE cameras DROP CONSTRAINT IF EXISTS cameras_site_id_ip_address_channel_id_unique');
        DB::statement('DROP INDEX IF EXISTS cameras_site_id_ip_address_channel_id_unique');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX cameras_site_id_ip_address_channel_id_unique
            ON cameras (site_id, ip_address, channel_id)
            WHERE ingestion_mode <> 'webhook'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cameras_site_id_ip_address_channel_id_unique');
        DB::statement(<<<'SQL'
            ALTER TABLE cameras
            ADD CONSTRAINT cameras_site_id_ip_address_channel_id_unique
            UNIQUE (site_id, ip_address, channel_id)
        SQL);
    }
};
