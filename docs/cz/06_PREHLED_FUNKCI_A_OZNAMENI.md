[🇨🇿 **Česky**](06_PREHLED_FUNKCI_A_OZNAMENI.md) | [🇬🇧 English](../en/06_FEATURES_AND_NOTICES.md)

# Podrobný přehled funkcí a oznámení GridSight 1.0.2

Tato příručka shrnuje **skutečně implementované funkce** aplikace a jejich hranice. GridSight načítá LINEA API v režimu **pouze pro čtení**. Příkazy pro řízení FVE, ESS, měničů ani baterie neodesílá. Informace jsou dostupné pouze v rozsahu, který poskytují zdrojové služby; chybějící údaj je označen `—` a nemusí znamenat fyzickou nulu.

## 1. Živý přehled (LIVE)

| Část | Zobrazené údaje a omezení |
| --- | --- |
| Síť | Okamžitý výkon, jednotlivé fáze L1–L3, odběr ze sítě a dodávka do sítě. Kladný tok znamená odběr. |
| Spotřeba domu | Okamžitý příkon domu, fáze L1–L3 a dostupné teploty měničů; přehled denní spotřeby a odhadu. |
| Výroba FVE | Celkový výkon, dostupné řetězce MPPT, napětí, proud a dílčí výroba; dnešní výroba a odhad. |
| Baterie | SOC, okamžitý výkon, proud, napětí, BatteryLife limit, denní/odrestartové energetické součty z LINEA/VRM. Teplota racku není automaticky teplotou baterie. |
| SPOT, počasí a denní světlo | Aktuální cena, prahová hodnota, hodinový cenový rozpis, predikce výroby/spotřeby, počasí, srážky, slunce a délka dne dle zdroje. |
| Klimatizace | Stav zařízení Daikin/Onecta, režim, naměřené a požadované teploty, dostupná diagnostika a detaily. Stav `ERROR` přebírá z podkladů zařízení. |
| UPS | Stav UPS publikovaný NUT, zatížení, případné hodnoty baterie, chybové informace a podrobnosti. |
| Shelly a čidla | Dostupné Shelly vstupy/výstupy, kouřová čidla a další připojená zařízení. Interval hlášení čidla nemusí být průběžný. |

Karty se přizpůsobují dostupné šířce Nextcloudu a používají světlý či tmavý motiv. Viditelnost údajů závisí na dostupnosti příslušné části odpovědi LINEA API.

## 2. ESS – pouze stav, nikoli ovládání

Zobrazují se režimy, přepínače a parametry zveřejněné zdrojem LINEA: Grid Point, limity, rezervy, SOC, predikční podmínky, nabíjení ze sítě a režimy Morning/Evening Peak, Delay Charging, Spot-Grid Charging a související časová okna, pokud jsou k dispozici. Význam parametrů vysvětluje nápověda vedle polí. Zobrazený stav není příkaz ani potvrzení, že GridSight regulaci změnil.

## 3. Historie a vizualizace

- Sběrač běžící samostatně od stránky přibližně každou sekundu načítá LINEA. Časově vážené hodnoty ukládá po dokončení pětiminutového okna. Neplatné nebo zastaralé údaje nesmí navyšovat energii.
- Křivky zahrnují dostupný výkon FVE, domu a sítě, dodávku, baterii/SOC, ceny SPOT, předpovědi a teploty. Jednotlivé křivky lze vypnout **jen pro zobrazení**, nikoli pro měření.
- Výběr časového období: hodina, 6 h, 24 h, týden, 30 dní, dnes/včera, 3/6/12 měsíců, půlrok, kalendářní rok, 2 roky a veškerá data.
- **2D graf**: přepínání křivek v legendě, výběr času a interaktivní přiblížení oblasti.
- **3D graf**: jedna veličina, čas dne × datum × hodnota; otáčení, posun, zoom, barvy, volitelné vyhlazení a uživatelské obrácení svislého pohybu. Prázdná buňka není nula. Graf používá Canvas v prohlížeči.
- Retence: 5min data 30 dní, 15min 365 dní, hodinové 1 825 dní, denní agregace dlouhodobě; diagnostické snímky 7 dní. Agregace starší historii nerozšiřuje o neexistující detaily.

## 4. Sběr, databáze a události

