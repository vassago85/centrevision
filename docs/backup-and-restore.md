# Backups and restore

The `centrevision` Postgres database is the only irreplaceable state in the
stack. Plate crops, generated PDFs, Redis, logs and caches can all be rebuilt
or safely re-created. This document covers backing up and restoring that one
database.

## What we back up, and why the design is defensive

`scripts/backup.sh` runs `pg_dump` **inside** the `centrevision-db` container,
pipes the output through `gzip -9` on the host, and writes the archive to a
host directory that is **not** a Docker volume. That last part is deliberate:
the incident that motivated this document was a `docker volume rm
centrevision_db-data` executed during misdiagnosed debugging. Anything living
in a Docker volume can be deleted by a wrong `docker volume rm`. Backups must
live somewhere `docker volume rm` cannot reach.

Recommended host path: `/opt/centrevision/backups/`. That directory is
owned by `root`, sits outside `/var/lib/docker/`, and survives any container
or volume operation.

## Install

On the server, once — this assumes the repo is checked out at
`/opt/centrevision` (the same place `docker-compose.yml` lives):

```bash
sudo mkdir -p /opt/centrevision/backups
sudo chmod +x /opt/centrevision/scripts/backup.sh
```

Then drop this into `/etc/cron.d/centrevision-backup`:

```
# Nightly Postgres backup for CentreVision, 02:00 SAST.
# Writes to /opt/centrevision/backups and rotates to keep 30 snapshots.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
0 2 * * * root /opt/centrevision/scripts/backup.sh >> /opt/centrevision/backups/backup.log 2>&1
```

Cron picks that up on the next minute — no `systemctl reload` needed.

## Verify before you rely on it

Never trust a backup you have not restored at least once.

Run the script by hand and inspect the output:

```bash
sudo /opt/centrevision/scripts/backup.sh
ls -lh /opt/centrevision/backups/
```

You should see a `centrevision-YYYYmmdd-HHMMSS.sql.gz` file in the tens of
MB range (empty DB will be ~100 KB — small is expected on a fresh install).
Peek at the header:

```bash
sudo zcat /opt/centrevision/backups/centrevision-*.sql.gz | head -40
```

Expect Postgres dump preamble: `SET statement_timeout = 0;`, `SET client_encoding …`,
followed by `DROP TABLE IF EXISTS …` lines from `--clean --if-exists`.

Confirm the archive is readable end-to-end (gzip catches truncation):

```bash
sudo gzip -t /opt/centrevision/backups/centrevision-*.sql.gz && echo "OK"
```

## Restore

Restoring is intentionally the simple inverse of backup. The archive was
written with `--clean --if-exists`, so it drops the target tables and rebuilds
them; feed it into `psql` inside the running `centrevision-db` container.

```bash
# 1. Stop the app tier so nothing is writing to the DB during restore.
docker compose stop app queue scheduler

# 2. Pick the snapshot to restore.
ARCHIVE=/opt/centrevision/backups/centrevision-20260824-020000.sql.gz

# 3. Pipe it in. The container's env supplies POSTGRES_USER / POSTGRES_DB.
zcat "$ARCHIVE" | docker exec -i centrevision-db sh -c \
    'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'

# 4. Bring the app tier back.
docker compose start app queue scheduler
```

Restore is idempotent — running it against an already-populated DB simply
replaces the contents. If the app is currently broken because the DB volume
was wiped, step 1 is a no-op (the app is already down); step 3 rebuilds the
schema and data; step 4 brings the app back up looking exactly as it did at
the moment the backup was taken.

## Off-server copy (recommended, not required)

Local backups only defend against operator error and application bugs. They
do not defend against a lost server. Rsync (or rclone) the archives to
somewhere else after each run — this can hang off the same cron entry.

Simplest: add an `rsync` after the backup command in `/etc/cron.d/centrevision-backup`:

```
0 2 * * * root /opt/centrevision/scripts/backup.sh && \
    rsync -a --delete /opt/centrevision/backups/ backup-user@offsite:/backups/centrevision/ \
    >> /opt/centrevision/backups/backup.log 2>&1
```

For cloud object storage, wrap with `rclone sync /opt/centrevision/backups
remote:centrevision-backups` and set retention on the remote side.

## Retention

`scripts/backup.sh` prunes files older than 30 days by default. Override with:

```
BACKUP_DIR=/opt/centrevision/backups RETENTION_DAYS=90 /opt/centrevision/scripts/backup.sh
```

For long-term retention beyond the local window, use the off-server copy
above and set retention on the remote — do not extend `RETENTION_DAYS`
indefinitely on the same disk that runs the app.

## When to bump the schedule

02:00 is a reasonable choice for a customer whose traffic is 06:00–19:00
(the Postgres load overnight is minimal). Bump to more frequent if the
customer generates more than ~50 MB of new plate data per day and their
retention SLA is tighter than a full day of loss. `scripts/backup.sh` is
safe to run concurrently on separate schedules — each writes a uniquely
timestamped file.
