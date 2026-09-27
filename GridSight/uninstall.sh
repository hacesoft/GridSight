#!/bin/sh
set -eu
# Odinstalace kódu zachová naměřená data, nastavení i staré tabulky.
[ "${1:-}" = '' ] || { echo 'Usage: sudo sh uninstall.sh (history is always preserved)' >&2; exit 2; }
[ "$(id -u)" -eq 0 ] || { echo 'Run with sudo sh uninstall.sh' >&2; exit 1; }
WEB=nextcloud-app CRON=nextcloud-cron APP=hc_gridsight
TARGET=/var/www/html/custom_apps/hc_gridsight
command -v docker >/dev/null || exit 1
docker exec "$WEB" test -f /var/www/html/occ || { echo 'Nextcloud web container is missing.' >&2; exit 1; }
if ! docker exec "$CRON" test -f /var/www/html/occ >/dev/null 2>&1; then CRON=''; fi
DATA_DIR="$(docker exec -u www-data "$WEB" php /var/www/html/occ config:system:get datadirectory)"
case "$DATA_DIR" in /*) ;; *) echo 'Could not determine data directory.' >&2; exit 1;; esac
BACKUP="$DATA_DIR/hc-core-deployment-backups/hc_gridsight.uninstall.$(date +%Y%m%d%H%M%S).$$"
# Dohled nesmí sběrač znovu spustit při přemísťování souborů.
docker exec -u www-data "$WEB" php -r 'require "/var/www/html/lib/base.php"; \OC::$server->get(\OCP\IConfig::class)->setAppValue("hc_gridsight", "collector_supervisor_paused", "1");'
for c in "$WEB" ${CRON:-}; do
    docker exec "$c" sh -c 'ps aux 2>/dev/null | awk '\''$0 ~ /[p]hp[[:space:]]+\/var\/www\/html\/custom_apps\/hc_gridsight\/bin\/collector-daemon\.php/ && $2 ~ /^[0-9]+$/ { print $2 }'\'' | xargs -r kill -TERM' || true
done
sleep 2
for c in "$WEB" ${CRON:-}; do
    remaining="$(docker exec "$c" sh -c 'ps aux 2>/dev/null | grep "[p]hp /var/www/html/custom_apps/hc_gridsight/bin/collector-daemon.php"' || true)"
    [ -z "$remaining" ] || { echo "Collector still runs in $c; no files were removed." >&2; exit 1; }
done
docker exec -u www-data "$WEB" php /var/www/html/occ app:disable "$APP"
# Pouze cílená stará registrace jobu, nikoli celá tabulka background jobs.
docker exec -u www-data "$WEB" php -r 'require "/var/www/html/lib/base.php"; \OC::$server->get(\OCP\IDBConnection::class)->executeStatement("DELETE FROM *PREFIX*jobs WHERE class IN (?, ?)", ["OCA\\HcGridSight\\BackgroundJob\\CollectorWatchdogJob", "OCA\\HcGridSight\\BackgroundJob\\HistoryCollectorJob"]);'
if docker exec "$WEB" test -d "$TARGET"; then
    docker exec "$WEB" sh -c 'mkdir -p "$2" && mv "$1" "$2/runtime"' sh "$TARGET" "$BACKUP"
fi
if [ -n "$CRON" ] && docker exec "$CRON" test -d "$TARGET"; then
    docker exec "$CRON" sh -c 'mkdir -p "$2" && mv "$1" "$2/cron-runtime"' sh "$TARGET" "$BACKUP"
fi
echo 'GridSight code disabled and archived; history and settings preserved.'
