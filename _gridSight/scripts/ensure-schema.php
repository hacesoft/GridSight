<?php
declare(strict_types=1);

// Re-run on EVERY install, including an interrupted install at the same version.
// Only append absent application tables, columns and indexes. Never drop data.
require '/var/www/html/lib/base.php';
$db = \OC::$server->get(\OCP\IDBConnection::class);
$prefix = (string)\OC::$server->get(\OCP\IConfig::class)->getSystemValue('dbtableprefix', 'oc_');
if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) {
    throw new \RuntimeException('Invalid Nextcloud table prefix');
}
$schema = $db->createSchema();
$changed = false;
$addColumn = static function ($table, string $name, string $type, array $options = []) use (&$changed): void {
    if (!$table->hasColumn($name)) {
        $table->addColumn($name, $type, $options);
        $changed = true;
    }
};
$addIndex = static function ($table, array $columns, string $name, bool $unique = false) use (&$changed): void {
    if (!$table->hasIndex($name)) {
        if ($unique) $table->addUniqueIndex($columns, $name);
        else $table->addIndex($columns, $name);
        $changed = true;
    }
};
$setPrimaryKey = static function ($table, array $columns) use (&$changed): void {
    if (!$table->hasPrimaryKey()) {
        $table->setPrimaryKey($columns);
        $changed = true;
    }
};

