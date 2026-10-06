[🇨🇿 **Česky**](README.md) | [🇬🇧 English](../en/README.md)

# GridSight – dokumentace

GridSight zobrazuje energetické a technické údaje poskytované LINEA API uvnitř Nextcloudu. Živý přehled je jen pro čtení; vlastní databáze slouží k historii, událostem, importovaným datům EG.D a výpočtům. Rozhraní a společné služby poskytuje Shared App Core.

1. [Instalace a provoz](01_INSTALACE.md)
2. [Ovládání](02_OVLADANI.md)
3. [Data a historie](03_DATA_A_HISTORIE.md)
4. [Denní SPOT výpočet](04_SPOT.md)
5. [Analýza elektřiny a import EG.D](05_ANALYZA_ELEKTRINY.md)

## Jazykové mutace

Rozhraní aplikace podporuje všech 11 jazyků: čeština (`cs`), angličtina (`en`), němčina (`de`), španělština (`es`), francouzština (`fr`), italština (`it`), nizozemština (`nl`), polština (`pl`), portugalština (`pt`), slovenština (`sk`) a ukrajinština (`uk`). Nepodporovaný jazyk i jednotlivý chybějící překlad používají angličtinu (EN). Katalogy obsahují shodnou úplnou sadu klíčů a zachovávají proměnné ve zprávách. Texty pocházející přímo ze serveru či komponent Core se řídí lokalizací těchto služeb. Návody a vývojová dokumentace jsou pouze CZ a EN.

Při každé další úpravě aplikace se ověří jazyky proti společné sadě `cs`, `en`, `de`, `es`, `fr`, `it`, `nl`, `pl`, `pt`, `sk`, `uk`. Doplní se chybějící jazyky i překladové klíče, prověří se výběr jazyka podle Nextcloudu a aktualizuje seznam skutečně podporovaných jazyků. Přítomnost souboru není důkaz úplného překladu. Návody a vývojová dokumentace se vydávají pouze česky a anglicky.

Kontrola překladů pro další vydání (Python 3 a Node.js):

```sh
python3 scripts/check-languages.py
```
