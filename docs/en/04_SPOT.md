[🇨🇿 Česky](../cz/04_SPOT.md) | [🇬🇧 **English**](04_SPOT.md)

# Daily export and SPOT

Positive `energy.grid.powerW` means grid import; negative power means export. The collector integrates each valid power sample over its duration. For exports, it multiplies that energy by `spot.currentPrice` at the time of the sample, then adds the amounts. It does not apply the latest price to a whole day's exported energy. Negative SPOT prices are retained.

Energy and the monetary sum are stored after a five-minute interval completes. The chart price is stored separately by UTC hour in `hc_gridsight_spot_hour`. The account in `hc_gridsight_daily` follows the `Europe/Prague` local calendar day, resets each day and is calculated from stored intervals so a repeated write cannot double count. The SPOT card shows the gross measured export value, not a final invoiced amount.

When a price is missing, exported energy is still recorded but is not assigned a zero price. The complete daily amount then shows “—”. Total imported energy is also stored. The live endpoint still returns `purchaseCzk: null`; the separate [monthly electricity analysis](05_ELECTRICITY_ANALYSIS.md) uses ED.G imports and entered prices and HDO schedules. The unverified Shelly “night tariff” polarity is not assumed.

The hourly SPOT chart shows `ess.settings.spotThresholdPrice` as a labeled horizontal line. Prices at or above it are green, prices below are red; the zero axis remains distinct. Adjacent hours are joined by a vertical step. When the price crosses the threshold, the connector changes color at the threshold. A missing hour is left as a gap.

The read-only `/apps/hc_gridsight/api/history/daily` endpoint returns the day, time zone, imported and exported kWh, complete `saleCzk` or `null`, the priced portion `pricedSaleCzk`, unpriced export and measurement coverage.
