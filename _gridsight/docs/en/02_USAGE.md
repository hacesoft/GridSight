[🇨🇿 Česky](../cz/02_OVLADANI.md) | [🇬🇧 **English**](02_USAGE.md)

# Using GridSight

<img width="2134" height="1153" alt="image" src="https://github.com/user-attachments/assets/6cae686b-1849-446d-9b7d-cb213ddca6f2" />

- **LIVE:** current PV generation, house consumption, grid flow, battery, SPOT, climate, UPS and other sensors. Positive grid power means import; negative means export. The Battery card's “Rack” value is the rack temperature, not an inferred battery temperature.
- **HISTORY:** measured energy and time charts for power, SOC, SPOT price, forecasts and available temperatures. Select a range and toggle individual series in the legend. Toggling a series leaves the range's scale fixed. Tap to select a time and inspect values; drag with a mouse to zoom.
- **ESS:** LINEA's published control state and settings. GridSight only displays them.
- **Collection:** collector health, last write and database state.
- **Event log:** the latest 100 recorded events.
- **ANALYTICS:** outline of planned functions; this tab does not yet present calculated results.
- **Settings:** LINEA URL, connection, refresh interval and per-user selection of visible history series. Hiding a series in Settings does not stop collecting it.

The **House consumption** card in LIVE shows L1, L2 and L3 inverter temperatures from the current LINEA response when the source provides them. The history chart reads separately stored five-minute intervals. LIVE can therefore show a temperature while the selected historical range has no recorded temperature yet. The legend shows only curves with historical data; old records are not filled in. Schema `1` does not define a separate battery temperature field, so GridSight does not present Rack as the battery cell temperature. “—” means unavailable data. Zero can be a valid value, but LINEA may convert some missing inputs to zero, so it does not prove a physical measurement by itself.

The layout uses Shared App Core. On a phone, zoomed content and charts can be panned; chart size adapts to available width. **Refresh** in the top toolbar reloads the active tab.
