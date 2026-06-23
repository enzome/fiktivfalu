FROM php:8.3-fpm-alpine

# ── System packages ──────────────────────────────────────────────────────────
RUN apk add --no-cache \
        nginx \
        sqlite \
        sqlite-libs \
        sqlite-dev \
        php83-mbstring \
        php83-pdo \
        php83-pdo_sqlite \
    && rm -rf /tmp/*

# ── Composer ─────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── App source ───────────────────────────────────────────────────────────────
WORKDIR /var/www/html

COPY . .

# Install PHP dependencies (no dev, optimised autoloader)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Persistent data directories
RUN mkdir -p /var/www/html/db \
             /var/www/html/tmp \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 775 /var/www/html/db /var/www/html/tmp

# ── nginx config ─────────────────────────────────────────────────────────────
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/default.conf /etc/nginx/http.d/default.conf

# ── php-fpm config ───────────────────────────────────────────────────────────
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

# ── Entrypoint ───────────────────────────────────────────────────────────────
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
