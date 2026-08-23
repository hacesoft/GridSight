<?php
declare(strict_types=1);

/**
 * GridSight · DB.php
 * ══════════════════════════════════════════════════════════
 * Veškerá práce s SQLite databází přes PDO.
 * Databáze se vytvoří automaticky při prvním startu.
 * WAL mode = Python Modbus píše, PHP čte současně bez lockování.
 */
class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) return self::$pdo;

        $dir  = rtrim(getenv('DATA_DIR') ?: '/data', '/');
        @mkdir($dir, 0777, true);
        $path = $dir . '/gridsight.db';

        self::$pdo = new PDO("sqlite:{$path}");
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE,          PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$pdo->exec('PRAGMA journal_mode=WAL');
        self::$pdo->exec('PRAGMA synchronous=NORMAL');
        self::createSchema();
        return self::$pdo;
    }

    // ──────────────────────────────────────────────────────
    // Schema
    // ──────────────────────────────────────────────────────
    private static function createSchema(): void
    {
        self::$pdo->exec("
        CREATE TABLE IF NOT EXISTS grid_data (
            ts    TEXT PRIMARY KEY,
            DCC0  REAL DEFAULT 0,   -- odběr ze sítě (kW)
            DCC1  REAL DEFAULT 0,
            DKC0  REAL DEFAULT 0,   -- jalová spotřeba
            DKC1  REAL DEFAULT 0,
            DLC0  REAL DEFAULT 0,
            DLC1  REAL DEFAULT 0,
            DMC0  REAL DEFAULT 0,
            DMC1  REAL DEFAULT 0,
            DNC0  REAL DEFAULT 0,   -- jalová dodávka
            DNC1  REAL DEFAULT 0,
            DSC0  REAL DEFAULT 0,   -- dodávka do sítě / FVE přebytek (kW)
            DSC1  REAL DEFAULT 0,
            source TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_gts ON grid_data(ts);

        CREATE TABLE IF NOT EXISTS mppt_data (
            ts           TEXT    NOT NULL,
            tracker_id   INTEGER NOT NULL,
            pv_power     REAL,        -- W  aktuální výkon
            pv_voltage   REAL,        -- V
            pv_current   REAL,        -- A
            yield_today  REAL,        -- kWh dnes
            yield_total  REAL,        -- kWh celkem od instalace
            batt_voltage REAL,        -- V
            batt_current REAL,        -- A
            state        INTEGER,     -- 0=off 3=bulk 4=absorb 5=float
            PRIMARY KEY (ts, tracker_id)
        );
        CREATE INDEX IF NOT EXISTS idx_mts ON mppt_data(ts);

        CREATE TABLE IF NOT EXISTS system_data (
            ts          TEXT PRIMARY KEY,
            grid_power  REAL,   -- W  kladné=import záporné=export
            consumption REAL,   -- W  celková AC spotřeba
            pv_total    REAL,   -- W  celkový výkon FVE (oba trackery)
            batt_soc    REAL,   -- %  stav baterie
            batt_power  REAL    -- W  kladné=nabíjení záporné=vybíjení
        );
        CREATE INDEX IF NOT EXISTS idx_sts ON system_data(ts);

        CREATE TABLE IF NOT EXISTS imported_files (
            filename    TEXT PRIMARY KEY,
            imported_at TEXT,
            records     INTEGER,
            period_from TEXT,
            period_to   TEXT
        );

        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT
        );

        INSERT OR IGNORE INTO settings VALUES
            ('ean',                  ''),
            ('cerbo_host',           ''),
            ('cerbo_port',           '502'),
            ('modbus_unit_t0',       '100'),
            ('modbus_unit_t1',       '101'),
            ('modbus_unit_system',   '100'),
            ('modbus_interval',      '300'),
            ('tracker_0_name',       'Tracker 1 (JV)'),
            ('tracker_1_name',       'Tracker 2 (JZ)'),
            ('tariff_import',        '5.50'),
            ('tariff_export',        '2.30'),
            ('tariff_own',           '5.50'),
            ('watch_folder',         ''),
            ('chart_main',           'bar'),
            ('chart_hourly',         'line'),
            ('chart_monthly',        'bar');
        ");
    }

    // ──────────────────────────────────────────────────────
    // Settings
    // ──────────────────────────────────────────────────────
    public static function get(string $key, string $default = ''): string
    {
        $r = self::pdo()->prepare('SELECT value FROM settings WHERE key=?');
        $r->execute([$key]);
        $v = $r->fetchColumn();
        return $v !== false ? $v : $default;
    }

    public static function set(string $key, string $value): void
    {
        self::pdo()->prepare('INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)')
            ->execute([$key, $value]);
    }

    public static function allSettings(): array
    {
        $rows = self::pdo()->query('SELECT key,value FROM settings')->fetchAll();
        return array_column($rows, 'value', 'key');
    }

    // ──────────────────────────────────────────────────────
    // Soubory
    // ──────────────────────────────────────────────────────
    public static function listFiles(): array
    {
        return self::pdo()
            ->query('SELECT * FROM imported_files ORDER BY period_from DESC')
            ->fetchAll();
    }

    public static function registerFile(string $fn, int $rec, string $from, string $to): void
    {
        self::pdo()
            ->prepare('INSERT OR REPLACE INTO imported_files VALUES(?,?,?,?,?)')
            ->execute([$fn, date('c'), $rec, $from, $to]);
    }

    public static function deleteFile(string $fn): void
    {
        self::pdo()->prepare('DELETE FROM grid_data      WHERE source=?')->execute([$fn]);
        self::pdo()->prepare('DELETE FROM imported_files WHERE filename=?')->execute([$fn]);
    }

    // ──────────────────────────────────────────────────────
    // Upsert grid dat (z XLSX)
    // ──────────────────────────────────────────────────────
    public static function upsertGrid(array $rows, string $source): int
    {
        $sql  = 'INSERT OR REPLACE INTO grid_data
                 (ts,DCC0,DCC1,DKC0,DKC1,DLC0,DLC1,DMC0,DMC1,DNC0,DNC1,DSC0,DSC1,source)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $stmt = self::pdo()->prepare($sql);
        self::pdo()->beginTransaction();
        foreach ($rows as $r) $stmt->execute(array_merge((array)$r, [$source]));
        self::pdo()->commit();
        return count($rows);
    }

    // ──────────────────────────────────────────────────────
    // Queries – helper
    // ──────────────────────────────────────────────────────
    private static function trunc(string $group): string
    {
        return match($group) {
            '15min' => 'ts',
            'hour'  => "strftime('%Y-%m-%dT%H:00:00',ts)",
            'week'  => "strftime('%G-W%V',ts)",
            'month' => "strftime('%Y-%m',ts)",
            default => "strftime('%Y-%m-%d',ts)",    // day
        };
    }

    // ──────────────────────────────────────────────────────
    // Grid dotazy
    // ──────────────────────────────────────────────────────
    public static function queryGrid(string $from, string $to, string $grp = 'day'): array
    {
        $tr = self::trunc($grp);
        if ($grp === '15min') {
            $s = self::pdo()->prepare('SELECT ts,DCC0,DSC0 FROM grid_data WHERE ts>=? AND ts<=? ORDER BY ts');
        } else {
            $s = self::pdo()->prepare("
                SELECT {$tr} AS period,
                       ROUND(SUM(DCC0)*0.25,3) AS spotreba,
                       ROUND(SUM(DSC0)*0.25,3) AS dodavka,
                       ROUND(MAX(DCC0),3)       AS max_sp,
                       ROUND(MAX(DSC0),3)       AS max_do
                FROM grid_data WHERE ts>=? AND ts<=?
                GROUP BY 1 ORDER BY 1");
        }
        $s->execute([$from, $to]);
        return $s->fetchAll();
    }

    public static function queryHourly(string $from, string $to): array
    {
        $s = self::pdo()->prepare("
            SELECT CAST(strftime('%H',ts) AS INTEGER) AS hour,
                   ROUND(AVG(DCC0),4) AS avg_sp,
                   ROUND(AVG(DSC0),4) AS avg_do
            FROM grid_data WHERE ts>=? AND ts<=?
            GROUP BY 1 ORDER BY 1");
        $s->execute([$from, $to]);
        return $s->fetchAll();
    }

    public static function totals(string $from, string $to): array
    {
        $s = self::pdo()->prepare("
            SELECT ROUND(SUM(DCC0)*0.25,2) AS spotreba,
                   ROUND(SUM(DSC0)*0.25,2) AS dodavka,
                   ROUND(MAX(DCC0),2)       AS max_sp,
                   ROUND(MAX(DSC0),2)       AS max_do,
                   COUNT(DISTINCT strftime('%Y-%m-%d',ts)) AS days,
                   MIN(ts) AS first_ts, MAX(ts) AS last_ts
            FROM grid_data WHERE ts>=? AND ts<=?");
        $s->execute([$from, $to]);
        return $s->fetch() ?: [];
    }

    // ──────────────────────────────────────────────────────
    // MPPT / Cerbo dotazy
    // ──────────────────────────────────────────────────────
    public static function queryMppt(string $from, string $to, string $grp = 'day'): array
    {
        $tr = self::trunc($grp);
        if ($grp === '15min') {
            $sql = 'SELECT ts,tracker_id,pv_power,yield_today,batt_voltage,state
                    FROM mppt_data WHERE ts>=? AND ts<=? ORDER BY ts';
        } else {
            $sql = "SELECT {$tr} AS period, tracker_id,
                           ROUND(AVG(pv_power),1)                           AS avg_power,
                           ROUND(MAX(pv_power),1)                           AS max_power,
                           ROUND(MAX(yield_today),3)                        AS yield_today,
                           ROUND(MAX(yield_total)-MIN(yield_total),3)       AS yield_period
                    FROM mppt_data WHERE ts>=? AND ts<=?
                    GROUP BY 1,2 ORDER BY 1,2";
        }
        $s = self::pdo()->prepare($sql);
        $s->execute([$from, $to]);
        return $s->fetchAll();
    }

    public static function querySystem(string $from, string $to, string $grp = 'day'): array
    {
        $tr = self::trunc($grp);
        if ($grp === '15min') {
            $sql = 'SELECT ts,grid_power,consumption,pv_total,batt_soc,batt_power
                    FROM system_data WHERE ts>=? AND ts<=? ORDER BY ts';
        } else {
            $sql = "SELECT {$tr} AS period,
                           ROUND(AVG(grid_power),1)  AS avg_grid,
                           ROUND(AVG(consumption),1) AS avg_cons,
                           ROUND(AVG(pv_total),1)    AS avg_pv,
                           ROUND(AVG(batt_soc),1)    AS avg_soc,
                           ROUND(MAX(pv_total),1)    AS max_pv
                    FROM system_data WHERE ts>=? AND ts<=?
                    GROUP BY 1 ORDER BY 1";
        }
        $s = self::pdo()->prepare($sql);
        $s->execute([$from, $to]);
        return $s->fetchAll();
    }

    public static function mpptTotals(string $from, string $to): array
    {
        $s = self::pdo()->prepare("
            SELECT tracker_id,
                   ROUND(MAX(yield_total)-MIN(yield_total),2) AS yield_kwh,
                   ROUND(MAX(pv_power),1)                     AS max_power,
                   COUNT(*)                                    AS records
            FROM mppt_data WHERE ts>=? AND ts<=?
            GROUP BY tracker_id");
        $s->execute([$from, $to]);
        return $s->fetchAll();
    }

    public static function hasMppt(string $from, string $to): bool
    {
        $s = self::pdo()->prepare('SELECT COUNT(*) FROM mppt_data WHERE ts>=? AND ts<=?');
        $s->execute([$from, $to]);
        return (int)$s->fetchColumn() > 0;
    }

    public static function dateRange(): array
    {
        $r = self::pdo()->query('SELECT MIN(ts),MAX(ts) FROM grid_data')->fetch(PDO::FETCH_NUM);
        return [$r[0] ?? null, $r[1] ?? null];
    }

    public static function lastModbus(): ?string
    {
        return self::pdo()->query('SELECT MAX(ts) FROM mppt_data')->fetchColumn() ?: null;
    }
}
