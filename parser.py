import pandas as pd
import re
from datetime import datetime

COLS = ['cas','DCC0','DCC1','DKC0','DKC1','DLC0','DLC1','DMC0','DMC1','DNC0','DNC1','DSC0','DSC1']

def parse_xlsx(path):
    """Returns list of tuples ready for upsert_grid_rows, plus meta info."""
    df = pd.read_excel(path, sheet_name=0, header=None, skiprows=4, engine='openpyxl')
    if df.shape[1] < 12:
        raise ValueError(f"Unexpected column count: {df.shape[1]}")

    df.columns = COLS[:df.shape[1]]
    for c in COLS[1:df.shape[1]]:
        df[c] = pd.to_numeric(df[c], errors='coerce').fillna(0.0)

    df = df.dropna(subset=['cas'])
    df = df[df['cas'].astype(str).str.match(r'\d{2}\.\d{2}\.\d{4}')]

    rows = []
    for _, r in df.iterrows():
        try:
            ts = _parse_ts(str(r['cas']))
        except Exception:
            continue
        rows.append((
            ts,
            float(r.get('DCC0', 0)), float(r.get('DCC1', 0)),
            float(r.get('DKC0', 0)), float(r.get('DKC1', 0)),
            float(r.get('DLC0', 0)), float(r.get('DLC1', 0)),
            float(r.get('DMC0', 0)), float(r.get('DMC1', 0)),
            float(r.get('DNC0', 0)), float(r.get('DNC1', 0)),
            float(r.get('DSC0', 0)), float(r.get('DSC1', 0)),
        ))

    if not rows:
        raise ValueError("No valid rows parsed")

    ts_list = [r[0] for r in rows]
    return rows, min(ts_list), max(ts_list)

def _parse_ts(s):
    """Convert '01.04.2026 00:00:00' → '2026-04-01T00:00:00'"""
    s = s.strip()
    m = re.match(r'(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2}):(\d{2})', s)
    if m:
        d, mo, y, hh, mm, ss = m.groups()
        return f"{y}-{mo}-{d}T{hh}:{mm}:{ss}"
    raise ValueError(f"Cannot parse timestamp: {s}")

def parse_victron_csv(path):
    """Parse CSV export from VRM portal or custom Node-RED export.
    Expected columns (flexible): timestamp/ts, pv_power/PV, batt_soc/SOC,
    batt_power, load_power/Load, grid_power/Grid
    """
    df = pd.read_csv(path)
    df.columns = [c.strip().lower() for c in df.columns]

    col_map = {
        'ts': ['ts','timestamp','time','datetime','cas'],
        'pv_power': ['pv_power','pv','solar','pvpower','pv power'],
        'batt_soc': ['batt_soc','soc','battery_soc','battery soc','baterie'],
        'batt_power': ['batt_power','battery_power','battery power','batpower'],
        'load_power': ['load_power','load','consumption','spotreba','zatizeni'],
        'grid_power': ['grid_power','grid','sit','grid power'],
    }

    mapped = {}
    for key, aliases in col_map.items():
        for a in aliases:
            if a in df.columns:
                mapped[key] = a
                break

    if 'ts' not in mapped:
        raise ValueError("CSV musí mít sloupec s časovým razítkem (ts, timestamp, datetime...)")

    rows = []
    for _, r in df.iterrows():
        try:
            ts_raw = str(r[mapped['ts']]).strip()
            ts = _normalize_ts(ts_raw)
        except Exception:
            continue
        rows.append((
            ts,
            float(r[mapped['pv_power']]) if 'pv_power' in mapped else None,
            float(r[mapped['batt_soc']]) if 'batt_soc' in mapped else None,
            float(r[mapped['batt_power']]) if 'batt_power' in mapped else None,
            float(r[mapped['load_power']]) if 'load_power' in mapped else None,
            float(r[mapped['grid_power']]) if 'grid_power' in mapped else None,
        ))
    return rows

def _normalize_ts(s):
    for fmt in ('%Y-%m-%dT%H:%M:%S', '%Y-%m-%d %H:%M:%S', '%d.%m.%Y %H:%M:%S',
                '%Y-%m-%dT%H:%M', '%Y-%m-%d %H:%M', '%d.%m.%Y %H:%M'):
        try:
            return datetime.strptime(s, fmt).strftime('%Y-%m-%dT%H:%M:%S')
        except ValueError:
            pass
    raise ValueError(f"Cannot parse: {s}")
