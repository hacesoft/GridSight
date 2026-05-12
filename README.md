# ⚡ GridSight

Vizualizace energetické bilance FVE instalace — data ze **smart metru distributora** (ČEZ / EG.D / PRE) + **Victron Cerbo GX** přes Modbus TCP.

[![Docker Hub](https://img.shields.io/docker/v/hacesoft/gridsight?label=Docker%20Hub)](https://hub.docker.com/r/hacesoft/gridsight)
[![Docker Pulls](https://img.shields.io/docker/pulls/hacesoft/gridsight)](https://hub.docker.com/r/hacesoft/gridsight)

## Co umí

| Funkce | Popis |
|--------|-------|
| 📊 **Dashboard** | odběr vs. dodávka, bilance, hodinový profil |
| ☀️ **FVE + Cerbo** | oba MPPT trackery, baterie SOC, systémový výkon |
| 💰 **Finance** | náklady, příjem z exportu, úspora vlastní spotřeby, roční odhad |
| ⚖️ **Srovnání** | libovolná dvě období vedle sebe s % rozdílem |
| 📁 **Soubory** | drag & drop upload REP_DATA_*.xlsx, historia v SQLite |
| 🔄 **Auto-import** | Python service hlídá Cerbo každých N sekund |
| 🎨 **Grafy** | sloupcový / křivka / plocha — přepínání jedním kliknutím |

## Tech stack

- **PHP 8.3** + Apache — backend logika, šablony
- **SQLite** via PDO — perzistentní databáze
- **PhpSpreadsheet** — parsování XLSX od distributora
- **HTMX** — dynamické HTML aktualizace bez psaní JS
- **Alpine.js** — reaktivní UI stav v HTML atributech
- **Chart.js** — grafy (minimální JS v `assets/js/charts.js`)
- **Python + pymodbus** — Modbus polling z Cerbo GX (samostatný kontejner)

## Rychlá instalace

```bash
mkdir gridsight && cd gridsight
curl -O https://raw.githubusercontent.com/hacesoft/GridSight/main/docker-compose.yml
curl -O https://raw.githubusercontent.com/hacesoft/GridSight/main/.env.example
cp .env.example .env
mkdir -p data
docker compose up -d
```

Aplikace běží na **http://\<IP_NASU\>:5080**

## Nastavení Cerbo GX

1. Web UI → **Settings** → zadej IP adresu Cerba
2. Unit ID zjistíš: Cerbo display → Settings → Services → Modbus TCP → Available services
3. Nastav tarify (Kč/kWh)
4. Modbus service se automaticky připojí

## Přidání dat ze smart metru

- **Web UI** → Soubory → drag & drop `REP_DATA_*.xlsx`
- Nebo zkopíruj XLSX do složky `data/` na NASu

## Aktualizace

```bash
docker compose pull
docker compose up -d
# Data v ./data/ jsou nedotčena
```

## Vývoj / build ze zdrojů

```bash
git clone https://github.com/hacesoft/GridSight.git
cd GridSight
cp .env.example .env
# Odkomentuj `build:` sekce v docker-compose.yml
docker compose up -d --build
```

---
*Součást projektu [Linea](https://github.com/hacesoft/Linea) — řízení FVE přes Node-RED*