Záložka **Sběr a databáze** ukazuje stav rychlého sběrače, poslední měření/zápis, případnou diagnostiku a stav databázových bloků. Záložka **Události** zobrazuje až 100 uložených změn stavů. Hlídací úloha Nextcloudu kontroluje životaschopnost procesu, sama nenahrazuje jeho sekundové měření.

## 5. SPOT a ocenění dodávky

Hodinový cenový graf označuje SPOT práh a zachovává záporné ceny. Hrubá hodnota denní dodávky vzniká ze změřené energie krát odpovídající ceny v okamžiku vzorku; při chybějících cenách se **úplný výnos neuvádí**, nikoli nevypočtená část jako 0 Kč. Výsledkem není faktura ani čistá platba od obchodníka. Podrobnosti: [Denní SPOT](04_SPOT.md).

## 6. EG.D, smlouvy, HDO a analýza

- **Reporty EG.D**: hromadný import měsíčních XLSX s ověřením 18místného EAN, celého měsíce, čtvrthodinových intervalů a čísel; měření ICQ2/ISQ2 nebo DCC1/DSC1. Chyby se hlásí u jednotlivých souborů. Detail obsahuje souhrny po dnech a 15min intervaly. Export vybraných reportů do ZIP; původní XLSX jsou v neveřejném Nextcloud AppData.
- **Smlouvy a ceny**: historie nepřekrývajících se smluv, dat účinnosti a cen VT/NT, pevné měsíční platby, dobrovolná zelená přirážka a odkazy na podklady. Úplná regulovaná cena z jednoho roku se automaticky nepřenáší do dalšího.
- **HDO**: ručně definované kalendářní intervaly nízkého tarifu nebo ověřené historické vstupy Shelly. Neúplná evidence není potichu nahrazena odhadem 0 Kč.
- **Výsledky**: energie odběru/dodávky podle distributora, VT/NT, částka za pokrytá období a porovnání odběru domu bez FVE pouze při dostatečné historii LINEA. Čísla jsou **odhady**, nikoli faktura.
- **Delta Green**: ruční zadávání, úprava a mazání vyúčtování prodeje, evidence období a EAN prodeje, porovnání se SPOT při dostatečném pokrytí cenami a měřením.

Úplné podmínky importu, cen a výpočtů: [Analýza elektřiny](05_ANALYZA_ELEKTRINY.md).

## 7. Nastavení, oprávnění a aktualizace

Správce nastavuje URL LINEA API, povolení historie a společné údaje analýzy. Uživatel si mění obnovovací interval, viditelnost křivek a orientaci 3D ovládání. Zobrazení dat je možné bez administrátorských práv, společné údaje upravuje jen správce. Sekce „O aplikaci“ kontroluje vydání GridSight a požadovaného Shared App Core přes veřejná metadata repozitářů; neprovádí sama instalaci aktualizace.

## 8. Oznámení a dostupnost LINEA API

| Situace | Chování aplikace |
| --- | --- |
| Restart Node-RED, prázdná odpověď cURL 52, přechodné HTTP 502/503/504 | Informační oznámení **„LINEA API je dočasně nedostupné. Čeká se na obnovení spojení.“** místo surové technické chyby. |
| LINEA se opět připojí | Následující úspěšná aktualizace obnoví LIVE a odstraní přechodné oznámení. |
| Neplatná verze či čtecí kontrakt API | Skutečná chyba kompatibility; nejde o běžné čekání na restart. |
| Chybějící údaj zařízení | Pomlčka `—` nebo stav zařízení; automaticky se nepředstírá nulová spotřeba. |
| Chyba během importu EG.D nebo ukládání cen | Samostatné chybové hlášení s příčinou; nic se neskrývá za výpadek API. |

API zpracovává server Nextcloudu; prohlížeč se k Node-RED nepřipojuje přímo. Během výpadku mohou být aktuální hodnoty nedostupné, uložená historie a importy se tím nemažou.

## 9. Lokalizace

Všech **11 povinných jazyků Hacesoft** (`en`, `cs`, `de`, `es`, `fr`, `it`, `nl`, `pl`, `pt`, `sk`, `uk`) má úplnou sadu překladových klíčů. Doplňková **maďarština (`hu`)** má nyní úplný katalog 594 přeložených textů včetně oznámení o výpadku LINEA, nastavení, historie a energetické analýzy. Názvy produktů a technické identifikátory zůstávají beze změny. Uživatelské návody a technická dokumentace jsou česky a anglicky.
