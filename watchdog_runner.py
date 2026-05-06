"""
Watches DATA_DIR for new REP_DATA_*.xlsx files and auto-imports them.
Uses polling (no inotify needed) so it works on Synology volumes.
"""
import threading, time, logging
from pathlib import Path
from db import list_files, upsert_grid_rows, register_file

log = logging.getLogger("watchdog")
_thread = None
_stop_event = threading.Event()
_watch_dir = None

def start(directory: Path):
    global _watch_dir, _thread, _stop_event
    _watch_dir = directory
    _stop_event = threading.Event()
    _thread = threading.Thread(target=_loop, daemon=True)
    _thread.start()
    log.info(f"Watchdog started on {directory}")

def restart(directory: Path):
    global _watch_dir, _stop_event, _thread
    _stop_event.set()
    if _thread:
        _thread.join(timeout=5)
    start(directory)

def _loop():
    from parser import parse_xlsx
    known = {f["filename"] for f in list_files()}
    while not _stop_event.is_set():
        try:
            d = Path(_watch_dir)
            if d.exists():
                for xlsx in d.glob("REP_DATA_*.xlsx"):
                    if xlsx.name not in known:
                        log.info(f"Auto-importing {xlsx.name}")
                        try:
                            rows, ts_from, ts_to = parse_xlsx(xlsx)
                            upsert_grid_rows([(r + (xlsx.name,)) for r in rows], xlsx.name)
                            register_file(xlsx.name, len(rows), ts_from, ts_to)
                            known.add(xlsx.name)
                            log.info(f"  → {len(rows)} records, {ts_from} – {ts_to}")
                        except Exception as e:
                            log.error(f"  → FAILED: {e}")
        except Exception as e:
            log.error(f"Watchdog error: {e}")
        _stop_event.wait(timeout=60)   # check every 60 s
