# Changelog

All notable changes to this addon are documented here.

## 1.1.0 — 2026-08-29

Insights stops being a revenue report with a screen and becomes what it was
meant to be: **reporting for the family, where any addon contributes a number.**

### Added

- **A metric contract.** `Metric` plus the optional `HasBreakdowns` and
  `HasFilterOptions`. An addon registers what it can count; this addon owns the
  period, the comparison against the period before, the chart, the formatting
  and the screens. See the README.
- **`Insights::registerMetric()`**, a registry and a reader around it. Failures
  are contained per metric: a contributor mid-upgrade costs its own tile, never
  the page.
- **Two generic screens.** *Metrics* lists everything registered, grouped by
  contributor; each has a detail view with a chart and any splits it offers.
  A number contributed tomorrow appears without a line of this addon changing.
- **`statamic-payments` 1.14 registers seven metrics** — gross, net, refunded,
  orders, buyers, average order, refund rate — with splits by campaign, source,
  product and country.

### Changed

- **The revenue screen is now assembled from registered metrics.** It looks and
  counts exactly as before; what changed is that the queries live in the addon
  that owns the data. Verified against the previous implementation figure for
  figure.
- The empty state distinguishes "no addon reports revenue" from "nothing sold
  yet", and derives the second from the number rather than from an optional
  interface.
- A chart is drawn only when there is more than one bucket. One bar is not a
  chart; it is the number above it, stretched.
- Negative buckets are drawn downwards and in the danger colour. Drawn upwards
  they were indistinguishable from a small positive day.

### Removed

- **`Support\RevenueReport`.** It read `payments` tables directly, which the
  ecosystem plan forbids for an analytics addon and which meant two places
  computed the same money. Undocumented and one day old; its arithmetic moved
  to `statamic-payments`, where it is tested against the real tables.

## 1.0.1 — 2026-08-29

### Added

- `docs/reading-the-numbers.md` — which question each figure answers, and the
  three that are answered differently than a reader might expect: a refund
  counts on the day the money went back, two currencies are never summed, and a
  sale with no campaign is grouped rather than dropped.
- Listing material: addon icon, cover, four Control Panel screenshots.

## 1.0.0 — 2026-08-29

### Added

- **The revenue screen.** One Control Panel page under Tools, reading what
  `statamic-payments` already records: net revenue, what was paid, orders,
  average order and the same figures for the period before.
- **By campaign.** What each `utm_campaign` sold, with the source beside it.
  Sales without a campaign are grouped, never dropped.
- **By product.** Over line items, so an order bump is credited to itself and
  not to the product it was attached to. Names resolve through the payments
  catalogue, so a product sold through an offer keeps its name.
- **Over time.** Every bucket in the range, including the empty ones — a chart
  built only from the days that had sales draws a bad month as a good one.
- Periods: 7 days, 30 days, 90 days, 12 months, year to date, all time.
- **Revenue on the CRM contact screen** — lifetime revenue, purchases, refunds
  and the first and last purchase, contributed through LeadHub's panel registry.
- `payments:leadhub-backfill`'s counterpart on the reading side: everything here
  works from the columns the payments addon already writes, with no migration
  of its own.
- A currency switch. Two currencies are never added together; the ones left out
  of the figure are named on the screen.
