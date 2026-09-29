#!/bin/sh
set -eu

mkdir -p /var/www/data
chown -R www-data:www-data /var/www/data
chmod 0770 /var/www/data

php-fpm -D
exec "$@"
