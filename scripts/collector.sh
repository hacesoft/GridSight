#!/bin/sh
set -eu
# Ruční diagnostika jednoho GridSight sběrače; instalátor jej spravuje sám.
APP_ID=hc_gridsight
WEB=nextcloud-app CRON=nextcloud-cron
if ! docker exec "$CRON" test -f /var/www/html/occ >/dev/null 2>&1; then CRON=''; fi
TARGET="${CRON:-$WEB}"
SCRIPT="/var/www/html/custom_apps/$APP_ID/bin/collector-daemon.php"
LOG=/tmp/hc_gridsight-collector.log
pids() {
 docker exec "$1" sh -c 'ps aux 2>/dev/null | awk '\''$0 ~ /[p]hp[[:space:]]+\/var\/www\/html\/custom_apps\/hc_gridsight\/bin\/collector-daemon\.php/ && $2 ~ /^[0-9]+$/ { print $2 }'\''' || true
}
stop_in() {
 for pid in $(pids "$1"); do docker exec "$1" kill -TERM "$pid" >/dev/null 2>&1 || true; done
}
case "${1:-status}" in
 start)
  docker exec "$TARGET" test -f "$SCRIPT" || { echo 'GridSight collector code is missing' >&2; exit 1; }
  docker exec "$TARGET" sh -c 'touch /tmp/hc_gridsight-collector.log && chown www-data:www-data /tmp/hc_gridsight-collector.log && chmod 600 /tmp/hc_gridsight-collector.log'
  docker exec -d -u www-data "$TARGET" sh -c "php '$SCRIPT' >>'$LOG' 2>&1"
  sleep 2 ;;
 stop) stop_in "$WEB"; [ -z "$CRON" ] || stop_in "$CRON" ;;
 restart) sh "$0" stop; sh "$0" start; exit $? ;;
 logs) docker exec "$TARGET" sh -c 'tail -n 120 /tmp/hc_gridsight-collector.log 2>/dev/null || true'; exit 0 ;;
 status) ;;
 *) echo 'Usage: sudo sh scripts/collector.sh {status|start|stop|restart|logs}' >&2; exit 2 ;;
esac
if [ -n "$(pids "$TARGET")" ]; then echo "GridSight collector: RUNNING ($TARGET)"; else echo "GridSight collector: STOPPED ($TARGET)"; fi
