#!/usr/bin/env python3
"""
GridSight · modbus_poll.py
══════════════════════════════════════════════════════════════════
Čte data z Cerbo GX přes Modbus TCP a ukládá do sdílené SQLite DB.
Běží jako samostatný Docker kontejner, sdílí volume /data s PHP.

Nastavení se bere ze SQLite settings tabulky (nastavuje se přes web UI).

Victron Modbus registry (SmartSolar MPPT – Solar Charger):
  771  PV power       [W]        Unit: tracker
  772  PV voltage     [0.01 V]   Unit: tracker
  773  PV current     [0.1  A]   Unit: tracker
  784  Yield today    [0.01 kWh] Unit: tracker
  790  Yield total    [0.01 kWh] Unit: tracker
  775  Charger state  [enum]     Unit: tracker
       0=Off, 2=Fault, 3=Bulk, 4=Absorption, 5=Float, 7=Equalize

Victron Modbus registry (System – com.victronenergy.system):
  820  Grid L1 power  [W]    Unit: system (kladné=import, záporné=export)
  821  Grid L2 power  [W]
  822  Grid L3 power  [W]
  817  AC consumption L1 [W]
  818  AC consumption L2 [W]
  819  AC consumption L3 [W]
  850  PV power total [W]
  843  Battery SOC    [1%]
  842  Battery power  [W]   (kladné=nabíjení, záporné=vybíjení)

Unit ID zjistíš:
  Cerbo display → Settings → Services → Modbus TCP → Available services
"""

import sqlite3
import time
import logging
import os
import signal
import sys
from datetime import datetime, timezone
from pathlib import Path

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)
log = logging.getLogger('gridsight-modbus')

DATA_DIR = Path(os.environ.get('DATA_DIR', '/data'))
DB_PATH  = DATA_DIR / 'gridsight.db'

# ── Graceful shutdown ──────────────────────────────────────────────
running = True
def _stop(sig, frame):
    global running
    log.info('Stopping...')
    running = False

signal.signal(signal.SIGTERM, _stop)
signal.signal(signal.SIGINT,  _stop)


# ── SQLite ─────────────────────────────────────────────────────────
def get_setting(key: str, default: str = '') -> str:
    try:
        with sqlite3.connect(str(DB_PATH)) as conn:
            row = conn.execute('SELECT value FROM settings WHERE key=?', (key,)).fetchone()
            return row[0] if row else default
    except Exception:
        return default


def upsert_mppt(ts: str, tracker_id: int, data: dict):
    sql = """INSERT OR REPLACE INTO mppt_data
             (ts, tracker_id, pv_power, pv_voltage, pv_current,
              yield_today, yield_total, state)
             VALUES (?,?,?,?,?,?,?,?)"""
    with sqlite3.connect(str(DB_PATH)) as conn:
        conn.execute('PRAGMA journal_mode=WAL')
        conn.execute(sql, (
            ts, tracker_id,
            data.get('pv_power'),
            data.get('pv_voltage'),
            data.get('pv_current'),
            data.get('yield_today'),
            data.get('yield_total'),
            data.get('state'),
        ))


def upsert_system(ts: str, data: dict):
    sql = """INSERT OR REPLACE INTO system_data
             (ts, grid_power, consumption, pv_total, batt_soc, batt_power)
             VALUES (?,?,?,?,?,?)"""
    with sqlite3.connect(str(DB_PATH)) as conn:
        conn.execute('PRAGMA journal_mode=WAL')
        conn.execute(sql, (
            ts,
            data.get('grid_power'),
            data.get('consumption'),
            data.get('pv_total'),
            data.get('batt_soc'),
            data.get('batt_power'),
        ))


# ── Modbus čtení ───────────────────────────────────────────────────
def read_reg(client, register: int, unit: int) -> int | None:
    """Přečte jeden holding register. Vrátí None při chybě nebo hodnotě 0xFFFF."""
    try:
        res = client.read_holding_registers(register, count=1, slave=unit)
        if res.isError():
            return None
        val = res.registers[0]
        return None if val == 0xFFFF else val
    except Exception as e:
        log.debug(f'  reg {register} unit {unit}: {e}')
        return None


def signed16(val: int | None) -> int | None:
    """Převede uint16 na signed int16 (pro výkony sítě které mohou být záporné)."""
    if val is None:
        return None
    return val - 65536 if val > 32767 else val


