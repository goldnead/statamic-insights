# Changelog

All notable changes to this addon are documented here.

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
