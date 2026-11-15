#!/bin/sh
set -eu

wait_for_tcp() {
  host="$1"
  port="$2"
  name="$3"
  attempts=0
  until php -r '$s = @fsockopen($argv[1], (int) $argv[2]); if (!$s) exit(1); fclose($s);' "$host" "$port"; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge 60 ]; then
      echo "Timed out waiting for $name at $host:$port" >&2
      exit 1
    fi
    sleep 2
  done
}

if [ "${RUN_BOOTSTRAP:-false}" = "true" ]; then
  mkdir -p storage/app/public storage/framework/cache storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache
  chown -R www-data:www-data storage bootstrap/cache
  cp -a /opt/app-public/. /var/www/public/
  wait_for_tcp "${PG_DB_HOST:-postgres}" "${PG_DB_PORT:-5432}" PostgreSQL
  wait_for_tcp "${MYSQL_DB_HOST:-mysql}" "${MYSQL_DB_PORT:-3306}" MySQL
  wait_for_tcp "${CLICKHOUSE_DB_HOST:-clickhouse}" "${CLICKHOUSE_DB_PORT:-8123}" ClickHouse

  php artisan migrate --force
  php artisan install
  php artisan storage:link 2>/dev/null || true
  php artisan optimize:clear
  touch /tmp/app-ready
fi

exec "$@"