// hc_gridsight_hist
if (!$schema->hasTable($prefix . 'hc_gridsight_hist')) $changed = true;
{
$table = $schema->hasTable($prefix . 'hc_gridsight_hist') ? $schema->getTable($prefix . 'hc_gridsight_hist') : $schema->createTable($prefix . 'hc_gridsight_hist');
            $addColumn($table, 'id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $addColumn($table, 'resolution', 'integer', ['notnull' => true, 'unsigned' => true, 'default' => 60]);
            $addColumn($table, 'ts', 'bigint', ['notnull' => true]);
            $addColumn($table, 'source_ts', 'bigint', ['notnull' => false]);
            $addColumn($table, 'sample_count', 'integer', ['notnull' => true, 'unsigned' => true, 'default' => 1]);
            $addColumn($table, 'coverage_sec', 'integer', ['notnull' => true, 'unsigned' => true, 'default' => 0]);

            foreach (['pv_w','house_w','grid_w','batt_w','grid_l1_w','grid_l2_w','grid_l3_w','house_l1_w','house_l2_w','house_l3_w','ess_grid_w'] as $name) {
                $addColumn($table, $name, 'integer', ['notnull' => false]);
            }
            foreach (['soc_x100','batt_temp_x100','rack_temp_x100'] as $name) {
                $addColumn($table, $name, 'integer', ['notnull' => false]);
            }
            foreach (['forecast_pv_wh','forecast_cons_wh','vrm_pv_wh','vrm_cons_wh','vrm_import_wh','vrm_export_wh','batt_counter_charge_wh','batt_counter_discharge_wh'] as $name) {
                $addColumn($table, $name, 'bigint', ['notnull' => false]);
            }
            foreach (['pv_mwh','house_mwh','grid_import_mwh','grid_export_mwh','batt_charge_mwh','batt_discharge_mwh'] as $name) {
                $addColumn($table, $name, 'bigint', ['notnull' => true, 'default' => 0]);
            }

            $setPrimaryKey($table, ['id']);
            $addIndex($table, ['resolution', 'ts'], 'hc_lm_hist_bucket_uq', true);
            $addIndex($table, ['ts'], 'hc_lm_hist_ts_idx');
}

// hc_gridsight_events
if (!$schema->hasTable($prefix . 'hc_gridsight_events')) $changed = true;
{
$table = $schema->hasTable($prefix . 'hc_gridsight_events') ? $schema->getTable($prefix . 'hc_gridsight_events') : $schema->createTable($prefix . 'hc_gridsight_events');
            $addColumn($table, 'id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $addColumn($table, 'ts', 'bigint', ['notnull' => true]);
            $addColumn($table, 'type', 'string', ['notnull' => true, 'length' => 80]);
            $addColumn($table, 'severity', 'string', ['notnull' => true, 'length' => 16, 'default' => 'info']);
            $addColumn($table, 'title', 'string', ['notnull' => true, 'length' => 255]);
            $addColumn($table, 'detail', 'text', ['notnull' => false]);
            $addColumn($table, 'old_value', 'string', ['notnull' => false, 'length' => 255]);
            $addColumn($table, 'new_value', 'string', ['notnull' => false, 'length' => 255]);
            $setPrimaryKey($table, ['id']);
            $addIndex($table, ['ts'], 'hc_lm_events_ts_idx');
            $addIndex($table, ['type', 'ts'], 'hc_lm_events_type_idx');
}

// hc_gridsight_snapshots
if (!$schema->hasTable($prefix . 'hc_gridsight_snapshots')) $changed = true;
{
$table = $schema->hasTable($prefix . 'hc_gridsight_snapshots') ? $schema->getTable($prefix . 'hc_gridsight_snapshots') : $schema->createTable($prefix . 'hc_gridsight_snapshots');
            $addColumn($table, 'id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $addColumn($table, 'ts', 'bigint', ['notnull' => true]);
            $addColumn($table, 'api_version', 'string', ['notnull' => false, 'length' => 32]);
            $addColumn($table, 'payload', 'text', ['notnull' => true]);
            $setPrimaryKey($table, ['id']);
            $addIndex($table, ['ts'], 'hc_lm_snap_ts_uq', true);
}

// hc_gridsight_runtime
if (!$schema->hasTable($prefix . 'hc_gridsight_runtime')) $changed = true;
{
$table = $schema->hasTable($prefix . 'hc_gridsight_runtime') ? $schema->getTable($prefix . 'hc_gridsight_runtime') : $schema->createTable($prefix . 'hc_gridsight_runtime');
            $addColumn($table, 'id', 'integer', ['notnull'=>true, 'unsigned'=>true]);
            $addColumn($table, 'collector_owner', 'string', ['notnull'=>true, 'length'=>96, 'default'=>'']);
            $addColumn($table, 'lease_until', 'bigint', ['notnull'=>true, 'default'=>0]);
            $addColumn($table, 'heartbeat_ts', 'bigint', ['notnull'=>true, 'default'=>0]);
            $addColumn($table, 'started_ts', 'bigint', ['notnull'=>true, 'default'=>0]);
            $addColumn($table, 'last_poll_ts', 'bigint', ['notnull'=>true, 'default'=>0]);
            $addColumn($table, 'last_flush_ts', 'bigint', ['notnull'=>true, 'default'=>0]);
            $addColumn($table, 'poll_ok', 'bigint', ['notnull'=>true, 'default'=>0]);
            $addColumn($table, 'poll_errors', 'bigint', ['notnull'=>true, 'default'=>0]);
            $addColumn($table, 'buffer_count', 'integer', ['notnull'=>true, 'unsigned'=>true, 'default'=>0]);
            $addColumn($table, 'current_bucket', 'bigint', ['notnull'=>true, 'default'=>0]);
            $addColumn($table, 'last_error', 'text', ['notnull'=>false]);
            $setPrimaryKey($table, ['id']);
}

// Columns appended after the first history schema; preserve collected samples.
$history = $schema->getTable($prefix . 'hc_gridsight_hist');
foreach (['pv_min_w','pv_max_w','house_min_w','house_max_w','grid_min_w','grid_max_w','batt_min_w','batt_max_w','soc_min_x100','soc_max_x100'] as $name) {
    $addColumn($history, $name, 'integer', ['notnull' => false]);
}
// NULL on an older bucket means: calculate using its stored five-minute SPOT price.
// New buckets integrate price per source sample, including negative prices.
foreach (['sale_microczk', 'unpriced_export_mwh'] as $name) {
    $addColumn($history, $name, 'bigint', ['notnull' => false]);
}
// Independent inverter temperatures. Existing history rows remain NULL.
foreach (['inv1_temp_x100', 'inv2_temp_x100', 'inv3_temp_x100'] as $name) {
    $addColumn($history, $name, 'integer', ['notnull' => false]);
}

// One durable calendar-day record. Existing history is never renamed or copied.
if (!$schema->hasTable($prefix . 'hc_gridsight_daily')) $changed = true;
$daily = $schema->hasTable($prefix . 'hc_gridsight_daily')
    ? $schema->getTable($prefix . 'hc_gridsight_daily') : $schema->createTable($prefix . 'hc_gridsight_daily');
$addColumn($daily, 'day', 'string', ['notnull' => true, 'length' => 10]);
$addColumn($daily, 'export_mwh', 'bigint', ['notnull' => true, 'default' => 0]);
$addColumn($daily, 'import_mwh', 'bigint', ['notnull' => true, 'default' => 0]);
$addColumn($daily, 'sale_microczk', 'bigint', ['notnull' => true, 'default' => 0]);
$addColumn($daily, 'unpriced_export_mwh', 'bigint', ['notnull' => true, 'default' => 0]);
$addColumn($daily, 'coverage_sec', 'integer', ['notnull' => true, 'default' => 0]);
$addColumn($daily, 'bucket_count', 'integer', ['notnull' => true, 'default' => 0]);
$addColumn($daily, 'updated_at', 'bigint', ['notnull' => true, 'default' => 0]);
$setPrimaryKey($daily, ['day']);

// One price per UTC market hour. Keep old spot_x10000 values in existing
// history rows, but do not write that column for new five-minute rows.
if (!$schema->hasTable($prefix . 'hc_gridsight_spot_hour')) $changed = true;
$spotHour = $schema->hasTable($prefix . 'hc_gridsight_spot_hour')
    ? $schema->getTable($prefix . 'hc_gridsight_spot_hour') : $schema->createTable($prefix . 'hc_gridsight_spot_hour');
$addColumn($spotHour, 'hour_ts', 'bigint', ['notnull' => true]);
$addColumn($spotHour, 'price_x10000', 'integer', ['notnull' => true]);
$addColumn($spotHour, 'recorded_at', 'bigint', ['notnull' => true]);
$setPrimaryKey($spotHour, ['hour_ts']);

$required = [];
foreach (['hc_gridsight_hist', 'hc_gridsight_events', 'hc_gridsight_snapshots', 'hc_gridsight_runtime', 'hc_gridsight_daily', 'hc_gridsight_spot_hour'] as $logical) {
    $table = $schema->getTable($prefix . $logical);
    $required[$logical] = ['columns' => array_keys($table->getColumns()), 'indexes' => array_diff(array_keys($table->getIndexes()), ['primary'])];
}

if ($changed) {
    $db->migrateToSchema($schema);
}
// Verify from a fresh snapshot; metadata in $schema alone is insufficient.
$verified = $db->createSchema();
foreach ($required as $logical => $parts) {
    $physical = $prefix . $logical;
    if (!$verified->hasTable($physical)) throw new \RuntimeException('Missing table: ' . $physical);
    $table = $verified->getTable($physical);
    foreach ($parts['columns'] as $column) {
        if (!$table->hasColumn($column)) throw new \RuntimeException('Missing column: ' . $physical . '.' . $column);
    }
    foreach ($parts['indexes'] as $index) {
        if (!$table->hasIndex($index)) throw new \RuntimeException('Missing index: ' . $physical . '.' . $index);
    }
}
echo "Database schema: OK (" . count($required) . " tables)\n";
