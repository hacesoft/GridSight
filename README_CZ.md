[🇨🇿 **Česky**](README_CZ.md) | [🇬🇧 English](README.md)

# GridSight 0.9.1

GridSight je aplikace pro Nextcloud 35 pro sledování fotovoltaiky, spotřeby domu, baterie, sítě a cen elektřiny. Čte LINEA API, ukládá měřenou historii a porovnává ji s měsíčními reporty EG.D. Technologii neřídí a neposílá příkazy pro změnu nastavení ESS.


<img width="2134" height="1153" alt="GridSight dashboard" src="https://github.com/user-attachments/assets/6cae686b-1849-446d-9b7d-cb213ddca6f2" />

## Funkce

- LIVE: aktuální energetické toky, baterie, SPOT ceny, předpověď a dostupná zařízení.
- Historie: časové grafy, součty energií, výběr období a viditelných křivek.
- ESS: zobrazení stavů a nastavení publikovaných LINEA.
- Sběr a události: stav sběrače a protokol změn.
- Analýza: reporty EG.D, smlouvy a ceny, HDO, výpočty odběru a srovnání domu bez FVE.
- Delta Green: ruční evidence vyúčtování prodeje a porovnání s hrubým výpočtem SPOTu.

## Požadavky a instalace

Nextcloud 35, PHP 8.3 nebo novější podle `src/appinfo/info.xml`, zapnutý Shared App Core alespoň `0.18.0-dev.2` a dostupné LINEA API se schématem `1`. Technické ID aplikace je `hc_gridsight`.

Zdrojový balíček obsahuje aplikaci v `src/`, instalační skripty a dokumentaci. Pro podporované prostředí Synology/Docker jej rozbalte a spusťte v kořeni projektu:

```sh
sudo sh install.sh
```

Odinstalace: `sudo sh uninstall.sh`; před použitím si přečtěte [instalační návod](docs/cz/01_INSTALACE.md). Výchozí schéma vytváří jediná baseline migrace Nextcloudu. Instalační kontrola doplňuje chybějící tabulky a sloupce bez mazání uložených měření.

## Dokumentace

- [Přehled](docs/cz/README.md)
- [Instalace a provoz](docs/cz/01_INSTALACE.md)
- [Ovládání](docs/cz/02_OVLADANI.md)
- [Data a historie](docs/cz/03_DATA_A_HISTORIE.md)
- [SPOT výpočty](docs/cz/04_SPOT.md)
- [Analýza elektřiny](docs/cz/05_ANALYZA_ELEKTRINY.md)

## Zdrojový balíček

`sh build-release.sh` vytvoří ZIP se zdrojovým kódem, `install.sh`, `uninstall.sh` a CZ/EN dokumentací. Obsahuje pouze GridSight. Uživatelská měření, smlouvy, přístupové údaje ani importované XLSX nejsou součástí balíčku.

Licence: AGPL-3.0-or-later, viz [LICENSE](LICENSE).
