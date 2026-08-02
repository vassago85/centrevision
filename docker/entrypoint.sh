#!/bin/sh
set -e

echo "🚀 Starting CentreVision..."

# ── Filesystem prep ───────────────────────────────────────────────────
# Storage / bootstrap-cache dirs sometimes come up empty on a fresh volume,
# so lay them out explicitly before touching permissions.
echo "🔧 Creating runtime directories..."
mkdir -p /var/www/html/storage/framework/views/livewire/classes
mkdir -p /var/www/html/storage/framework/views/livewire/views
mkdir -p /var/www/html/storage/framework/cache/data
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/testing
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/bootstrap/cache
# Camera-drop targets referenced by SweepFtpDropFolder.
mkdir -p /var/www/html/storage/app/private/hikvision-drop
mkdir -p /var/www/html/storage/app/private/hikvision-drop/failed

echo "🔧 Setting permissions..."
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage
chown -R www-data:www-data /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/bootstrap/cache

# ── Database readiness ────────────────────────────────────────────────
# Wait for the Postgres port to open, then confirm we can actually authenticate.
# We do this both here and in every worker/scheduler container so nothing kicks
# off before the DB will accept queries.
echo "⏳ Waiting for Postgres on db:5432 ..."
max_tries=30
count=0
until nc -z db 5432 2>/dev/null; do
    count=$((count + 1))
    if [ $count -ge $max_tries ]; then
        echo "❌ Postgres not reachable after $max_tries attempts"
        exit 1
    fi
    echo "   Attempt $count/$max_tries..."
    sleep 2
done
echo "✅ Postgres port is open"

# Give the server a moment past 'port open' to become 'ready for queries'.
sleep 2

echo "🔐 Testing database credentials..."
max_tries=10
count=0
until php -r "new PDO('pgsql:host=db;port=5432;dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    count=$((count + 1))
    if [ $count -ge $max_tries ]; then
        echo "⚠️  Postgres authentication still failing after $max_tries attempts — continuing so migrations can surface the real error"
        break
    fi
    echo "   Auth attempt $count/$max_tries..."
    sleep 2
done
echo "✅ Postgres is ready"

# ── Laravel setup ─────────────────────────────────────────────────────
echo "📦 Running migrations..."
php artisan migrate --force || echo "⚠️  Migration had issues, continuing..."

# Env comes from docker at runtime, so DO NOT cache config — a cached config
# would pin the values from build-time (empty). Just clear everything and let
# Laravel resolve fresh on each request.
echo "⚡ Clearing caches..."
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear || echo "⚠️  Cache clear had issues, continuing..."

# Livewire's compiled Volt classes get stale between deploys — nuke them so
# the very first request rebuilds them against the new codebase.
rm -rf /var/www/html/storage/framework/views/livewire/classes/*
rm -rf /var/www/html/storage/framework/views/livewire/views/*

echo "📦 Publishing Livewire assets..."
php artisan livewire:publish --assets 2>/dev/null || true

echo "🔗 Ensuring storage symlink..."
php artisan storage:link 2>/dev/null || true

echo "🔧 Final permissions check..."
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage

echo "✅ CentreVision is ready!"

# ── Hand off ──────────────────────────────────────────────────────────
# When docker-compose passes an explicit CMD (queue:work, schedule:run loop),
# run that. Otherwise the web container defaults to supervisor (nginx + fpm).
if [ "$#" -gt 0 ]; then
    echo "▶️  Running container command: $*"
    exec "$@"
else
    echo "▶️  Starting supervisor (nginx + php-fpm)"
    exec /usr/bin/supervisord -c /etc/supervisord.conf
fi
