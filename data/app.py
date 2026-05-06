from flask import Flask, render_template, jsonify, request
from pathlib import Path
import os, threading
from db import (init_db, get_setting, set_setting, get_all_settings,
                upsert_grid_rows, upsert_victron_rows, register_file,
                list_files, delete_file,
                query_grid, query_victron, query_hourly_profile,
                get_totals, get_date_range, has_victron_data)
from parser import parse_xlsx, parse_victron_csv
import watchdog_runner

app = Flask(__name__)
DATA_DIR = Path(os.environ.get("DATA_DIR", "./data"))

# ── Init ──────────────────────────────────────────────────────────────────────
init_db()
watchdog_runner.start(DATA_DIR)   # background folder watcher

# ── Pages ─────────────────────────────────────────────────────────────────────
@app.route("/")
def index():
    dr = get_date_range()
    return render_template("index.html",
                           date_min=dr[0][:10] if dr[0] else "",
                           date_max=dr[1][:10] if dr[1] else "")

# ── Files ─────────────────────────────────────────────────────────────────────
@app.route("/api/files")
def api_files():
    return jsonify(list_files())

@app.route("/api/files/<filename>", methods=["DELETE"])
def api_delete_file(filename):
    delete_file(filename)
    return jsonify({"ok": True})

@app.route("/api/upload", methods=["POST"])
def api_upload():
    if 'file' not in request.files:
        return jsonify({"error": "no file"}), 400
    f = request.files['file']
    fname = f.filename

    if fname.endswith(".xlsx"):
        path = DATA_DIR / fname
        f.save(path)
        return _import_xlsx(path, fname)
    elif fname.endswith(".csv"):
        path = DATA_DIR / fname
        f.save(path)
        return _import_victron_csv(path, fname)
    else:
        return jsonify({"error": "Podporované formáty: REP_DATA_*.xlsx, victron_*.csv"}), 400

def _import_xlsx(path, fname):
    try:
        rows, ts_from, ts_to = parse_xlsx(path)
        upsert_grid_rows([(r + (fname,)) for r in rows], fname)
        register_file(fname, len(rows), ts_from, ts_to)
        return jsonify({"ok": True, "filename": fname,
                        "records": len(rows), "from": ts_from, "to": ts_to})
    except Exception as e:
        return jsonify({"error": str(e)}), 500

def _import_victron_csv(path, fname):
    try:
        rows = parse_victron_csv(path)
        upsert_victron_rows([(r + (fname,)) for r in rows], fname)
        register_file(fname, len(rows),
                      rows[0][0] if rows else "", rows[-1][0] if rows else "")
        return jsonify({"ok": True, "filename": fname, "records": len(rows)})
    except Exception as e:
        return jsonify({"error": str(e)}), 500

# ── Data API ──────────────────────────────────────────────────────────────────
@app.route("/api/data")
def api_data():
    ts_from = request.args.get("from", "2000-01-01T00:00:00")
    ts_to   = request.args.get("to",   "2099-12-31T23:59:59")
    group   = request.args.get("group", "day")   # 15min|hour|day|week|month
    compare = request.args.get("compare", "")     # optional second range "from2,to2"

    grid    = query_grid(ts_from, ts_to, group)
    hourly  = query_hourly_profile(ts_from, ts_to)
    totals  = get_totals(ts_from, ts_to)
    vict    = query_victron(ts_from, ts_to, group) if has_victron_data(ts_from, ts_to) else []

    result = {"grid": grid, "hourly": hourly, "totals": totals, "victron": vict}

    if compare:
        try:
            cf, ct = compare.split(",")
            result["compare"] = {
                "grid":   query_grid(cf.strip(), ct.strip(), group),
                "totals": get_totals(cf.strip(), ct.strip()),
                "label":  f"{cf[:10]} – {ct[:10]}",
            }
        except Exception:
            pass

    return jsonify(result)

@app.route("/api/range")
def api_range():
    dr = get_date_range()
    return jsonify({"min": dr[0], "max": dr[1]})

# ── Settings ──────────────────────────────────────────────────────────────────
@app.route("/api/settings", methods=["GET"])
def api_settings_get():
    return jsonify(get_all_settings())

@app.route("/api/settings", methods=["POST"])
def api_settings_set():
    data = request.get_json(force=True)
    for k, v in data.items():
        set_setting(k, str(v))
    # restart watcher if watch_folder changed
    if "watch_folder" in data:
        watchdog_runner.restart(Path(data["watch_folder"]) if data["watch_folder"] else DATA_DIR)
    return jsonify({"ok": True})

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000, debug=False)
