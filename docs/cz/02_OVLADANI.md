[🇨🇿 **Česky**](02_OVLADANI.md) | [🇬🇧 English](../en/02_USAGE.md)

# Ovládání GridSight

<img width="2134" height="1153" alt="image" src="https://github.com/user-attachments/assets/6cae686b-1849-446d-9b7d-cb213ddca6f2" />

- **LIVE:** aktuální FVE, spotřeba domu, tok do sítě a ze sítě, baterie, SPOT, klimatizace, UPS a další čidla. Kladný výkon sítě znamená odběr, záporný dodávku. Na kartě Baterie údaj „Rack“ zobrazuje teplotu racku, nikoli odhad teploty akumulátoru.
- **HISTORIE:** měřené energie a časový graf výkonů, SOC, SPOT ceny, předpovědi a dostupných teplot. Zvolte období; jednotlivé křivky lze přepínat legendou. Přepínání křivek nemění měřítko daného výběru. Klepnutím vyberete čas a hodnoty; tažením myší přiblížíte interval.
- **ESS:** stav a nastavení řízení publikované LINEA. GridSight je pouze zobrazuje.
- **Sběr:** zdraví sběrače, poslední zápis a stav databáze.
- **Události:** posledních 100 zaznamenaných událostí.
- **ANALÝZA:** reporty EG.D a jejich detaily, smlouvy, ceny, HDO, finanční souhrny a vyúčtování Delta Green.
- **Nastavení:** LINEA URL, spojení, obnovování a osobní výběr křivek v historii. Vypnutí křivky v nastavení nevypne její měření.

Karta **Spotřeba domu** v LIVE zobrazuje teploty měničů L1, L2 a L3 přímo z aktuální odpovědi LINEA, pokud je zdroj poskytuje. Historický graf čte zvlášť uložené pětiminutové bloky. Proto může LIVE ukazovat teplotu, zatímco historie pro vybrané období ještě nemá žádný teplotní záznam. V legendě jsou jen křivky s historickými daty; starší záznamy se nedopočítávají. Samostatné pole teploty baterie schéma `1` nedefinuje a GridSight nepovažuje Rack za teplotu článků baterie. „—“ značí nedostupný údaj. Nula může být platná hodnota, ale LINEA umí některé chybějící vstupy převést na nulu, takže sama o sobě nepotvrzuje fyzické měření.

Rozvržení využívá Shared App Core. Na telefonu lze po přiblížení posouvat obsah a graf; velikost samotného grafu se přizpůsobuje dostupné šířce. Tlačítko **Obnovit** v horní liště obnoví aktivní kartu.

Období historie: 1 h, 6 h, 24 h, 7 dní, 30 dní, dnes, včera, poslední 3/6/12 měsíců, tento půlrok, tento rok, poslední dva roky a vše. Tento rok začíná 1. ledna; posledních 12 měsíců sahá od aktuálního data zpět. Tento půlrok začíná 1. ledna nebo 1. července. Kalendářní hranice používají GMT+1 v zimě / GMT+2 v létě.
