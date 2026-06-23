#!/bin/sh
set -e

# Ensure writable directories exist with correct ownership
mkdir -p /var/www/html/db /var/www/html/tmp
chown -R www-data:www-data /var/www/html/db /var/www/html/tmp

# Start php-fpm in background
php-fpm --daemonize --fpm-config /usr/local/etc/php-fpm.conf

# Start nginx in foreground (keeps the container alive)
exec nginx -g 'daemon off;'
