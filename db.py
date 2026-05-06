import sqlite3, os
from pathlib import Path
from contextlib import contextmanager

DB_PATH = Path(os.environ.get("DATA_DIR", "./data")) / "fve.db"

SCHEMA = """
PRAGMA journal_mode=WAL;

CREATE TABLE IF NOT EXISTS grid_data (
    ts      TEXT PRIMARY KEY,
    DCC0    REAL, DCC1 REAL,
    DKC0    REAL, DKC1 REAL,
    DLC0    REAL, DLC1 REAL,
    DMC0    REAL, DMC1 REAL,
    DNC0    REAL, DNC1 REAL,
    DSC0    REAL, DSC1 REAL,
    source  TEXT
);

CREATE INDEX IF NOT EXISTS idx_ts ON grid_data(ts);
CREATE INDEX IF NOT EXISTS idx_source ON grid_data(source);

CREATE TABLE IF NOT EXISTS victron_data (
    ts          TEXT PRIMARY KEY,
    pv_power    REAL,
    batt_soc    REAL,
    batt_power  REAL,
    load_power  REAL,
    grid_power  REAL,
    source      TEXT
);

CREATE INDEX IF NOT EXISTS idx_vts ON victron_data(ts);

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
  ('chart_type_daily',   'bar'),
  ('chart_type_hourly',  'line'),
  ('chart_type_raw',     'line'),
  ('chart_type_monthly', 'bar'),
  ('watch_folder',       ''),
  ('ean',                '');
"""

@contextmanager
def get_conn():
    conn = sqlite3.connect(str(DB_PATH), check_same_thread=False)
    conn.row_factory = sqlite3.Row
    try:
        yield conn
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()

def init_db():
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    with get_conn() as conn:
        conn.executescript(SCHEMA)

def get_setting(key, default=None):
    with get_conn() as conn:
        row = conn.execute("SELECT value FROM settings WHERE key=?", (key,)).fetchone()
        return row[0] if row else default

def set_setting(key, value):
    with get_conn() as conn:
        conn.execute("INSERT OR REPLACE INTO settings VALUES (?,?)", (key, value))

def get_all_settings():
    with get_conn() as conn:
        rows = conn.execute("SELECT key,value FROM settings").fetchall()
        return {r[0]: r[1] for r in rows}

def upsert_grid_rows(rows, source):
    sql = """INSERT OR REPLACE INTO grid_data
             (ts,DCC0,DCC1,DKC0,DKC1,DLC0,DLC1,DMC0,DMC1,DNC0,DNC1,DSC0,DSC1,source)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"""
    with get_conn() as conn:
        conn.executemany(sql, rows)

def upsert_victron_rows(rows, source):
    sql = """INSERT OR REPLACE INTO victron_data
             (ts,pv_power,batt_soc,batt_power,load_power,grid_power,source)
             VALUES (?,?,?,?,?,?,?)"""
    with get_conn() as conn:
        conn.executemany(sql, rows)

def register_file(filename, records, period_from, period_to):
    from datetime import datetime
    with get_conn() as conn:
        conn.execute("""INSERT OR REPLACE INTO imported_files
                        VALUES (?,?,?,?,?)""",
                     (filename, datetime.now().isoformat(), records, period_from, period_to))

def list_files():
    with get_conn() as conn:
        rows = conn.execute("""SELECT filename,imported_at,records,period_from,period_to
                               FROM imported_files ORDER BY period_from DESC""").fetchall()
        return [dict(r) for r in rows]

def delete_file(filename):
    with get_conn() as conn:
        conn.execute("DELETE FROM grid_data WHERE source=?", (filename,))
        conn.execute("DELETE FROM imported_files WHERE filename=?", (filename,))

