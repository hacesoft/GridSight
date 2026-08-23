# ⚡ GridSight

**[🇨🇿 Czech version](README_CZ.md)** | **[👨‍💻 More projects by the author](https://github.com/hacesoft?tab=repositories)**

---

Energy balance visualization for PV installations — data from **smart grid meter** (ČEZ / EG.D / PRE) + **Victron Cerbo GX** via Modbus TCP.

[![Docker Hub](https://img.shields.io/docker/v/hacesoft/gridsight?label=Docker%20Hub)](https://hub.docker.com/u/hacesoft/)

---

## 📋 Table of Contents

- [What it does](#what-it-does)
- [Features](#features)
- [Tech Stack](#tech-stack)
- [Quick Start (Docker)](#quick-start-docker)
- [Docker Installation on Synology NAS](#docker-installation-on-synology-nas)
- [Cerbo GX Setup](#cerbo-gx-setup)
- [Import Smart Meter Data](#import-smart-meter-data)
- [Updates](#updates)
- [Development / Build from Source](#development--build-from-source)

---

## What it does

**GridSight** is a web application for monitoring and analyzing the energy balance of a photovoltaic installation with battery storage. It combines data from your grid distributor's smart meter with real-time information from Victron Cerbo GX inverter systems.

---

## Features

| Feature | Description |
|---------|-------------|
| 📊 **Dashboard** | Grid consumption vs. export, balance, hourly profile |
| ☀️ **PV + Cerbo** | Both MPPT trackers, battery SOC, system power |
| 💰 **Finance** | Costs, export income, self-consumption savings, annual estimates |
| ⚖️ **Comparison** | Compare any two periods side-by-side with % differences |
| 📁 **Files** | Drag & drop upload REP_DATA_*.xlsx, historical data in SQLite |
| 🔄 **Auto-import** | Python service polls Cerbo every N seconds |
| 🎨 **Charts** | Bar / line / area graphs — one-click switching |

---

## Tech Stack

- **PHP 8.3** + Apache — backend logic, templates
- **SQLite** via PDO — persistent database
- **PhpSpreadsheet** — parsing XLSX from grid distributor
- **HTMX** — dynamic HTML updates without writing JS
- **Alpine.js** — reactive UI state in HTML attributes
- **Chart.js** — graphs (minimal JS in `assets/js/charts.js`)
- **Python + pymodbus** — Modbus polling from Cerbo GX (separate container)

---

## Quick Start (Docker)

For testing or small deployments:

```bash
mkdir gridsight && cd gridsight
curl -O https://raw.githubusercontent.com/hacesoft/GridSight/main/docker-compose.yml
curl -O https://raw.githubusercontent.com/hacesoft/GridSight/main/.env.example
cp .env.example .env
mkdir -p data
docker compose up -d
```

**Application runs on:** `http://<your-ip>:5080`

---

## Docker Installation on Synology NAS

For production deployments on Synology NAS, follow the complete guide below.

### Directory Structure

```
/volume1/docker/gridsight/
└── docker-compose.yml
```

**That's it!** All data is stored in a Docker volume that's created automatically.

### Step 1: Connect to NAS

```powershell
ssh admin@192.168.X.X
```

### Step 2: Create Project Directory

```bash
# Create only the project directory
mkdir -p /volume1/docker/gridsight
cd /volume1/docker/gridsight
```

### Step 3: Create docker-compose.yml

```bash
cat > docker-compose.yml << 'EOF'
version: '3.8'

services:

  # ── PHP web application ────────────────────────────────────────
  gridsight:
    image: ghcr.io/hacesoft/gridsight:latest
    container_name: gridsight
    restart: unless-stopped
    ports:
      - "5080:80"
    volumes:
      - gridsight-data:/data
    environment:
      - DATA_DIR=/data
      - TZ=Europe/Prague
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/api.php?action=range"]
      interval: 30s
      timeout: 10s
      retries: 5
      start_period: 40s
    labels:
      - "com.centurylinklabs.watchtower.enable=true"
    networks:
      - gridsight

  # ── Python Modbus poller (Cerbo GX) ────────────────────────────
  gridsight-modbus:
    image: ghcr.io/hacesoft/gridsight-modbus:latest
    container_name: gridsight-modbus
    restart: unless-stopped
    volumes:
      - gridsight-data:/data
    environment:
      - DATA_DIR=/data
      - TZ=Europe/Prague
      - CERBO_IP=192.168.1.100
      - POLL_INTERVAL=300
    depends_on:
      gridsight:
        condition: service_healthy
    labels:
      - "com.centurylinklabs.watchtower.enable=true"
    networks:
      - gridsight

volumes:
  gridsight-data:
    driver: local

networks:
  gridsight:
    driver: bridge
EOF
```

### Step 4: Start Containers

```bash
docker-compose up -d

# Monitor startup
docker-compose logs -f
```

**That's it!** Docker automatically:
- Creates the `gridsight-data` volume
- Creates SQLite database at `/data/gridsight.db`
- Initializes all settings in the database
- Shares data between both containers (gridsight + gridsight-modbus)

### Container Management

```bash
# Stop
docker-compose stop

# Start
docker-compose start

# Restart
docker-compose restart

# View logs
docker-compose logs -f

# Update to new version
docker-compose pull
docker-compose up -d
```

### Backup Data

```bash
# Copy data directory
cp -r /volume1/docker/gridsight/data /volume1/backup/gridsight-$(date +%Y%m%d)
```

### Uninstall

```bash
cd /volume1/docker/gridsight

# Stop and remove containers
docker-compose down

# Remove data (CAREFUL!)
# rm -rf /volume1/docker/gridsight
```

---

## Cerbo GX Setup

### Find Unit ID

1. Cerbo display → **Settings → Services → Modbus TCP → Available services**
2. Note your **Unit ID** (usually `100`)

### Configure in GridSight

1. Open GridSight → **Settings**
2. Enter:
   - **Cerbo IP**: `192.168.X.X`
   - **Modbus Unit ID**: `100`
   - **Tariffs**: €/kWh for purchase and sale
3. Click **Save**

### Verify Connection

Check dashboard for:
- ✅ **PV power** (from MPPT trackers)
- ✅ **Battery SOC** (charge level)
- ✅ **System power**

If connection fails:
- Verify IP addresses are on same network
- Check firewall on Cerbo (Modbus TCP port 502)
- View logs: `docker-compose logs gridsight-modbus`

---

## Import Smart Meter Data

### Export from Distributor

1. Login to your grid distributor's portal
2. Download: `REP_DATA_XXXXXXXX_XXXXXXXX_[period].xlsx`

### Upload to GridSight

1. GridSight → **Files**
2. Drag & drop XLSX file
3. Click **Import**

Application automatically:
- Parses spreadsheet data
- Creates historical records in SQLite
- Updates financial overviews

---

## Updates

```bash
cd /volume1/docker/gridsight

# Pull latest images
docker-compose pull

# Update running containers
docker-compose up -d

# Data in ./data/ is preserved
```

---

## Development / Build from Source

For development or building custom versions:

```bash
git clone https://github.com/hacesoft/GridSight.git
cd GridSight
cp .env.example .env

# Uncomment `build:` sections in docker-compose.yml
# Then build and run
docker compose up -d --build
```

### Requirements

- Docker & Docker Compose
- Git
- 2GB+ disk space

### Services

- **PHP/Apache** on port 5080
- **SQLite** database in `./data/`
- **Modbus poller** (auto-connects to Cerbo)

---

## Troubleshooting

### Port 5080 not accessible

```bash
# Check container status
docker-compose ps

# View logs
docker-compose logs gridsight
```

### Modbus connection fails

```bash
# Verify Cerbo is reachable
ping 192.168.1.100

# Check Modbus logs
docker-compose logs gridsight-modbus

# Update IP in .env and restart
docker-compose restart gridsight-modbus
```

### Database locked

```bash
# Fix permissions
sudo chown nobody:nogroup /volume1/docker/gridsight/data/gridsight.db
sudo chmod 666 /volume1/docker/gridsight/data/gridsight.db

# Restart
docker-compose restart
```

---

## Additional Resources

- 📖 [Official GitHub](https://github.com/hacesoft/GridSight)
- 🐳 [Docker Hub](https://hub.docker.com/u/hacesoft/)
- 🔧 [Victron Modbus Documentation](https://www.victronenergy.com/support)

---

**Version:** 1.0 | **Last updated:** May 2026 | **Author:** HaceSoft

---

*Part of [Linea](https://github.com/hacesoft/Linea) project — PV management via Node-RED*
