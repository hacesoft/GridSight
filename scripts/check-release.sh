#!/bin/sh
set -eu
ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
SRC="$ROOT/src"
fail(){ echo "FAIL: $*" >&2; exit 1; }
[ -f "$SRC/appinfo/info.xml" ] || fail 'info.xml missing'
for readme in README.md README_CZ.md; do
    [ -f "$ROOT/$readme" ] || fail "missing $readme"
    grep -Fq 'https://github.com/user-attachments/assets/6cae686b-1849-446d-9b7d-cb213ddca6f2' "$ROOT/$readme" || fail "missing dashboard image in $readme"
done
grep -q '<id>hc_gridsight</id>' "$SRC/appinfo/info.xml" || fail 'technical ID mismatch'
grep -q '<version>0.9.1</version>' "$SRC/appinfo/info.xml" || fail 'version mismatch'
grep -q '<namespace>HcGridSight</namespace>' "$SRC/appinfo/info.xml" || fail 'PHP namespace mismatch'
grep -q '<nextcloud min-version="35" max-version="35"/>' "$SRC/appinfo/info.xml" || fail 'NC35 target mismatch'
grep -q '<route>hc_gridsight.page.index</route>' "$SRC/appinfo/info.xml" || fail 'navigation route mismatch'
grep -q 'OCA\\HcGridSight\\BackgroundJob\\CollectorWatchdogJob' "$SRC/appinfo/info.xml" || fail 'watchdog registration mismatch'
for item in appinfo css img js l10n lib templates bin; do [ -e "$SRC/$item" ] || fail "missing src/$item"; done
for item in js/app-0.9.1.js js/dashboard-0.9.1.js css/style-0.9.1.css; do [ -f "$SRC/$item" ] || fail "missing $item"; done
for script in install.sh uninstall.sh scripts/collector.sh scripts/custom-apps-safety.sh; do sh -n "$ROOT/$script" || fail "invalid shell syntax: $script"; done
python3 - "$ROOT" <<'PY'
import pathlib,re,sys,xml.etree.ElementTree as ET
root=pathlib.Path(sys.argv[1]); src=root/'src'
info=ET.parse(src/'appinfo/info.xml').getroot()
assert info.findtext('id')=='hc_gridsight'
assert info.findtext('version')=='0.9.1'
app=(src/'lib/AppInfo/Application.php').read_text()
assert "APP_ID = 'hc_gridsight'" in app
assert "VERSION = '0.9.1'" in app
for p in (src/'lib').rglob('*.php'):
    s=p.read_text()
    assert 'namespace OCA\\HcGridSight\\' in s,(p,'namespace')
    assert 'hc_lineamon_' not in s,(p,'legacy database reference')
for p in (src/'l10n').glob('*.js'):
    assert '"hc_gridsight"' in p.read_text(),p
for p in (src/'js').glob('*.js'):
    assert "const APP='hc_gridsight'" in p.read_text(),p
layout=(src/'templates/main.php').read_text()
order=["Util::addStyle('hc_shared_app_core', 'workspace')",
       "Util::addStyle(Application::APP_ID, 'style-0.9.1')",
       "Util::addScript('hc_shared_app_core', 'hc_shared_app_core')",
       "Util::addScript(Application::APP_ID, 'app-0.9.1')"]
indices=[layout.index(s) for s in order]
assert indices==sorted(indices),'Core/app asset order'
assert 'hc-shared-app-core-layout__content' in layout
js=(src/'js/app-0.9.1.js').read_text()
for api in ['CORE.layout.observe','CORE.notifications.',
            'CORE.toolbar.create','CORE.forms.create','CORE.settings.create',
            'CORE.logger','window.HcSharedAppCore','assertCompatible']:
    assert api in js,api
