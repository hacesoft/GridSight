[🇨🇿 **Česky**](README_CZ.md) | [🇬🇧 English](README.md)


# GridSight 1.0.1

GridSight je aplikace pro Nextcloud 35 pro sledování fotovoltaiky, spotřeby domu, baterie, sítě a cen elektřiny. Čte LINEA API, ukládá měřenou historii a porovnává ji s měsíčními reporty EG.D. Technologii neřídí a neposílá příkazy pro změnu nastavení ESS.


![Přehled GridSight](https://github.com/user-attachments/assets/6cae686b-1849-446d-9b7d-cb213ddca6f2)

## Funkce

- LIVE: aktuální energetické toky, baterie, SPOT ceny, předpověď a dostupná zařízení.
- Historie: časové grafy, součty energií, výběr období a viditelných křivek.
- ESS: zobrazení stavů a nastavení publikovaných LINEA.
- Sběr a události: stav sběrače a protokol změn.
- Analýza: reporty EG.D, smlouvy a ceny, HDO, výpočty odběru a srovnání domu bez FVE.
- Delta Green: ruční evidence vyúčtování prodeje a porovnání s hrubým výpočtem SPOTu.

## Požadavky a instalace

Nextcloud 35, PHP 8.3 nebo novější podle `src/appinfo/info.xml`, zapnutý Shared App Core alespoň `0.18.8` a dostupné LINEA API se schématem `1`. Technické ID aplikace je `hc_gridsight`.

Zdrojový balíček obsahuje aplikaci v `src/`, instalační skripty a dokumentaci. Pro podporované prostředí Synology/Docker jej rozbalte a spusťte v kořeni projektu:

```sh
sudo sh install.sh
```

Odinstalace: `sudo sh uninstall.sh`; před použitím si přečtěte [instalační návod](docs/cz/01_INSTALACE.md). Výchozí schéma vytváří jediná baseline migrace Nextcloudu. Instalační kontrola doplňuje chybějící tabulky a sloupce bez mazání uložených měření.

Povinné Core je dostupné v [repozitáři Hacesoft Core](https://github.com/hacesoft/core). Nainstalujte a zapněte je před instalací GridSightu.

## Dokumentace

- [Přehled](docs/cz/README.md)
- [Instalace a provoz](docs/cz/01_INSTALACE.md)
- [Ovládání](docs/cz/02_OVLADANI.md)
- [Data a historie](docs/cz/03_DATA_A_HISTORIE.md)
- [SPOT výpočty](docs/cz/04_SPOT.md)
- [Analýza elektřiny](docs/cz/05_ANALYZA_ELEKTRINY.md)
- [Kompletní přehled funkcí a oznámení](docs/cz/06_PREHLED_FUNKCI_A_OZNAMENI.md)
- [API a význam datových položek](docs/cz/08_API_A_DATOVA_POLE.md)

## Zdrojové kódy a soukromá data

Veřejný zdrojový archiv obsahuje kód GridSight, instalační pomocné soubory a českou i anglickou dokumentaci. Uživatelská měření, nahrané smlouvy, přístupové údaje a importované soubory XLSX součástí archivu nejsou. Popis rozhraní a význam datových položek je v [referenci API](docs/cz/08_API_A_DATOVA_POLE.md).

Licence: AGPL-3.0-or-later, viz [LICENSE](LICENSE).

## Jazykové mutace

Rozhraní aplikace podporuje všech 11 jazyků: čeština (`cs`), angličtina (`en`), němčina (`de`), španělština (`es`), francouzština (`fr`), italština (`it`), nizozemština (`nl`), polština (`pl`), portugalština (`pt`), slovenština (`sk`) a ukrajinština (`uk`). Nepodporovaný jazyk i jednotlivý chybějící překlad používají angličtinu (EN). Katalogy obsahují shodnou úplnou sadu klíčů a zachovávají proměnné ve zprávách. Texty pocházející přímo ze serveru či komponent Core se řídí lokalizací těchto služeb. Uživatelská a technická dokumentace jsou česky a anglicky. Doplňková maďarština (`hu`) má rovněž úplný katalog všech 594 překladových klíčů, včetně delších popisů a oznámení o výpadku LINEA API. Technické identifikátory a názvy produktů se nepřekládají.

## Kontrola aktualizací

Sekce O aplikaci kontroluje GridSight (`hacesoft/GridSight`) i povinné Core (`hacesoft/core`). Ve výchozí větvi svého repozitáře čte `src/appinfo/info.xml`, ověří ID aplikace a porovná `<version>` s nainstalovanou verzí. Adresář `release/` slouží pouze jako záložní zdroj. Úspěšný výsledek Core ukládá na 6 hodin; kontrolu spouští otevření sekce, neběží periodicky na pozadí. Přesné adresy, pravidla výběru a diagnostiku popisuje [referenční dokumentace Core](https://github.com/hacesoft/core/blob/main/docs/cz/CORE_REFERENCE.md).

## Historie: 2D a 3D

Historie nabízí přepínač 2D/3D se společným výběrem období od 1 hodiny po všechna data. Ve 3D vyberte jeden parametr; graf se zobrazí automaticky. Osy představují místní čas během dne, datum a hodnotu (W, %, °C nebo CZK/kWh). Jde o časově agregované historické hodnoty, nikoliv denní energii v kWh. Dlouhá období se pro zobrazení slučují nejvýše do 64 řádků a 96 časových sloupců; původní databázová data zůstávají nezměněná. Buňka s dostupnými vzorky ukazuje jejich průměr; částečná chybějící měření uvnitř buňky nejsou samostatně zobrazena. Prázdné buňky jsou černé, nikoliv nulové hodnoty. Denní agregace bez podrobnějších vzorků nemohou obnovit průběh během dne.

Barvy nízkých a vysokých hodnot i chybějících dat lze změnit. Vyhlazení je výchozí vypnuté, ovlivňuje pouze povrch a nevyplňuje chybějící buňky. Nápověda ukazuje původní průměr buňky a čas reprezentativního vzorku. Tažením se graf otáčí, Shift mění třetí úhel, pravé tlačítko posouvá, kolečko přibližuje. Na dotykovém zařízení funguje otáčení jedním prstem a přiblížení dvěma prsty. Reset obnovuje pohled. Graf se kreslí lokálně v prohlížeči pomocí Canvas; nevyžaduje externí grafickou službu.

Graf využívá zbývající výšku okna. V Nastavení je uživatelská volba obrácení pouze svislého pohybu myši a šipek nahoru/dolů. Po kliknutí do grafu fungují šipky; se Shift otáčejí kolem třetí osy. Domeček nebo Home obnoví pohled.
