<?php

/**
 * Hacesoft GridSight – typová legenda proměnných (maďarská notace).
 * Historie: 2026-11-21 – bezpečné sjednocení interních lokálních proměnných.
 * Historie: 2026-11-22 – ověření typů, významových komentářů a shody názvů vlastností.
 * s – textový řetězec / identifikátor nebo cesta.
 * n – číslo (celé, desetinné, čas nebo jednotky).
 * b – logická hodnota.
 * o – instance objektu nebo výjimka.
 * v – smíšená nebo zatím neurčená hodnota.
 * fn – pomocná funkce, closure či callback.
 * Výjimka: názvy parametrů PHP metod a veřejných vlastností se nemění,
 * protože jsou součástí rozhraní Nextcloud, DI a pojmenovaných argumentů.
 */

declare(strict_types=1);
namespace OCA\HcGridSight\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Initial public schema. Freeze this migration; future schema changes get new migrations. */
final class Version009000Date20261006090000 extends SimpleMigrationStep {
    /**
     * Založí výchozí tabulky a indexy GridSight při migraci databáze.
     *
     * Vstupy: `$output` (IOutput) – výstupní rozhraní databázové migrace Nextcloudu; `$schemaClosure` (\Closure) – callback vracející schéma databáze při migraci; `$options` (array) – doplňující volby migrace poskytnuté Nextcloudem.
     * Výstup: `?ISchemaWrapper` – nové/rozšířené schéma databáze, nebo null pokud nebyla změna nutná.
     *
     * @param IOutput $output výstupní rozhraní databázové migrace Nextcloudu
     * @param \Closure $schemaClosure callback vracející schéma databáze při migraci
     * @param array $options doplňující volby migrace poskytnuté Nextcloudem
     * @return ?ISchemaWrapper nové/rozšířené schéma databáze, nebo null pokud nebyla změna nutná
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $oSchema=$schemaClosure();
$bChanged = false;
$fnAddColumn = /** Přidá do tabulky chybějící sloupec požadovaného typu a nastaví příznak změny schématu. Vstupy: tabulka schématu, název a typ sloupce, volby definice. Výstup: `void` – mění schéma v předaném objektu, nevrací hodnotu. */ static function ($table, string $name, string $type, array $options = []) use (&$bChanged): void {
    if (!$table->hasColumn($name)) {
        $table->addColumn($name, $type, $options);
        $bChanged = true;
    }
};
$fnAddIndex = /** Přidá do tabulky chybějící index nad zadanými sloupci; podle volby může být unikátní. Vstupy: tabulka, názvy indexovaných sloupců, název indexu a volba unikátnosti. Výstup: `void` – mění schéma v předaném objektu, nevrací hodnotu. */ static function ($table, array $columns, string $name, bool $unique = false) use (&$bChanged): void {
    if (!$table->hasIndex($name)) {
        if ($unique) $table->addUniqueIndex($columns, $name);
        else $table->addIndex($columns, $name);
        $bChanged = true;
    }
};
$fnSetPrimaryKey = /** Nastaví primární klíč tabulky pouze tehdy, pokud dosud neexistuje. Vstupy: tabulka a seznam sloupců primárního klíče. Výstup: `void` – mění schéma v předaném objektu, nevrací hodnotu. */ static function ($table, array $columns) use (&$bChanged): void {
    if (!$table->hasPrimaryKey()) {
        $table->setPrimaryKey($columns);
        $bChanged = true;
    }
};

// hc_gridsight_hist
if (!$oSchema->hasTable('hc_gridsight_hist')) $bChanged = true;
{
$table = $oSchema->hasTable('hc_gridsight_hist') ? $oSchema->getTable('hc_gridsight_hist') : $oSchema->createTable('hc_gridsight_hist');
            $fnAddColumn($table, 'id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $fnAddColumn($table, 'resolution', 'integer', ['notnull' => true, 'unsigned' => true, 'default' => 60]);
            $fnAddColumn($table, 'ts', 'bigint', ['notnull' => true]);
            $fnAddColumn($table, 'source_ts', 'bigint', ['notnull' => false]);
            $fnAddColumn($table, 'sample_count', 'integer', ['notnull' => true, 'unsigned' => true, 'default' => 1]);
            $fnAddColumn($table, 'coverage_sec', 'integer', ['notnull' => true, 'unsigned' => true, 'default' => 0]);

            foreach (['pv_w','house_w','grid_w','batt_w','grid_l1_w','grid_l2_w','grid_l3_w','house_l1_w','house_l2_w','house_l3_w','ess_grid_w'] as $name) {
                $fnAddColumn($table, $name, 'integer', ['notnull' => false]);
            }
            foreach (['soc_x100','batt_temp_x100','rack_temp_x100'] as $name) {
                $fnAddColumn($table, $name, 'integer', ['notnull' => false]);
            }
            foreach (['forecast_pv_wh','forecast_cons_wh','vrm_pv_wh','vrm_cons_wh','vrm_import_wh','vrm_export_wh','batt_counter_charge_wh','batt_counter_discharge_wh'] as $name) {
                $fnAddColumn($table, $name, 'bigint', ['notnull' => false]);
            }
            foreach (['pv_mwh','house_mwh','grid_import_mwh','grid_export_mwh','batt_charge_mwh','batt_discharge_mwh'] as $name) {
                $fnAddColumn($table, $name, 'bigint', ['notnull' => true, 'default' => 0]);
            }

            $fnSetPrimaryKey($table, ['id']);
            $fnAddIndex($table, ['resolution', 'ts'], 'hc_lm_hist_bucket_uq', true);
            $fnAddIndex($table, ['ts'], 'hc_lm_hist_ts_idx');
}

// hc_gridsight_events
if (!$oSchema->hasTable('hc_gridsight_events')) $bChanged = true;
{
$table = $oSchema->hasTable('hc_gridsight_events') ? $oSchema->getTable('hc_gridsight_events') : $oSchema->createTable('hc_gridsight_events');
            $fnAddColumn($table, 'id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $fnAddColumn($table, 'ts', 'bigint', ['notnull' => true]);
            $fnAddColumn($table, 'type', 'string', ['notnull' => true, 'length' => 80]);
            $fnAddColumn($table, 'severity', 'string', ['notnull' => true, 'length' => 16, 'default' => 'info']);
            $fnAddColumn($table, 'title', 'string', ['notnull' => true, 'length' => 255]);
            $fnAddColumn($table, 'detail', 'text', ['notnull' => false]);
            $fnAddColumn($table, 'old_value', 'string', ['notnull' => false, 'length' => 255]);
            $fnAddColumn($table, 'new_value', 'string', ['notnull' => false, 'length' => 255]);
            $fnSetPrimaryKey($table, ['id']);
            $fnAddIndex($table, ['ts'], 'hc_lm_events_ts_idx');
            $fnAddIndex($table, ['type', 'ts'], 'hc_lm_events_type_idx');
}

// hc_gridsight_snapshots
if (!$oSchema->hasTable('hc_gridsight_snapshots')) $bChanged = true;
{
$table = $oSchema->hasTable('hc_gridsight_snapshots') ? $oSchema->getTable('hc_gridsight_snapshots') : $oSchema->createTable('hc_gridsight_snapshots');
            $fnAddColumn($table, 'id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $fnAddColumn($table, 'ts', 'bigint', ['notnull' => true]);
            $fnAddColumn($table, 'api_version', 'string', ['notnull' => false, 'length' => 32]);
            $fnAddColumn($table, 'payload', 'text', ['notnull' => true]);
            $fnSetPrimaryKey($table, ['id']);
            $fnAddIndex($table, ['ts'], 'hc_lm_snap_ts_uq', true);
}

// hc_gridsight_runtime
if (!$oSchema->hasTable('hc_gridsight_runtime')) $bChanged = true;
{
$table = $oSchema->hasTable('hc_gridsight_runtime') ? $oSchema->getTable('hc_gridsight_runtime') : $oSchema->createTable('hc_gridsight_runtime');
            $fnAddColumn($table, 'id', 'integer', ['notnull'=>true, 'unsigned'=>true]);
            $fnAddColumn($table, 'collector_owner', 'string', ['notnull'=>true, 'length'=>96, 'default'=>'']);
            $fnAddColumn($table, 'lease_until', 'bigint', ['notnull'=>true, 'default'=>0]);
            $fnAddColumn($table, 'heartbeat_ts', 'bigint', ['notnull'=>true, 'default'=>0]);
            $fnAddColumn($table, 'started_ts', 'bigint', ['notnull'=>true, 'default'=>0]);
            $fnAddColumn($table, 'last_poll_ts', 'bigint', ['notnull'=>true, 'default'=>0]);
            $fnAddColumn($table, 'last_flush_ts', 'bigint', ['notnull'=>true, 'default'=>0]);
            $fnAddColumn($table, 'poll_ok', 'bigint', ['notnull'=>true, 'default'=>0]);
            $fnAddColumn($table, 'poll_errors', 'bigint', ['notnull'=>true, 'default'=>0]);
            $fnAddColumn($table, 'buffer_count', 'integer', ['notnull'=>true, 'unsigned'=>true, 'default'=>0]);
            $fnAddColumn($table, 'current_bucket', 'bigint', ['notnull'=>true, 'default'=>0]);
            $fnAddColumn($table, 'last_error', 'text', ['notnull'=>false]);
            $fnSetPrimaryKey($table, ['id']);
}

// Columns appended after the first history schema; preserve collected samples.
$oHistory = $oSchema->getTable('hc_gridsight_hist');
foreach (['pv_min_w','pv_max_w','house_min_w','house_max_w','grid_min_w','grid_max_w','batt_min_w','batt_max_w','soc_min_x100','soc_max_x100'] as $name) {
    $fnAddColumn($oHistory, $name, 'integer', ['notnull' => false]);
}
// NULL on an older bucket means: calculate using its stored five-minute SPOT price.
// New buckets integrate price per source sample, including negative prices.
foreach (['sale_microczk', 'unpriced_export_mwh'] as $name) {
    $fnAddColumn($oHistory, $name, 'bigint', ['notnull' => false]);
}
// Independent inverter temperatures. Existing history rows remain NULL.
foreach (['inv1_temp_x100', 'inv2_temp_x100', 'inv3_temp_x100'] as $name) {
    $fnAddColumn($oHistory, $name, 'integer', ['notnull' => false]);
}
// Seconds of the observed Shelly HDO input. NULL means no observation, not OFF.
foreach (['hdo_on_sec', 'hdo_off_sec'] as $name) {
    $fnAddColumn($oHistory, $name, 'integer', ['notnull' => false]);
}

// One durable calendar-day record. Existing history is never renamed or copied.
if (!$oSchema->hasTable('hc_gridsight_daily')) $bChanged = true;
$oDaily = $oSchema->hasTable('hc_gridsight_daily')
    ? $oSchema->getTable('hc_gridsight_daily') : $oSchema->createTable('hc_gridsight_daily');
$fnAddColumn($oDaily, 'day', 'string', ['notnull' => true, 'length' => 10]);
$fnAddColumn($oDaily, 'export_mwh', 'bigint', ['notnull' => true, 'default' => 0]);
$fnAddColumn($oDaily, 'import_mwh', 'bigint', ['notnull' => true, 'default' => 0]);
$fnAddColumn($oDaily, 'sale_microczk', 'bigint', ['notnull' => true, 'default' => 0]);
$fnAddColumn($oDaily, 'unpriced_export_mwh', 'bigint', ['notnull' => true, 'default' => 0]);
$fnAddColumn($oDaily, 'coverage_sec', 'integer', ['notnull' => true, 'default' => 0]);
$fnAddColumn($oDaily, 'bucket_count', 'integer', ['notnull' => true, 'default' => 0]);
$fnAddColumn($oDaily, 'updated_at', 'bigint', ['notnull' => true, 'default' => 0]);
$fnSetPrimaryKey($oDaily, ['day']);

// One price per UTC market hour. Keep old spot_x10000 values in existing
// history rows, but do not write that column for new five-minute rows.
if (!$oSchema->hasTable('hc_gridsight_spot_hour')) $bChanged = true;
$oSpotHour = $oSchema->hasTable('hc_gridsight_spot_hour')
    ? $oSchema->getTable('hc_gridsight_spot_hour') : $oSchema->createTable('hc_gridsight_spot_hour');
$fnAddColumn($oSpotHour, 'hour_ts', 'bigint', ['notnull' => true]);
$fnAddColumn($oSpotHour, 'price_x10000', 'integer', ['notnull' => true]);
$fnAddColumn($oSpotHour, 'recorded_at', 'bigint', ['notnull' => true]);
$fnSetPrimaryKey($oSpotHour, ['hour_ts']);

// Source-of-truth 15-minute ED.G imports. Reimport replaces only the selected
// month in one transaction; collected LINEA history is never modified.
if (!$oSchema->hasTable('hc_gridsight_edg_reports')) $bChanged = true;
$oReports = $oSchema->hasTable('hc_gridsight_edg_reports')
    ? $oSchema->getTable('hc_gridsight_edg_reports') : $oSchema->createTable('hc_gridsight_edg_reports');
$fnAddColumn($oReports, 'period', 'string', ['notnull'=>true, 'length'=>7]);
$fnAddColumn($oReports, 'ean', 'string', ['notnull'=>true, 'length'=>18]);
$fnAddColumn($oReports, 'sha256', 'string', ['notnull'=>true, 'length'=>64]);
$fnAddColumn($oReports, 'row_count', 'integer', ['notnull'=>true]);
$fnAddColumn($oReports, 'import_mwh', 'bigint', ['notnull'=>true]);
$fnAddColumn($oReports, 'export_mwh', 'bigint', ['notnull'=>true]);
$fnAddColumn($oReports, 'imported_at', 'bigint', ['notnull'=>true]);
$fnSetPrimaryKey($oReports, ['period']);

if (!$oSchema->hasTable('hc_gridsight_edg_intervals')) $bChanged = true;
$oIntervals = $oSchema->hasTable('hc_gridsight_edg_intervals')
    ? $oSchema->getTable('hc_gridsight_edg_intervals') : $oSchema->createTable('hc_gridsight_edg_intervals');
$fnAddColumn($oIntervals, 'period', 'string', ['notnull'=>true, 'length'=>7]);
$fnAddColumn($oIntervals, 'ts', 'bigint', ['notnull'=>true]);
$fnAddColumn($oIntervals, 'import_mwh', 'bigint', ['notnull'=>true]);
$fnAddColumn($oIntervals, 'export_mwh', 'bigint', ['notnull'=>true]);
$fnSetPrimaryKey($oIntervals, ['period','ts']);
$fnAddIndex($oIntervals, ['ts'], 'hc_gridsight_edg_ts_idx');

        return $bChanged ? $oSchema : null;
    }
}
