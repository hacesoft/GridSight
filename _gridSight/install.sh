#!/bin/sh
# HC_INSTALLER_REV=2026-09-26.2 (běžná instalace bez převodu dat)
set -eu

# Úspěšná instalace vypíše stejné čtyři řádky ve všech aplikacích.
# Při chybě se místo toho zobrazí celý protokol.
if [ "${HC_INSTALL_VERBOSE_INTERNAL:-0}" != "1" ]; then
    INSTALL_LOG="$(mktemp)"
    trap 'rm -f "$INSTALL_LOG"' EXIT
    trap 'exit 129' HUP
    trap 'exit 130' INT
    trap 'exit 143' TERM
    if HC_INSTALL_VERBOSE_INTERNAL=1 sh "$0" >"$INSTALL_LOG" 2>&1; then
        APP_INFO="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)/src/appinfo/info.xml"
        APP_ID="$(sed -n 's:.*<id>\([^<]*\)</id>.*:\1:p' "$APP_INFO" | head -n 1)"
        APP_NAME="$(sed -n 's:.*<name>\([^<]*\)</name>.*:\1:p' "$APP_INFO" | head -n 1 | sed 's/&amp;/\&/g')"
        NEW_VERSION="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$APP_INFO" | head -n 1)"
        OLD_VERSION="$(sed -n 's/^Install previous version: //p' "$INSTALL_LOG" | head -n 1)"
        WEB_NAME="$(sed -n 's/^Nextcloud web container: //p' "$INSTALL_LOG" | head -n 1)"
        if [ -z "$APP_ID" ] || [ -z "$NEW_VERSION" ] || [ -z "$OLD_VERSION" ] || [ -z "$WEB_NAME" ]; then
            cat "$INSTALL_LOG" >&2
            echo 'ERROR: Could not verify the installation summary.' >&2
            exit 1
        fi
        SIZE_KIB="$(docker exec "$WEB_NAME" du -sk "/var/www/html/custom_apps/$APP_ID" 2>/dev/null | awk 'NR==1 {print $1}')"
        case "$SIZE_KIB" in ''|*[!0-9]*) SIZE_KIB="$(du -sk "$(dirname "$APP_INFO")/.." | awk 'NR==1 {print $1}')";; esac
        printf 'Aplikace: %s\nVerze: %s -> %s\nVelikost instalace: %s KiB\nHotovo.\n' "${APP_NAME:-$APP_ID}" "$OLD_VERSION" "$NEW_VERSION" "$SIZE_KIB"
        grep '^WARNING:' "$INSTALL_LOG" >&2 || true
        exit 0
    else
        STATUS=$?
        cat "$INSTALL_LOG" >&2
        exit "$STATUS"
    fi
fi

PROJECT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
for file in scripts/install-transaction.sh scripts/custom-apps-safety.sh scripts/ensure-schema.php; do
    [ -f "$PROJECT_DIR/$file" ] || { echo "ERROR: Neúplný zdrojový balík GridSight: chybí $file. Rozbalte celý source ZIP." >&2; exit 1; }
done
. "$PROJECT_DIR/scripts/install-transaction.sh"
APP_DIR="$PROJECT_DIR/src"
INFO="$APP_DIR/appinfo/info.xml"
[ -f "$INFO" ] || { echo "ERROR: $INFO not found" >&2; exit 1; }
APP_ID="$(sed -n 's:.*<id>\([^<]*\)</id>.*:\1:p' "$INFO" | head -n 1)"
APP_NAME="$(sed -n 's:.*<name>\([^<]*\)</name>.*:\1:p' "$INFO" | head -n 1)"
MIN_NC_MAJOR="$(sed -n 's:.*<nextcloud[^>]*min-version="\([0-9][0-9]*\)".*:\1:p' "$INFO" | head -n 1)"
MAX_NC_MAJOR="$(sed -n 's:.*<nextcloud[^>]*max-version="\([0-9][0-9]*\)".*:\1:p' "$INFO" | head -n 1)"
[ -n "$APP_ID" ] || { echo "ERROR: app id not found" >&2; exit 1; }
[ -n "$APP_NAME" ] || APP_NAME="$APP_ID"
WEB_CONTAINER=""; CRON_CONTAINER=""
say(){ printf '%s\n' "$*"; }; die(){ printf 'ERROR: %s\n' "$*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || die "Run this installer with: sudo sh install.sh"
chmod 755 "$0" 2>/dev/null || true
command -v docker >/dev/null 2>&1 || die "Docker was not found."
LOCK_DIR="$PROJECT_DIR/.hc-install-lock"
mkdir "$LOCK_DIR" 2>/dev/null || die "Another installation or an interrupted install lock exists: $LOCK_DIR"
trap 'rmdir "$LOCK_DIR" 2>/dev/null || true' EXIT HUP INT TERM
RUNTIME_ITEMS="appinfo css img js l10n lib templates bin"
container_has_occ(){ docker exec "$1" sh -c 'test -f /var/www/html/occ' >/dev/null 2>&1; }
if docker ps --format '{{.Names}}' | grep -qx 'nextcloud-app' && container_has_occ nextcloud-app; then WEB_CONTAINER=nextcloud-app; fi
if docker ps --format '{{.Names}}' | grep -qx 'nextcloud-cron' && container_has_occ nextcloud-cron; then CRON_CONTAINER=nextcloud-cron; fi
if [ -z "$WEB_CONTAINER" ]; then
 for c in $(docker ps --format '{{.Names}}' | grep -Ei 'nextcloud' || true); do case "$c" in *cron*) continue;; esac; if container_has_occ "$c"; then WEB_CONTAINER="$c"; break; fi; done
