#!/bin/sh
set -eu

data_dir="${RUSTDESK_DATA_DIR:-/var/www/data}"
geoip_database="${RUSTDESK_GEOIP_DATABASE:-${data_dir}/GeoLite2-City.mmdb}"
geoip_seed="${RUSTDESK_GEOIP_SEED:-/usr/share/rustdesk-api/GeoLite2-City.mmdb}"

mkdir -p "$data_dir"

if [ ! -s "$geoip_database" ] && [ -s "$geoip_seed" ]; then
    mkdir -p "$(dirname "$geoip_database")"
    geoip_temp="${geoip_database}.tmp.$$"
    trap 'rm -f "$geoip_temp"' EXIT HUP INT TERM
    cp "$geoip_seed" "$geoip_temp"
    chmod 0644 "$geoip_temp"
    mv -f "$geoip_temp" "$geoip_database"
    trap - EXIT HUP INT TERM
fi

chown -R www-data:www-data "$data_dir"
chmod 0770 "$data_dir"

php-fpm -D
exec "$@"
