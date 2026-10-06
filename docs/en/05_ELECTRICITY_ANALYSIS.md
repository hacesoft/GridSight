[🇨🇿 Česky](../cz/05_ANALYZA_ELEKTRINY.md) | [🇬🇧 **English**](05_ELECTRICITY_ANALYSIS.md)

# Electricity analysis

## Views and access

Analysis contains **ED.G reports**, **Contracts and prices**, **HDO settings**, **Analysis** and **Delta Green**. Administrators can modify shared data; other signed-in users can view it.

## EG.D reports

Select or drop one or more monthly XLSX files. Each file is validated independently for EAN, a complete local calendar month, quarter-hour order, numerical values and supported columns. An invalid file does not block other files. Import outcomes are shown per file; stored months appear in the report table.

| Roles | Unit | Meaning |
| --- | --- | --- |
| ICQ2 / ISQ2 | kWh | Quarter-hour grid import / export energy |
| DCC1 / DSC1 | kW | Billed import / export power; convert to kWh by multiplying by 0.25 hours |

Reactive power and unrelated power columns are not treated as energy. Report EAN must match the connection. Identical files do not duplicate measurements; corrected files replace that month's imported intervals. LINEA history remains separate.

**Open detail** shows the selected month's daily totals and original quarter-hour values. Daily totals sum energy per day; the other table shows intervals for the selected day. Both tables can collapse. The selected report month also determines the financial summary in Analysis.

Export 1–24 selected months as one ZIP, up to 25 MB of data: normalized CSV with UTC/local timestamps, metadata and available originals. New imports retain original XLSX in private Nextcloud AppData. Reuploading an identical existing report attaches its missing original without duplicating measurements. Deletion removes imported intervals and the available original, not LINEA history or contracts.

## Contracts and prices

Save connection details, supplier, product, supply start and contract length in months. The end date is calculated. Add each new contract as a separate row; contract periods cannot overlap.

Each price row links to a contract and effective date. An empty price start inherits its selected contract start. A later price row ends the preceding price period. Combined prices including regulated components expire no later than the end of their start year.

Choose CZK/MWh or CZK/kWh. Enter combined VT/NT prices including supply, distribution, taxes and VAT; monthly fixed charges; and the optional green fee separately. That fee is added once when enabled. Fixed charges always use CZK/month. Use your actual valid tariff; provider prices are not populated automatically.

The profile persists in the `analysis_profile` app setting. Loading JSON only fills the form; save to apply it.

## HDO settings

Times use **GMT+1 in winter / GMT+2 in summer**, with automatic Czech daylight-saving handling. Each schedule line follows `from;to;NT windows`, for example:

```text
2027-01-01;2027-12-31;00:00-09:00,10:00-12:00,13:00-16:00,17:00-20:00,21:00-24:00
```

This is a format example only. Enter your connection's real schedule. D57d schedules must total 20 NT hours per day; incomplete schedules are ignored with a warning.

A valid manual schedule takes precedence. Otherwise historical Shelly input **Noční proud**, input channel 0, can provide tariff evidence. ON/OFF polarity is confirmed only after a nearly complete day demonstrates roughly 20/4-hour durations. A current state alone does not prove polarity. Missing historic readings remain unknown.

## Results

Select an imported month to view EG.D import/export, VT/NT energy, variable cost, fixed charges and estimated total. Prices valid for only part of a month produce a separate partial result. Unknown tariffs or prices are not treated as zero.

The no-PV comparison uses LINEA household load at the same times and requires sufficient coverage. The difference includes the combined effects of PV and batteries, not panel efficiency alone. Results are estimates, not provider invoices.

## Delta Green

Enter inclusive dates and actual total CZK payout, optionally sale EAN and document number. Sale EAN is separate from intake EAN. Rows can be edited/deleted. Exact period/EAN duplicates are rejected; different overlapping periods are not summed automatically. Up to 600 records are supported, each spanning at most 367 calendar days. They persist in `sale_statements`.

Gross SPOT revenue and its difference from the payout require >=99.5% coverage for every day and no unpriced export. Otherwise results show —. EG.D export appears when all period intervals are present. Fees and sale terms can explain the difference; VT/NT is not applied to sales.

Imported intervals remain in `hc_gridsight_edg_reports` and `hc_gridsight_edg_intervals` with Nextcloud's database prefix for repeatable calculation and audit. They are not automatically replaced or removed based on LINEA history.
