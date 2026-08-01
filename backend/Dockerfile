# Laravel API image for Railway/Render.
#
# PostgreSQL is not optional here: filiale isolation is enforced by Postgres
# row-level security (app/Support/Database/RowLevelSecurity.php, applied by the
# 2026_07_30_000004 and 2026_07_31_000001 migrations), so pdo_pgsql must be
# present and the attached database must be Postgres — SQLite will not do.
FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        unzip \
        git \
    && docker-php-ext-install pdo_pgsql pgsql zip intl bcmath opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Laravel serves from public/, and needs mod_rewrite for its front controller.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf \
    && a2enmod rewrite

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# Dependencies first, so a code-only change doesn't re-resolve Composer.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

CMD ["/usr/local/bin/docker-entrypoint.sh"]
