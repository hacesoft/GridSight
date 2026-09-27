[🇨🇿 Česky](../cz/04_SPOT.md) | [🇬🇧 **English**](04_SPOT.md)

# Daily export and SPOT

Positive `energy.grid.powerW` means grid import; negative power means export. The collector integrates each valid power sample over its duration. For exports, it multiplies that energy by `spot.currentPrice` at the time of the sample, then adds the amounts. It does not apply the latest price to a whole day's exported energy. Negative SPOT prices are retained.

Energy and the monetary sum are stored after a five-minute interval completes. The chart price is stored separately by UTC hour in `hc_gridsight_spot_hour`. The account in `hc_gridsight_daily` follows the `Europe/Prague` local calendar day, resets each day and is calculated from stored intervals so a repeated write cannot double count. The SPOT card shows the gross measured export value, not a final invoiced amount.

When a price is missing, exported energy is still recorded but is not assigned a zero price. The complete daily amount then shows “—”. Total imported energy is also stored. Purchase cost and high/low tariff allocation are not yet calculated because the Shelly “night tariff” state and purchase prices have not been confirmed; the API returns `purchaseCzk: null`.

The read-only `/apps/hc_gridsight/api/history/daily` endpoint returns the day, time zone, imported and exported kWh, complete `saleCzk` or `null`, the priced portion `pricedSaleCzk`, unpriced export and measurement coverage.
