# GridSight 1.0.1 – dokumentace zdrojového kódu a maďarská notace

Tento dokument vysvětluje pravidla pojmenovávání proměnných ve zdrojovém kódu, nikoli jazykové překlady. Přehled předpon je současně vložen přímo na začátek každého PHP nebo JavaScript souboru, v němž se příslušná notace používá.

## Předpony

| Předpona | Význam | Příklad |
|---|---|---|
| `s` | Řetězec, identifikátor nebo cesta | `sId`, `sPath` |
| `n` | Číslo, časový údaj, délka nebo jednotky | `nTs`, `nCount` |
| `b` | Logický stav (boolean) | `bLoading`, `bDisposed` |
| `a` | Pole nebo seznam | `aPoints`, `aValid` |
| `o` | Objekt, instance služby, DOM prvek nebo výjimka | `oRoot`, `oFailure` |
| `v` | Hodnota s nejednoznačným nebo měnícím se typem | `vValue`, `vStatus` |
| `m` | Mapová kolekce | `mBuckets` |
| `fn` | Funkce nebo callback uložený do proměnné | `fnCurrentCleanup` |

Předpona popisuje očekávaný typ **na úrovni dané funkce či průchodu daty**. U dynamických dat LINEA, odvozených polí a hodnot, které mohou být `null`, je bezpečnější `v` než předstírat jednoznačný typ.

## Výjimky důležité pro Nextcloud

- **PHP parametry funkcí a metod se nepřejmenovávají automaticky.** Jejich názvy mohou používat pojmenované argumenty, DI kontejner, framework nebo externí volající. Tato kompatibilita má přednost před stylistikou.
- **PHP vlastnosti, včetně statických** (například `\OC::$server`) se nepřejmenovávají automaticky. Přejmenování statické vlastnosti by změnilo chování aplikace.
- **Názvy JSON, API polí, databázových sloupců, route parametrů, HTML `data-*` a CSS tříd jsou veřejný datový kontrakt** a nepodléhají typovým předponám.
- **Konstanty `APP`, `CORE`, `EXPECTED_API_SCHEMA` a další pevné identifikátory** používají původní deklarované názvy.
- Pomocné shellové skripty zachovávají systémové konvence proměnných `HC_*`, `$1`, `$?` a jména běhových prostředí; nejde o typovou notaci JavaScriptu/PHP.

## Komentáře funkcí

Každá pojmenovaná funkce v PHP a JavaScriptu obsahuje český komentář s účelem, **konkrétními názvy vstupních parametrů** a **typem i významem výstupu**. Anonymní callbacky jsou komentovány u místa použití. Konstruktor vrací `void`, přesto jeho vedlejším účinkem je inicializace instance a závislostí. U funkce bez návratové hodnoty je výstupem popsaná změna stavu nebo rozhraní.

## Historie

- **21. 11. 2026** – sjednocení pojmenování interních lokálních proměnných, doplnění legend a českých signatur pojmenovaných i anonymních funkcí; ponechání kompatibilních veřejných rozhraní.
- **22. 11. 2026** – kontrola sémantických typů, oprava shody deklarovaných a používaných vlastností `FastCollectorAccumulator`, zpřesnění vstupů a výstupů callbacků a zavedení regresního běhového testu sběrače.

Před každým vydáním se spouští `scripts/tests/check-code-style.py` a `scripts/tests/collector-accumulator.php`. První test ověřuje dokumentaci všech pojmenovaných PHP metod, hlavičky souborů a odkazy na deklarované vlastnosti; druhý ověřuje skutečný výsledek časové agregace, zápornou SPOT cenu a ochranu před zastaralými vzorky. Testy nenahrazují reálnou instalaci Nextcloudu ani Publisher CP21.

Vnější názvy API, Nextcloud rozhraní a databázových polí nejsou součástí maďarské notace; jejich názvy jsou popsány samostatně v [referenci API](08_API_A_DATOVA_POLE.md).