def query_grid(ts_from, ts_to, group="15min"):
    """group: 15min | hour | day | week | month"""
    trunc = {
        "15min": "ts",
        "hour":  "strftime('%Y-%m-%dT%H:00:00', ts)",
        "day":   "strftime('%Y-%m-%d', ts)",
        "week":  "strftime('%Y-W%W', ts)",
        "month": "strftime('%Y-%m', ts)",
    }.get(group, "ts")

    if group == "15min":
        sql = f"""SELECT ts,
                  DCC0, DSC0, DNC0, DMC0, DLC0
                  FROM grid_data
                  WHERE ts >= ? AND ts <= ?
                  ORDER BY ts"""
        with get_conn() as conn:
            rows = conn.execute(sql, (ts_from, ts_to)).fetchall()
            return [dict(r) for r in rows]
    else:
        sql = f"""SELECT {trunc} as period,
                  ROUND(SUM(DCC0)*0.25,3) as spotreba,
                  ROUND(SUM(DSC0)*0.25,3) as dodavka,
                  ROUND(MAX(DCC0),3) as max_sp,
                  ROUND(MAX(DSC0),3) as max_do,
                  ROUND(AVG(DNC0),4) as avg_jalova
                  FROM grid_data
                  WHERE ts >= ? AND ts <= ?
                  GROUP BY 1 ORDER BY 1"""
        with get_conn() as conn:
            rows = conn.execute(sql, (ts_from, ts_to)).fetchall()
            return [dict(r) for r in rows]

def query_victron(ts_from, ts_to, group="15min"):
    trunc = {
        "15min": "ts",
        "hour":  "strftime('%Y-%m-%dT%H:00:00', ts)",
        "day":   "strftime('%Y-%m-%d', ts)",
        "week":  "strftime('%Y-W%W', ts)",
        "month": "strftime('%Y-%m', ts)",
    }.get(group, "ts")

    if group == "15min":
        sql = """SELECT ts,pv_power,batt_soc,batt_power,load_power,grid_power
                 FROM victron_data WHERE ts>=? AND ts<=? ORDER BY ts"""
    else:
        sql = f"""SELECT {trunc} as period,
                  ROUND(AVG(pv_power),1) as pv_power,
                  ROUND(AVG(batt_soc),1) as batt_soc,
                  ROUND(AVG(batt_power),1) as batt_power,
                  ROUND(AVG(load_power),1) as load_power,
                  ROUND(AVG(grid_power),1) as grid_power
                  FROM victron_data WHERE ts>=? AND ts<=?
                  GROUP BY 1 ORDER BY 1"""
    with get_conn() as conn:
        rows = conn.execute(sql, (ts_from, ts_to)).fetchall()
        return [dict(r) for r in rows]

def query_hourly_profile(ts_from, ts_to):
    sql = """SELECT CAST(strftime('%H',ts) AS INTEGER) as hour,
             ROUND(AVG(DCC0),4) as avg_sp,
             ROUND(AVG(DSC0),4) as avg_do
             FROM grid_data WHERE ts>=? AND ts<=?
             GROUP BY 1 ORDER BY 1"""
    with get_conn() as conn:
        rows = conn.execute(sql, (ts_from, ts_to)).fetchall()
        return [dict(r) for r in rows]

def get_totals(ts_from, ts_to):
    sql = """SELECT
             ROUND(SUM(DCC0)*0.25,2) as spotreba,
             ROUND(SUM(DSC0)*0.25,2) as dodavka,
             ROUND(MAX(DCC0),2) as max_sp,
             ROUND(MAX(DSC0),2) as max_do,
             COUNT(DISTINCT strftime('%Y-%m-%d',ts)) as days,
             MIN(ts) as first_ts, MAX(ts) as last_ts
             FROM grid_data WHERE ts>=? AND ts<=?"""
    with get_conn() as conn:
        row = conn.execute(sql, (ts_from, ts_to)).fetchone()
        return dict(row) if row else {}

def get_date_range():
    with get_conn() as conn:
        row = conn.execute("SELECT MIN(ts), MAX(ts) FROM grid_data").fetchone()
        return row[0], row[1]

def has_victron_data(ts_from, ts_to):
    with get_conn() as conn:
        n = conn.execute("SELECT COUNT(*) FROM victron_data WHERE ts>=? AND ts<=?",
                         (ts_from, ts_to)).fetchone()[0]
        return n > 0
