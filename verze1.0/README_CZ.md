# ⚡ GridSight

**[🇬🇧 English version](README.md)** | **[👨‍💻 Další projekty autora](https://github.com/hacesoft?tab=repositories)**

---

Vizualizace energetické bilance FVE instalace — data ze **smart metru distributora** (ČEZ / EG.D / PRE) + **Victron Cerbo GX** přes Modbus TCP.

[![Docker Hub](https://img.shields.io/docker/v/hacesoft/gridsight?label=Docker%20Hub)](https://hub.docker.com/u/hacesoft/)

---

## 📋 Obsah

- [Co je to GridSight](#co-je-to-gridsight)
- [Funkce](#funkce)
- [Technologický stack](#technologický-stack)
- [Rychlý start (Docker)](#rychlý-start-docker)
- [Instalace na Synology NAS](#instalace-na-synology-nas)
- [Nastavení Cerbo GX](#nastavení-cerbo-gx)
- [Import dat ze smart metru](#import-dat-ze-smart-metru)
- [Aktualizace](#aktualizace)
- [Vývoj / Build ze zdrojů](#vývoj--build-ze-zdrojů)

---

## Co je to GridSight

**GridSight** je webová aplikace pro monitorování a analýzu energetické bilance fotovoltaické instalace s akumulátorem. Kombinuje data z chytrého měřidla tvého distributora se živými informacemi ze systému Victron Cerbo GX.

---

## Funkce

| Funkce | Popis |
|--------|-------|
| 📊 **Dashboard** | Odběr vs. dodávka, bilance, hodinový profil |
| ☀️ **FVE + Cerbo** | Oba MPPT trackery, baterie SOC, systémový výkon |
| 💰 **Finance** | Náklady, příjem z exportu, úspora vlastní spotřeby, roční odhad |
| ⚖️ **Srovnání** | Libovolná dvě období vedle sebe s % rozdílem |
| 📁 **Soubory** | Drag & drop upload REP_DATA_*.xlsx, historická data v SQLite |
| 🔄 **Auto-import** | Python service hlídá Cerbo každých N sekund |
| 🎨 **Grafy** | Sloupcový / křivka / plocha — přepínání jedním kliknutím |

---

## Technologický stack

- **PHP 8.3** + Apache — backend logika, šablony
- **SQLite** via PDO — perzistentní databáze
- **PhpSpreadsheet** — parsování XLSX od distributora
- **HTMX** — dynamické HTML aktualizace bez psaní JS
- **Alpine.js** — reaktivní UI stav v HTML atributech
- **Chart.js** — grafy (minimální JS v `assets/js/charts.js`)
- **Python + pymodbus** — Modbus polling z Cerbo GX (oddělený kontejner)

---

## Rychlý start (Docker)

Pro testování nebo menší nasazení:

```bash
mkdir gridsight && cd gridsight
curl -O https://raw.githubusercontent.com/hacesoft/GridSight/main/docker-compose.yml
curl -O https://raw.githubusercontent.com/hacesoft/GridSight/main/.env.example
cp .env.example .env
mkdir -p data
docker compose up -d
```

**Aplikace běží na:** `http://<tvá-ip>:5080`

---

## Instalace na Synology NAS

Pro produkční nasazení na Synology NAS postupuj dle kompletního průvodce níže.

### Adresářová struktura

```
/volume1/docker/gridsight/
└── docker-compose.yml
```

**To je vše!** Všechna data se ukládají do Docker volume, které se vytváří automaticky.

### Krok 1: Připojení k NAS

```powershell
ssh admin@192.168.X.X
```

### Krok 2: Vytvoření projektu

```bash
# Vytvoř pouze jeden adresář
mkdir -p /volume1/docker/gridsight
cd /volume1/docker/gridsight
```

### Krok 3: Vytvoření docker-compose.yml

```bash
cat > docker-compose.yml << 'EOF'
version: '3.8'

services:

  # ── PHP web aplikace ───────────────────────────────────────────
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

  # ── Python Modbus poller (Cerbo GX) ───────────────────────────
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

### Krok 4: Spuštění kontejnerů

```bash
docker-compose up -d

# Sleduj startup
docker-compose logs -f
```

**To je vše!** Docker automaticky:
- Vytváří volume `gridsight-data`
- Vytváří SQLite databázi v `/data/gridsight.db`
- Inicializuje všechna nastavení v databázi
- Sdílí data mezi oběma kontejnery (gridsight + gridsight-modbus)

### Správa kontejnerů

```bash
# Zastavení
docker-compose stop

# Spuštění
docker-compose start

# Restart
docker-compose restart

# Zobrazení logů
docker-compose logs -f

# Aktualizace na novou verzi
docker-compose pull
docker-compose up -d
```

### Zálohování dat

```bash
# Kopírování data adresáře
cp -r /volume1/docker/gridsight/data /volume1/backup/gridsight-$(date +%Y%m%d)
```

### Odinstalace

```bash
cd /volume1/docker/gridsight

# Zastavení a odebrání kontejnerů
docker-compose down

# Smazání dat (POZOR!)
# rm -rf /volume1/docker/gridsight
```

---

## Nastavení Cerbo GX

### Zjištění Unit ID

1. Na Cerbo display → **Settings → Services → Modbus TCP → Available services**
2. Poznamenej si **Unit ID** (obvykle `100`)

### Konfigurace v GridSight

1. Otevři GridSight → **Nastavení**
2. Zadej:
   - **Cerbo IP**: `192.168.X.X`
   - **Modbus Unit ID**: `100`
   - **Tarify**: Kč/kWh pro nákup a prodej
3. Klikni **Uložit**

### Ověření připojení

V dashboardu by měly vidět:
- ✅ **PV výkon** (z MPPT trackerů)
- ✅ **Baterie SOC** (stav nabití)
- ✅ **Systémový výkon**

Pokud se nepřipojí:
- Ověř IP adresy Cerba a NAS ve stejné podsíti
- Zkontroluj firewall na Cerbu (Modbus TCP port 502)
- Podívej se na logy: `docker-compose logs gridsight-modbus`

---

## Import dat ze smart metru

### Export z portálu distributora

1. Přihlášení do portálu tvého distributora
2. Stažení: `REP_DATA_XXXXXXXX_XXXXXXXX_[období].xlsx`

### Nahrání do GridSight

1. GridSight → **Soubory**
2. Drag & drop XLSX soubor
3. Klikni **Importovat**

Aplikace automaticky:
- Parsuje data z tabulky
- Vytvoří historické záznamy v SQLite
- Aktualizuje finanční přehledy

---

## Aktualizace

```bash
cd /volume1/docker/gridsight

# Stažení nejnovějších obrazů
docker-compose pull

# Aktualizace běžících kontejnerů
docker-compose up -d

# Data v ./data/ jsou uchována
```

---

## Vývoj / Build ze zdrojů

Pro vývoj nebo vytváření vlastních verzí:

```bash
git clone https://github.com/hacesoft/GridSight.git
cd GridSight
cp .env.example .env

# Odkomentuj `build:` sekce v docker-compose.yml
# Pak build a spuštění
docker compose up -d --build
```

### Požadavky

- Docker & Docker Compose
- Git
- 2GB+ volného místa

### Služby

- **PHP/Apache** na portu 5080
- **SQLite** databáze v `./data/`
- **Modbus poller** (automatické připojení k Cerbu)

---

## Řešení problémů

### Port 5080 není dostupný

```bash
# Kontrola statusu kontejneru
docker-compose ps

# Zobrazení logů
docker-compose logs gridsight
```

### Modbus se nemůže připojit

```bash
# Ověř dostupnost Cerba
ping 192.168.1.100

# Zkontroluj Modbus logy
docker-compose logs gridsight-modbus

# Aktualizuj IP v .env a restartuj
docker-compose restart gridsight-modbus
```

### Databáze je locked

```bash
# Opravení oprávnění
sudo chown nobody:nogroup /volume1/docker/gridsight/data/gridsight.db
sudo chmod 666 /volume1/docker/gridsight/data/gridsight.db

# Restart
docker-compose restart
```

---

## Další zdroje

- 📖 [Oficiální GitHub](https://github.com/hacesoft/GridSight)
- 🐳 [Docker Hub](https://hub.docker.com/u/hacesoft/)
- 🔧 [Dokumentace Victron Modbus](https://www.victronenergy.com/support)

---

**Verze:** 1.0 | **Poslední aktualizace:** květen 2026 | **Autor:** HaceSoft

---

*Součást projektu [Linea](https://github.com/hacesoft/Linea) — řízení FVE přes Node-RED*