assert 'lineamonitor' not in js
assert 'estimatedSaleValue' not in js, 'do not price all daily export at the current SPOT'
assert "api('/api/history/daily')" in js
collector=(src/'lib/Service/FastCollectorAccumulator.php').read_text()
assert "'sale_microczk'" in collector
assert "'spot_hour_x10000'" in collector
assert "'spot_x10000' =>" not in collector, 'do not duplicate the hourly price in new five-minute rows'
assert 'pvSurplusW' not in collector, 'do not persist the internal LINEA diagnostic'
schema=(root/'scripts/ensure-schema.php').read_text()
assert "'hc_gridsight_daily'" in schema and "'hc_gridsight_spot_hour'" in schema
assert "['soc_x100','batt_temp_x100','rack_temp_x100']" in schema, 'history temperatures must be optional additions'
for name in ('inv1_temp_x100','inv2_temp_x100','inv3_temp_x100'):
    assert name in schema, ('nullable inverter column missing',name)
history=(src/'lib/Service/HistoryService.php').read_text()
assert "'pvSurplusW'=>" not in history and "'pv_surplus_w'," not in history
assert "'spot_x10000','forecast" not in history
assert "unset($status['spot']" in history
assert "'events' => $this->getEvents(0, time(), 100)" in history, 'event log must not be limited by chart range'
assert "'batteryTempC'" in history and "'rackTempC'" in history
for name in ('inverter1TempC','inverter2TempC','inverter3TempC'):
    assert "'"+name+"'" in history, ('inverter chart value missing',name)
assert "'batt_temp_x100'" in (src/'lib/Service/FastCollectorAccumulator.php').read_text()
assert 'TemperatureReadings::fromStatus($status)' in collector
assert 'TemperatureReadings::fromStatus($status)' in (src/'lib/Controller/LineaApiController.php').read_text()
assert 'lm-batt-rack-temp' in layout and "setText('lm-batt-rack-temp',value(temps.rackTempC" in js
assert "unset($status['ess']['decision']['pvSurplusW']" in (src/'lib/Controller/LineaApiController.php').read_text()
assert 'lm-pv-surplus' not in layout
assert 'data-tab="collector"' in layout and 'data-tab="events"' in layout and 'data-tab="settings"' in layout
assert 'data-series="export"' in layout and 'data-series="rackTemp"' in layout
assert layout.index('data-tab="history"') < layout.index('data-tab="ess"'), 'history must precede ESS'
assert 'lm-history-refresh' not in layout, 'only the Core toolbar may provide manual refresh'
for number in (1,2,3):
    assert 'data-series="inverter'+str(number)+'Temp"' in layout
assert "<span>${esc(t(APP,'Load'))}</span><b>${load}</b>" in js, 'basic UPS must show load'
assert 'historyAllowed' in js and 'historyChartStatus=latestStatus' in js, 'series switches must preserve the domain'
assert 'EXPECTED_API_SCHEMA=1' in js and 'oHealth.api||{}' in js, 'LINEA API compatibility and health envelope mismatch'
assert 'updateTemperatureLegend(shown)' in js and 'btn.hidden=!historyAllowed[series]' in js, 'temperature legend must follow available history data'
settings=(src/'lib/Controller/SettingsController.php').read_text()
assert 'setUserValue($userId, Application::APP_ID' in settings, 'per-user chart settings missing'
assert "'history#daily'" in (src/'appinfo/routes.php').read_text()
installer=(root/'install.sh').read_text()
assert 'lineamonitor' not in installer, 'one-time app rename must stay outside the normal installer'
assert 'hc_lineamon_' not in installer, 'one-time database rename must stay outside the normal installer'
for p in root.iterdir():
    if p.is_file(): assert p.name in {'LICENSE','README.md','README_CZ.md','install.sh','uninstall.sh','build-release.sh'},p
assert len(list((src/'lib/Migration').glob('Version*.php')))==1, 'one baseline migration required'
assert (src/'lib/Migration/Version009000Date20261006090000.php').is_file()
print('PASS: App ID, schema references, assets, Core integration, baseline migration and project root')
PY
