#!/usr/bin/env bash
set -e

# Railway and Render assign the port at runtime and expect the process to bind
# it; the base image hardcodes 80.
: "${PORT:=8080}"
sed -ri "s!^Listen 80\$!Listen ${PORT}!" /etc/apache2/ports.conf
sed -ri "s!<VirtualHost \*:80>!<VirtualHost *:${PORT}>!" /etc/apache2/sites-available/000-default.conf

# Schema, including the row-level-security policies. --force because there is
# no TTY to confirm on, and this is a production environment by definition.
php artisan migrate --force

# Cached after migrate so a failed migration doesn't leave a half-booted app
# serving stale config.
php artisan config:cache
php artisan route:cache

exec apache2-foreground