def collect(host: str, port: int, unit_t0: int, unit_t1: int, unit_sys: int):
    from pymodbus.client import ModbusTcpClient

    client = ModbusTcpClient(host, port=port, timeout=5)
    if not client.connect():
        raise ConnectionError(f'Nelze se připojit na {host}:{port}')

    ts = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S')

    try:
        # ── MPPT Tracker 0 ──────────────────────────────────────
        _read_mppt(client, ts, tracker_id=0, unit=unit_t0)

        # ── MPPT Tracker 1 ──────────────────────────────────────
        _read_mppt(client, ts, tracker_id=1, unit=unit_t1)

        # ── System overview ─────────────────────────────────────
        _read_system(client, ts, unit=unit_sys)

    finally:
        client.close()

    log.info(f'OK → {ts}  (unit_t0={unit_t0} unit_t1={unit_t1} sys={unit_sys})')


def _read_mppt(client, ts: str, tracker_id: int, unit: int):
    r = lambda reg: read_reg(client, reg, unit)

    raw_pow  = r(771)
    raw_volt = r(772)
    raw_cur  = r(773)
    raw_tod  = r(784)
    raw_tot  = r(790)
    raw_stat = r(775)

    data = {
        'pv_power':    raw_pow,
        'pv_voltage':  round(raw_volt * 0.01, 2) if raw_volt is not None else None,
        'pv_current':  round(raw_cur  * 0.1,  2) if raw_cur  is not None else None,
        'yield_today': round(raw_tod  * 0.01, 3) if raw_tod  is not None else None,
        'yield_total': round(raw_tot  * 0.01, 2) if raw_tot  is not None else None,
        'state':       raw_stat,
    }

    if any(v is not None for v in data.values()):
        upsert_mppt(ts, tracker_id, data)
        log.info(f'  MPPT[{tracker_id}] unit={unit}: '
                 f'{data["pv_power"]}W  dnes={data["yield_today"]}kWh  '
                 f'state={data["state"]}')
    else:
        log.warning(f'  MPPT[{tracker_id}] unit={unit}: žádná data (špatné unit ID?)')


def _read_system(client, ts: str, unit: int):
    r  = lambda reg: read_reg(client, reg, unit)
    rs = lambda reg: signed16(read_reg(client, reg, unit))

    g1 = rs(820); g2 = rs(821); g3 = rs(822)
    c1 = rs(817); c2 = rs(818); c3 = rs(819)
    pv = r(850)
    soc = r(843)
    bat = rs(842)

    def ssum(*vals):
        v = [x for x in vals if x is not None]
        return sum(v) if v else None

    data = {
        'grid_power':  ssum(g1, g2, g3),
        'consumption': ssum(c1, c2, c3),
        'pv_total':    pv,
        'batt_soc':    soc,
        'batt_power':  bat,
    }

    if any(v is not None for v in data.values()):
        upsert_system(ts, data)
        log.info(f'  System unit={unit}: '
                 f'grid={data["grid_power"]}W  pv={data["pv_total"]}W  '
                 f'soc={data["batt_soc"]}%')
    else:
        log.warning(f'  System unit={unit}: žádná data (špatné unit ID?)')


# ── Hlavní smyčka ──────────────────────────────────────────────────
def main():
    log.info(f'GridSight Modbus service start · DB: {DB_PATH}')

    # Počkej na DB (PHP kontejner ji vytvoří při prvním startu)
    for i in range(30):
        if DB_PATH.exists():
            break
        log.info(f'Čekám na DB ({i+1}/30)...')
        time.sleep(2)

    if not DB_PATH.exists():
        log.error(f'DB nenalezena: {DB_PATH}. Spusť nejdříve PHP kontejner.')
        sys.exit(1)

    while running:
        host     = get_setting('cerbo_host', '')
        port     = int(get_setting('cerbo_port', '502'))
        unit_t0  = int(get_setting('modbus_unit_t0', '100'))
        unit_t1  = int(get_setting('modbus_unit_t1', '101'))
        unit_sys = int(get_setting('modbus_unit_system', '100'))
        interval = int(get_setting('modbus_interval', '300'))

        if not host:
            log.info('cerbo_host není nastaven – nastavte přes web UI (Settings)')
            time.sleep(60)
            continue

        try:
            collect(host, port, unit_t0, unit_t1, unit_sys)
        except ConnectionError as e:
            log.error(f'Připojení selhalo: {e}')
        except Exception as e:
            log.error(f'Chyba: {e}')

        # Čekej na další cyklus (nebo na SIGTERM)
        for _ in range(interval):
            if not running:
                break
            time.sleep(1)

    log.info('Modbus service zastaven.')


if __name__ == '__main__':
    main()
