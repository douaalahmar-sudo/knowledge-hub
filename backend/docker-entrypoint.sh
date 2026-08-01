#!/usr/bin/env bash
set -e

# Railway and Render assign the port at runtime and expect the process to bind
# it; the base image hardcodes 80.
: "${PORT:=8080}"
sed -ri "s!^Listen 80\$!Listen ${PORT}!" /etc/apache2/ports.conf
sed -ri "s!<VirtualHost \*:80>!<VirtualHost *:${PORT}>!" /etc/apache2/sites-available/000-default.conf

# Preflight. Without these, Laravel silently falls back to the defaults in
# config/database.php (127.0.0.1 / laravel / root) and the failure surfaces as
# a 200-line PDO stack trace that looks like a database outage rather than a
# missing setting. Fail fast with something actionable instead.
missing=""
[ -z "${APP_KEY:-}" ] && missing="${missing} APP_KEY"
{ [ -z "${DB_URL:-}" ] && [ -z "${DB_HOST:-}" ]; } && missing="${missing} DB_URL(or DB_HOST)"

if [ -n "$missing" ]; then
    echo "FATAL: missing required environment variable(s):${missing}" >&2
    echo "Set them on the service (Render dashboard -> Environment), then redeploy." >&2
    echo "  APP_KEY  generate with: php artisan key:generate --show" >&2
    echo "  DB_URL   full connection string, e.g." >&2
    echo "           postgresql://user:password@host/dbname?sslmode=require" >&2
    echo "Note the name is DB_URL, not DATABASE_URL — see config/database.php." >&2
    exit 1
fi

# Schema, including the row-level-security policies. --force because there is
# no TTY to confirm on, and this is a production environment by definition.
php artisan migrate --force

# Cached after migrate so a failed migration doesn't leave a half-booted app
# serving stale config.
php artisan config:cache
php artisan route:cache

exec apache2-foreground
