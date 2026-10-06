[🇨🇿 **Česky**](05_ANALYZA_ELEKTRINY.md) | [🇬🇧 English](../en/05_ELECTRICITY_ANALYSIS.md)

# Analýza elektřiny

## Části aplikace

Karta Analýza obsahuje **Reporty ED.G**, **Smlouvy a ceny**, **Nastavení HDO**, **Analýzu** a **Delta Green**. Administrátor může měnit společná data; ostatní přihlášení uživatelé je mohou prohlížet.

## Reporty EG.D

Vyberte nebo přetáhněte jeden či více měsíčních XLSX. Každý soubor se kontroluje samostatně: EAN, úplný kalendářní měsíc, pořadí čtvrthodin, numerické hodnoty a dostupné sloupce. Chyba jednoho souboru nezablokuje ostatní. Výsledek importu je uveden u souboru a uložené měsíce jsou v tabulce.

Podporovány jsou dvě struktury:

| Role | Jednotka | Význam |
| --- | --- | --- |
| ICQ2 / ISQ2 | kWh | Energie odběru / dodávky za čtvrthodinu |
| DCC1 / DSC1 | kW | Fakturační výkon odběru / dodávky; převod na kWh násobením 0,25 hodiny |

Jalový výkon ani ostatní výkonové sloupce se nepřebírají jako energie. EAN musí odpovídat uloženému odběrnému místu. Stejný soubor měření nezdvojí; opravný soubor za stejný měsíc nahradí jeho importované intervaly. Měření LINEA zůstávají oddělená.

**Otevřít detail** u měsíce zobrazí denní součty a původní čtvrthodinové hodnoty. Denní tabulka sčítá energii za každý den; druhá tabulka ukazuje jednotlivé intervaly vybraného dne. Obě tabulky lze sbalit. Vybraný měsíc současně určuje finanční souhrn v Analýze.

Zaškrtnuté měsíce exportujte jedním ZIPem: 1–24 reportů, nejvýše 25 MB dat. Obsahuje CSV s UTC i místním časem, metadata a dostupné původní XLSX. Nové importy uchovávají originál v neveřejném AppData Nextcloudu. Pokud originál u existujícího importu chybí, opětovné nahrání stejného souboru jej doplní bez zdvojení měření. Odstranění reportu smaže jeho importované intervaly a dostupný originál, nikoli měření LINEA nebo smlouvu.

## Smlouvy a ceny

Uložte údaje odběrného místa a smlouvy: dodavatel, produkt, začátek dodávky a délka v měsících. Konec smlouvy se dopočítá. Další smlouvu přidejte jako samostatný řádek, aby zůstala historie; období smluv se nesmí překrývat.

Každý cenový řádek má vazbu na smlouvu a datum účinnosti. Pokud datum ceny necháte prázdné, převezme se začátek zvolené smlouvy. Pozdější řádek ukončí předchozí cenové období. Celkové ceny zahrnující regulované položky platí nejdéle do konce roku jejich počátku.

Zvolte Kč/MWh nebo Kč/kWh. Zadejte celkovou cenu VT a NT včetně dodávky, distribuce, daní a DPH, stálé měsíční platby a případný volitelný zelený příplatek. Zelený příplatek se zadává samostatně a přičítá právě jednou při jeho zapnutí. Stálé platby jsou vždy Kč/měsíc. Používejte vlastní platný ceník; aplikace ceny poskytovatele automaticky nedoplňuje.

Profil se ukládá do nastavení aplikace `analysis_profile`. Načtení JSON pouze vyplní formulář; změny je nutné uložit.

## Nastavení HDO

Časy jsou **GMT+1 v zimě / GMT+2 v létě**; aplikace automaticky přepíná český zimní/letní čas. Každý řádek má podobu `od;do;okna NT`, například:

```text
2027-01-01;2027-12-31;00:00-09:00,10:00-12:00,13:00-16:00,17:00-20:00,21:00-24:00
```

Příklad pouze ukazuje formát. Zadejte skutečný rozpis pro své odběrné místo. U D57d se rozpis kontroluje na 20 hodin NT denně; neúplný rozpis se při výpočtu ignoruje a označí upozorněním.

Platný ruční rozpis má přednost. Bez něj lze využít historický vstup Shelly **Noční proud**, typ input, kanál 0. Polaritě ON/OFF aplikace přiřadí NT/VT až po téměř úplném dni potvrzujícím poměr přibližně 20/4 hodin. Aktuální stav sám polaritu nepotvrzuje. Chybějící historické časy zůstávají neznámé.

## Výsledky analýzy

Vyberte importovaný měsíc. Souhrn zobrazuje odběr a dodávku EG.D, energie VT/NT, proměnnou cenu, stálé platby a odhad celkové ceny. Pokud je smlouva nebo cena platná jen pro část měsíce, samostatně se uvede cena za dostupnou část. Neznámý tarif nebo cena se nepovažují za nulu.

Srovnání domu bez FVE používá zatížení domu z historie LINEA ve stejných časech. Je dostupné při dostatečném pokrytí. Rozdíl zahrnuje kombinovaný vliv FVE a baterie; nejde o samostatnou účinnost panelů. Výpočty jsou odhady, nikoli faktura dodavatele.

## Delta Green

Ručně uložte období od/do včetně, skutečnou celkovou platbu v Kč, případně EAN prodeje a číslo dokladu. EAN prodeje je oddělený od EAN odběru. Řádky lze upravit a odstranit. Shodné období a EAN se nesmějí zdvojit; různá překrývající se období se automaticky nesčítají. Maximálně 600 záznamů, jeden nejvýše 367 kalendářních dnů. Údaje jsou v nastavení `sale_statements`.

Hrubý SPOT a rozdíl proti platbě vyžadují alespoň 99,5% pokrytí každého dne a ocenění celé dodávky. Jinak se zobrazuje —. Dodávka EG.D se uvede, pokud existují všechny čtvrthodiny období. Rozdíl může zahrnovat poplatky nebo podmínky výkupu; VT/NT se u prodeje nerozlišuje.

Importované intervaly jsou v tabulkách `hc_gridsight_edg_reports` a `hc_gridsight_edg_intervals`, s prefixem databáze Nextcloudu. Uchovávají se pro opětovný výpočet a kontrolu. Neprovádí se jejich automatické nahrazení nebo smazání podle historie LINEA.