fi
if [ -z "$CRON_CONTAINER" ]; then
 for c in $(docker ps --format '{{.Names}}' | grep -Ei 'nextcloud.*cron|cron.*nextcloud' || true); do if container_has_occ "$c"; then CRON_CONTAINER="$c"; break; fi; done
fi
[ -n "$WEB_CONTAINER" ] || die "Could not find the Nextcloud web container."
say "Nextcloud web container: $WEB_CONTAINER"; [ -n "$CRON_CONTAINER" ] && say "Nextcloud cron container: $CRON_CONTAINER"

# Před prvním occ odsunout staré duplicitní kopie mimo custom_apps.
. "$PROJECT_DIR/scripts/custom-apps-safety.sh"
hc_clean_custom_apps "$APP_ID" "$WEB_CONTAINER" "$CRON_CONTAINER" || die "Could not safely move duplicate application trees out of custom_apps."
NC_STATUS="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ status --output=json 2>/dev/null || true)"
NC_VERSION="$(printf '%s' "$NC_STATUS" | sed -n 's/.*"versionstring":"\([^"]*\)".*/\1/p')"
if [ -n "$NC_VERSION" ]; then
 say "Nextcloud version: $NC_VERSION"; NC_MAJOR="$(printf '%s' "$NC_VERSION" | cut -d. -f1)"
 case "$NC_MAJOR" in ''|*[!0-9]*) ;; *)
  [ -z "$MIN_NC_MAJOR" ] || [ "$NC_MAJOR" -ge "$MIN_NC_MAJOR" ] || die "$APP_NAME requires Nextcloud $MIN_NC_MAJOR or newer."
  [ -z "$MAX_NC_MAJOR" ] || [ "$NC_MAJOR" -le "$MAX_NC_MAJOR" ] || die "$APP_NAME supports Nextcloud up to major $MAX_NC_MAJOR."
 ;; esac
fi
for item in $RUNTIME_ITEMS; do [ -e "$APP_DIR/$item" ] || die "Required application item is missing in src/: $item"; done
SOURCE_VERSION="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$INFO" | head -n 1)"; [ -n "$SOURCE_VERSION" ] || die "Could not determine app version."
say "$APP_NAME source version: $SOURCE_VERSION"
case "$APP_ID" in ''|*[!a-z0-9_]*) die "Invalid application id.";; esac
INSTALLED_VERSION="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ config:app:get "$APP_ID" installed_version 2>/dev/null || true)"
say "Install previous version: ${INSTALLED_VERSION:-nová instalace}"
# U nové instalace mohou chybět všechny tabulky. Částečnou strukturu
# instalátor nedoplňuje naslepo, aby nepřekryl problém v existujících datech.
if ! docker exec -u www-data "$WEB_CONTAINER" php -r 'require "/var/www/html/lib/base.php"; $db=\OC::$server->get(\OCP\IDBConnection::class); $found=0; foreach (["hist","events","snapshots","runtime"] as $suffix) { $found+=(int)$db->tableExists("hc_gridsight_".$suffix); } exit(($found===0 || $found===4) ? 0 : 1);'; then
 die "GridSight database tables are incomplete; existing rows were not changed."
