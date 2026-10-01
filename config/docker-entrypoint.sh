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
stream_broker="${RUSTDESK_STREAM_BROKER:-/usr/local/bin/rustdesk-stream-broker}"
if [ ! -x "$stream_broker" ]; then
    echo "RustDesk stream broker is not executable: $stream_broker" >&2
    exit 2
fi

broker_pid=''
main_pid=''
cleanup() {
    trap - EXIT HUP INT TERM
    [ -z "$main_pid" ] || kill "$main_pid" 2>/dev/null || true
    [ -z "$broker_pid" ] || kill "$broker_pid" 2>/dev/null || true
    [ -z "$main_pid" ] || wait "$main_pid" 2>/dev/null || true
    [ -z "$broker_pid" ] || wait "$broker_pid" 2>/dev/null || true
}
trap cleanup EXIT
trap 'exit 143' HUP INT TERM

"$stream_broker" &
broker_pid=$!
sleep 0.1
if ! kill -0 "$broker_pid" 2>/dev/null; then
    wait "$broker_pid"
    exit $?
fi

"$@" &
main_pid=$!
while kill -0 "$broker_pid" 2>/dev/null && kill -0 "$main_pid" 2>/dev/null; do
    sleep 1
done

status=0
if ! kill -0 "$broker_pid" 2>/dev/null; then
    wait "$broker_pid" || status=$?
else
    wait "$main_pid" || status=$?
fi
exit "$status"
