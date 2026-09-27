[🇨🇿 **Česky**](03_DATA_A_HISTORIE.md) | [🇬🇧 English](../en/03_DATA_AND_HISTORY.md)

# Data a historie

GridSight čte aktuální data z LINEA API přes Nextcloud; prohlížeč se k Node-RED nepřipojuje přímo. Samostatný sběrač přibližně jednou za sekundu drží hodnoty v paměti a do databáze ukládá dokončený pětiminutový blok. Výkon, SOC a teploty se agregují s ohledem na čas; energie se integruje. Zastaralé zdrojové stavy se do energie nezapočítávají.

| Logická tabulka | Obsah |
| --- | --- |
| `hc_gridsight_hist` | Pětiminutové měření a delší agregáty; volitelné teploty racku, baterie a měničů |
| `hc_gridsight_events` | Změny významných stavů |
| `hc_gridsight_snapshots` | Krátkodobé technické snímky |
| `hc_gridsight_runtime` | Stav a poslední činnost sběrače |
| `hc_gridsight_daily` | Denní odběr, dodávka a hrubá hodnota prodeje |
| `hc_gridsight_spot_hour` | Hodinová SPOT cena |

Nextcloud k těmto názvům automaticky přidává systémový prefix databáze. Při `dbtableprefix=oc_` je fyzický název například `oc_hc_gridsight_hist`. Systémový prefix neměňte. Instalátor přidává chybějící strukturu, zachovává existující řádky a neprovádí převod jiných tabulek.

Retence historie: pětiminutová data 30 dní, patnáctiminutová 365 dní, hodinová 1 825 dní; denní agregáty zůstávají. Technické snímky se uchovávají sedm dní. Agregace a promazávání provádí aplikace automaticky. Předvolby viditelných křivek jsou v uživatelském nastavení Nextcloudu a nemění uložená měření.

Dokumentované LINEA API používá obálku `temperatures: {available, data}` a pole `temperatures.data.racks[]`, `inverters[]`, `other[]`; každé čidlo má `name` a `temperatureC`. Počet měničů není pevně daný; GridSight pro historický graf páruje až tři měniče podle označení 1–3/L1–L3, jinak podle pořadí. Živé teploty ve Spotřebě domu se čtou přímo z aktuálního API, historické teploty až z uložených bloků. Schéma `1` nedefinuje samostatnou teplotu baterie. Volitelný sloupec pro ni zůstává `NULL`, pokud API nepřidá nezávislé čidlo; teplota racku se do něj nekopíruje. Starší uložená historie se nedopočítává z aktuálního stavu.

Příznak `temperatures.available: true` znamená, že je uložen blok modulu, nikoli že čidlo právě fyzicky odpovědělo. LINEA vrací také `data.updatedAt` a `system.stale`; při vyhodnocování čerstvosti je nutné tyto údaje vzít v úvahu. GridSight neukládá zastaralý hlavní stav do energetické historie.