fi
if [ -n "$INSTALLED_VERSION" ]; then
 docker exec "$WEB_CONTAINER" php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 1 : 0);' "$INSTALLED_VERSION" "$SOURCE_VERSION" || die "Downgrade refused: $INSTALLED_VERSION -> $SOURCE_VERSION. Restore a matched code/database backup instead."
fi
CORE_LIST="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ app:list --enabled --output=json 2>/dev/null | tr -d '\n\r ' || true)"
CORE_VERSION="$(printf '%s' "$CORE_LIST" | sed -n 's/.*"hc_shared_app_core":"\([^"]*\)".*/\1/p')"
CORE_CONTRACT="$APP_DIR/appinfo/hc_shared_app_core.json"
[ -f "$CORE_CONTRACT" ] || die "Missing Core contract: $CORE_CONTRACT"
REQUIRED_CORE_VERSION="$(sed -n 's/.*"requiredVersion"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$CORE_CONTRACT" | head -n 1)"
[ -n "$REQUIRED_CORE_VERSION" ] || die "Core contract has no requiredVersion."
if [ -z "$CORE_VERSION" ]; then
 die "Shared App Core is not installed or enabled. Required version: $REQUIRED_CORE_VERSION or newer."
elif ! docker exec "$WEB_CONTAINER" php -r 'exit(version_compare($argv[1], $argv[2], ">=") ? 0 : 1);' "$CORE_VERSION" "$REQUIRED_CORE_VERSION"; then
 die "Shared App Core $CORE_VERSION is older than required $REQUIRED_CORE_VERSION."
else
 say "Shared App Core version: $CORE_VERSION (required >= $REQUIRED_CORE_VERSION)"
fi
DATA_DIRECTORY="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ config:system:get datadirectory)"
case "$DATA_DIRECTORY" in /*) ;; *) die "Cannot locate Nextcloud data directory for deployment backup.";; esac
TMP_LOCAL="$(mktemp -d)"
SUPERVISOR_PAUSED=0
HISTORY_RESTORE_VALUE=""
SUPERVISOR_RESTORE_VALUE="0"
COLLECTOR_WAS_RUNNING=0
PREVIOUS_ENABLED=0
if docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ app:list --enabled 2>/dev/null | grep -q -- "- $APP_ID:"; then PREVIOUS_ENABLED=1; fi
restore_supervisor_setting(){
 if [ "$SUPERVISOR_PAUSED" -eq 1 ]; then
  docker exec -u www-data "$WEB_CONTAINER" php -r 'require "/var/www/html/lib/base.php"; \OC::$server->get(\OCP\IConfig::class)->setAppValue("hc_gridsight", "collector_supervisor_paused", $argv[1]);' "$SUPERVISOR_RESTORE_VALUE" || return 1
  SUPERVISOR_PAUSED=0
 fi
}
cleanup_install(){
 install_status=$?
 trap - EXIT HUP INT TERM
 rollback_ok=1
 if [ "$install_status" -ne 0 ]; then
  # Historický sběrač nesmí běžet při výměně jeho souborů.
 if [ "${HC_TX_WEB_NEW:-0}" -eq 1 ]; then
   for collector_container in "$WEB_CONTAINER" ${CRON_CONTAINER:-}; do
    for collector_pid in $(collector_pids "$collector_container"); do
     docker exec "$collector_container" sh -c 'kill -TERM "$1"' sh "$collector_pid" >/dev/null 2>&1 || true
    done
   done
   rollback_wait=0
   while [ "$rollback_wait" -lt 45 ]; do
    [ -z "$(collector_pids "$WEB_CONTAINER")$( [ -z "$CRON_CONTAINER" ] || collector_pids "$CRON_CONTAINER")" ] && break
    sleep 1
    rollback_wait=$((rollback_wait + 1))
   done
  fi
  if [ "${HC_TX_WEB_NEW:-0}" -eq 1 ] && [ -n "$(collector_pids "$WEB_CONTAINER")$( [ -z "$CRON_CONTAINER" ] || collector_pids "$CRON_CONTAINER")" ]; then
   printf 'ERROR: Sběrač stále běží; záloha v %s zůstala zachována, kód nelze bezpečně vyměnit za běhu.\n' "$BACKUP_DIR" >&2
   rollback_ok=0
  else
   hc_tx_restore || rollback_ok=0
  fi
 fi
 if [ "$rollback_ok" -eq 0 ] && [ "$SUPERVISOR_PAUSED" -eq 1 ]; then
  printf 'ERROR: Dohled nad sběračem zůstal pozastaven; po opravě obnovte kód ze zálohy %s a stav collector_supervisor_paused=%s.\n' "$BACKUP_DIR" "$SUPERVISOR_RESTORE_VALUE" >&2
 elif ! restore_supervisor_setting; then
  printf 'WARNING: restore hc_gridsight collector_supervisor_paused to %s in Nextcloud app settings.\n' "$SUPERVISOR_RESTORE_VALUE" >&2
 fi
 if [ "$install_status" -ne 0 ] && [ "$rollback_ok" -eq 1 ] && [ "$COLLECTOR_WAS_RUNNING" -eq 1 ] && [ "$HISTORY_RESTORE_VALUE" != 0 ] && [ "$SUPERVISOR_RESTORE_VALUE" != 1 ] && [ "$PREVIOUS_ENABLED" -eq 1 ]; then
  old_collector_container="$WEB_CONTAINER"
  [ -n "$CRON_CONTAINER" ] && old_collector_container="$CRON_CONTAINER"
  if docker exec "$old_collector_container" test -f "$TARGET_DIR/bin/collector-daemon.php" >/dev/null 2>&1 && [ -z "$(collector_pids "$old_collector_container")" ]; then
   docker exec "$old_collector_container" sh -c 'touch /tmp/hc_gridsight-collector.log && chown www-data:www-data /tmp/hc_gridsight-collector.log && chmod 600 /tmp/hc_gridsight-collector.log' >/dev/null 2>&1 || true
   docker exec -d -u www-data "$old_collector_container" sh -c "php '$TARGET_DIR/bin/collector-daemon.php' >>/tmp/hc_gridsight-collector.log 2>&1" >/dev/null 2>&1 || true
   printf 'Previous LINEA history collector restart requested after failure.\n' >&2
  fi
 fi
 hc_cleanup_install_stage
 rm -rf "$TMP_LOCAL"
 rmdir "$LOCK_DIR" 2>/dev/null || true
 exit "$install_status"
}
trap cleanup_install EXIT HUP INT TERM
STAGE="$TMP_LOCAL/$APP_ID"; mkdir -p "$STAGE"
for item in $RUNTIME_ITEMS; do cp -R "$APP_DIR/$item" "$STAGE/"; done
TARGET_DIR="/var/www/html/custom_apps/$APP_ID"; NEW_DIR="/tmp/${APP_ID}.new.$$"; BACKUP_DIR="${DATA_DIRECTORY}/hc-core-deployment-backups/${APP_ID}.$(date +%Y%m%d%H%M%S).$$"; REMOTE_TMP="/tmp/${APP_ID}.install.$$"
hc_tx_begin "$WEB_CONTAINER" "$CRON_CONTAINER" "$APP_ID" "$TARGET_DIR" "$BACKUP_DIR" "$INSTALLED_VERSION" "$PREVIOUS_ENABLED"
docker exec "$WEB_CONTAINER" sh -c 'mkdir -p /var/www/html/custom_apps'
docker exec "$WEB_CONTAINER" rm -rf "$REMOTE_TMP" "$NEW_DIR" >/dev/null 2>&1 || true
docker exec "$WEB_CONTAINER" mkdir -p "$REMOTE_TMP"; docker cp "$STAGE/." "$WEB_CONTAINER:$REMOTE_TMP/"
docker exec "$WEB_CONTAINER" sh -c "mv '$REMOTE_TMP' '$NEW_DIR' && chown -R www-data:www-data '$NEW_DIR'"
# Ověřit PHP v připravené kopii, dokud běží původní verze.
docker exec "$WEB_CONTAINER" sh -c 'find "$1" -type f -name "*.php" -exec php -l {} \;' sh "$NEW_DIR" >"$TMP_LOCAL/php-lint.log" 2>&1 || { cat "$TMP_LOCAL/php-lint.log"; die "Staged PHP validation failed."; }
if grep -q 'Errors parsing' "$TMP_LOCAL/php-lint.log"; then cat "$TMP_LOCAL/php-lint.log"; die "Staged PHP validation failed."; fi
# Deaktivace aplikace sama sběrač nezastaví. Pozastavit jeho dohled a poslat
# SIGTERM, aby se rozpracovaný agregát mohl uložit; history_enabled neměnit.
HISTORY_RESTORE_VALUE="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ config:app:get "$APP_ID" history_enabled 2>/dev/null || true)"
[ -n "$HISTORY_RESTORE_VALUE" ] || HISTORY_RESTORE_VALUE=1
SUPERVISOR_RESTORE_VALUE="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ config:app:get "$APP_ID" collector_supervisor_paused 2>/dev/null || true)"
[ -n "$SUPERVISOR_RESTORE_VALUE" ] || SUPERVISOR_RESTORE_VALUE=0
docker exec -u www-data "$WEB_CONTAINER" php -r 'require "/var/www/html/lib/base.php"; \OC::$server->get(\OCP\IConfig::class)->setAppValue("hc_gridsight", "collector_supervisor_paused", "1");'
SUPERVISOR_PAUSED=1
say "Pausing LINEA collector supervisor and draining the current collector..."
collector_pids(){
 docker exec "$1" sh -c 'ps aux 2>/dev/null | awk '\''$0 ~ /[p]hp[[:space:]]+\/var\/www\/html\/custom_apps\/hc_gridsight\/bin\/collector-daemon\.php/ && $2 ~ /^[0-9]+$/ { print $2 }'\''' || true
}
for collector_container in "$WEB_CONTAINER" ${CRON_CONTAINER:-}; do
 old_pids="$(collector_pids "$collector_container")"
 [ -z "$old_pids" ] || COLLECTOR_WAS_RUNNING=1
 for collector_pid in $old_pids; do
  docker exec "$collector_container" sh -c 'kill -TERM "$1"' sh "$collector_pid" >/dev/null 2>&1 || true
 done
 collector_stopped=0
 wait_count=0
 while [ "$wait_count" -lt 45 ]; do
  if [ -z "$(collector_pids "$collector_container")" ]; then collector_stopped=1; break; fi
  sleep 1
  wait_count=$((wait_count + 1))
 done
 [ "$collector_stopped" -eq 1 ] || die "LINEA collector did not stop within 45 seconds in $collector_container; history setting is restored by cleanup."
done
# Sběrač už doplnil svůj agregát; teprve nyní se mění kód.
hc_tx_disable_web
hc_tx_backup_web
hc_tx_place_web "$NEW_DIR"
# Zkušební soubor rozliší sdílený svazek od samostatné kopie pro cron.
PROBE_FILE=".hc-deploy-probe-$$"
docker exec "$WEB_CONTAINER" touch "$TARGET_DIR/$PROBE_FILE"
if [ -n "$CRON_CONTAINER" ] && [ "$CRON_CONTAINER" != "$WEB_CONTAINER" ]; then
 if docker exec "$CRON_CONTAINER" test -f "$TARGET_DIR/$PROBE_FILE"; then
  say "Cron sees the same deployed application tree."
 else
  CRON_TMP="/tmp/${APP_ID}.install.$$"
  docker exec "$CRON_CONTAINER" mkdir -p "$CRON_TMP"
  docker cp "$STAGE/." "$CRON_CONTAINER:$CRON_TMP/"
  hc_tx_backup_cron
  hc_tx_place_cron "$CRON_TMP"
 fi
fi
docker exec "$WEB_CONTAINER" rm -f "$TARGET_DIR/$PROBE_FILE"
docker exec "$WEB_CONTAINER" php -r 'if (function_exists("opcache_reset")) { opcache_reset(); }' >/dev/null 2>&1 || true
say "Checking application database structure..."
docker exec -i -u www-data "$WEB_CONTAINER" php -l < "$PROJECT_DIR/scripts/ensure-schema.php" >/dev/null || die "Invalid database check PHP syntax."
docker exec -i -u www-data "$WEB_CONTAINER" php < "$PROJECT_DIR/scripts/ensure-schema.php" || die "Could not complete the application database structure; original rows have not been deleted."
docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ app:enable "$APP_ID"
# Cíleně zapsat verzi aplikace; tabulky byly ověřeny, řádky se nepřevádějí.
CONFIG_VERSION_BEFORE="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ config:app:get "$APP_ID" installed_version 2>/dev/null || true)"
if [ "$CONFIG_VERSION_BEFORE" != "$SOURCE_VERSION" ]; then
 docker exec -u www-data "$WEB_CONTAINER" php -r 'require "/var/www/html/lib/base.php"; if (!\OC_App::updateApp($argv[1])) { throw new \RuntimeException("Application update failed"); }' "$APP_ID" || die "Targeted application update failed."
fi
if docker exec "$WEB_CONTAINER" sh -c 'command -v apache2ctl >/dev/null 2>&1'; then
 docker exec "$WEB_CONTAINER" apache2ctl -k graceful || die "Apache reload failed."
elif docker exec "$WEB_CONTAINER" sh -c 'test -r /proc/1/comm && grep -qi php-fpm /proc/1/comm'; then
 docker exec "$WEB_CONTAINER" kill -USR2 1 || die "PHP-FPM reload failed."
fi
CONFIG_VERSION="$(docker exec -u www-data "$WEB_CONTAINER" php /var/www/html/occ config:app:get "$APP_ID" installed_version)"
[ "$CONFIG_VERSION" = "$SOURCE_VERSION" ] || die "Installed version does not match source."

DEPLOYED_VERSION="$(docker exec "$WEB_CONTAINER" sh -c "grep -o '<version>[^<]*</version>' '$TARGET_DIR/appinfo/info.xml' | sed 's#<version>##;s#</version>##'" 2>/dev/null || true)"
[ "$DEPLOYED_VERSION" = "$SOURCE_VERSION" ] || die "Version verification failed. Source: $SOURCE_VERSION, deployed: $DEPLOYED_VERSION"
for item in $RUNTIME_ITEMS; do docker exec "$WEB_CONTAINER" sh -c "test -e '$TARGET_DIR/$item'" || die "Deployment verification failed. Missing: $TARGET_DIR/$item"; done
say "$APP_NAME version: $DEPLOYED_VERSION"
say "Filesystem/installed-version verification: OK; browser startup requires runtime qualification."
# Sběrač spustit až po ověření kódu a tabulek; historické řádky se nekopírují.
restore_supervisor_setting || die "Could not restore LINEA collector supervisor setting; collector was not restarted."
COLLECTOR_CONTAINER="$WEB_CONTAINER"
[ -n "$CRON_CONTAINER" ] && COLLECTOR_CONTAINER="$CRON_CONTAINER"
say "Starting LINEA fast history collector in: $COLLECTOR_CONTAINER"
docker exec "$COLLECTOR_CONTAINER" sh -c 'touch /tmp/hc_gridsight-collector.log && chown www-data:www-data /tmp/hc_gridsight-collector.log && chmod 600 /tmp/hc_gridsight-collector.log && : > /tmp/hc_gridsight-collector.log' >/dev/null || die "Cannot prepare a writable collector log in $COLLECTOR_CONTAINER."
COLLECTOR_OK=0
# Dotaz na veřejný stav služby respektuje nastavení aplikace bez znalosti
# předchozích technických ID v instalačním skriptu.
HISTORY_ENABLED="$(docker exec -u www-data "$WEB_CONTAINER" php -r 'require "/var/www/html/lib/base.php"; echo \OC::$server->get(\OCA\HcGridSight\Service\HistoryService::class)->isEnabled() ? "1" : "0";')"
if [ "$HISTORY_ENABLED" = 0 ] || [ "$SUPERVISOR_RESTORE_VALUE" = 1 ]; then
 say "Fast history collector: disabled or previously paused in application settings"
else
 # Starý sběrač může mít ještě platný zámek; počkat na vypršení
 # a nerušit zámek, který může patřit živému procesu.
 for attempt in 1 2 3 4 5 6; do
  if [ -n "$(collector_pids "$COLLECTOR_CONTAINER")$(collector_pids "$WEB_CONTAINER")" ]; then COLLECTOR_OK=1; break; fi
  docker exec -d -u www-data "$COLLECTOR_CONTAINER" sh -c "php '$TARGET_DIR/bin/collector-daemon.php' >>/tmp/hc_gridsight-collector.log 2>&1" >/dev/null 2>&1 || true
  sleep 3
  if [ -n "$(collector_pids "$COLLECTOR_CONTAINER")$(collector_pids "$WEB_CONTAINER")" ]; then COLLECTOR_OK=1; break; fi
  [ "$attempt" -eq 6 ] || sleep 2
 done
fi
if [ "$COLLECTOR_OK" -eq 1 ]; then
 say "Fast history collector: running"
elif [ "$HISTORY_ENABLED" != 0 ] && [ "$SUPERVISOR_RESTORE_VALUE" != 1 ]; then
 die "Fast history collector did not start. Previous application code is restored; check /tmp/hc_gridsight-collector.log in the Nextcloud container."
fi
say "$APP_NAME deployment finished successfully."
