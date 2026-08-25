#!/usr/bin/env bash
# Nightly Postgres backup for CentreVision.
#
# Runs pg_dump inside centrevision-db (which already has POSTGRES_* env vars
# set by docker-compose) and writes a gzipped SQL dump to a host directory
# OUTSIDE any Docker-managed volume, so a `docker volume rm` cannot wipe it.
# Rotates to keep the most recent N days (default 30).
#
# See docs/backup-and-restore.md for install and restore instructions.

set -euo pipefail

# --- config (all overridable via env) --------------------------------------
BACKUP_DIR="${BACKUP_DIR:-/opt/centrevision/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
CONTAINER="${CONTAINER:-centrevision-db}"

# --- guards ----------------------------------------------------------------
if ! command -v docker >/dev/null 2>&1; then
    echo "[$(date -Is)] FAIL: docker not on PATH" >&2
    exit 1
fi

if ! docker inspect --format '{{.State.Running}}' "$CONTAINER" 2>/dev/null | grep -q true; then
    echo "[$(date -Is)] FAIL: container $CONTAINER is not running" >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR"

STAMP="$(date +'%Y%m%d-%H%M%S')"
DEST="$BACKUP_DIR/centrevision-$STAMP.sql.gz"
TMP="$DEST.partial"

echo "[$(date -Is)] backup starting -> $DEST"

# --- dump ------------------------------------------------------------------
# The container has POSTGRES_USER / POSTGRES_DB / POSTGRES_PASSWORD in its
# own environment (from docker-compose). Passing them through here means no
# credentials live in this script or on the host filesystem.
#
# --clean --if-exists writes a self-restoring dump: `psql < dump.sql` (after
# gunzip) drops the existing tables and rebuilds. Safe to feed into a fresh
# database or one that already has schema.
docker exec "$CONTAINER" sh -c '
    set -eu
    PGPASSWORD="$POSTGRES_PASSWORD" pg_dump \
        --no-owner \
        --no-acl \
        --clean \
        --if-exists \
        -U "$POSTGRES_USER" \
        -d "$POSTGRES_DB"
' | gzip -9 > "$TMP"

# --- publish atomically ---------------------------------------------------
# A concurrent `ls` should never pick up a half-written .sql.gz. If pg_dump
# died mid-stream, `set -o pipefail` above already killed us before this.
mv "$TMP" "$DEST"

# --- rotate ----------------------------------------------------------------
# Only prune our own files; never anything else that happens to sit in the
# backup directory (someone might drop a manual dump alongside).
find "$BACKUP_DIR" -maxdepth 1 -type f -name 'centrevision-*.sql.gz' \
    -mtime "+$RETENTION_DAYS" -delete

# --- report ----------------------------------------------------------------
SIZE="$(du -h "$DEST" | cut -f1)"
COUNT="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'centrevision-*.sql.gz' | wc -l)"

echo "[$(date -Is)] backup complete: $DEST ($SIZE, ${COUNT} snapshot(s) retained)"
