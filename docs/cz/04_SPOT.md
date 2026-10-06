[🇨🇿 **Česky**](04_SPOT.md) | [🇬🇧 English](../en/04_SPOT.md)

# Denní dodávka a SPOT

Kladný výkon `energy.grid.powerW` znamená odběr ze sítě, záporný dodávku. Sběrač z výkonu a délky platného vzorku vypočítává energii. U dodávky násobí energii cenou `spot.currentPrice` platnou v okamžiku vzorku. Takto získané částky sčítá; neoceňuje celodenní dodávku poslední známou cenou. Zápornou SPOT cenu zachová.

Energie a peněžní součet se zapisují po dokončení pětiminutového bloku. Cena pro graf se uchovává samostatně po hodinách UTC v `hc_gridsight_spot_hour`. Denní účet v `hc_gridsight_daily` používá místní kalendářní den `Europe/Prague`, začíná každý den od nuly a vzniká součtem uložených bloků, takže opakovaný zápis nezapočítá data dvakrát. Hodnota v kartě SPOT je hrubá hodnota naměřené dodávky; nejde o konečnou fakturovanou částku.

Při chybějící ceně se dodaná energie eviduje, ale neoceňuje nulovou cenou. Úplná denní částka se pak zobrazuje jako „—“. Uložena je také celková odebraná energie. Živý endpoint dál vrací `purchaseCzk: null`; samostatná [měsíční analýza odběru](05_ANALYZA_ELEKTRINY.md) používá report EG.D a zadané ceny a časy HDO. Nepřevádí neověřený stav Shelly „noční proud“ na tarif.

Graf hodinového SPOTu vykresluje nastavený `ess.settings.spotThresholdPrice` jako pojmenovanou vodorovnou čáru. Ceny na prahu nebo nad ním jsou zelené, pod prahem červené; nulová osa zůstává odlišena. Sousední hodinové úseky spojuje svislý přechod; při překročení prahu se jeho barva změní přesně na čáře prahu. Pokud v datech chybí sousední hodina, spojnice přes mezeru nevznikne.

Čtecí endpoint `/apps/hc_gridsight/api/history/daily` vrací den, časové pásmo, odebranou a dodanou energii v kWh, úplnou tržbu `saleCzk` nebo `null`, oceněnou část `pricedSaleCzk`, neoceněnou dodávku a informaci o pokrytí měření.
