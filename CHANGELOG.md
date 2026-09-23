# Changelog

All notable changes to this addon are documented here.

## Unreleased

### Added: subscription figures

A new screen, **Insights → Subscriptions**, and four reports in a new "Subscriptions" group, read
from the `subscriptions` table of statamic-payments and the cycles charged on it:

- MRR and ARR, running subscriptions, new subscriptions, customer churn and revenue churn per
  month, the charges due in the next 30 days and a twelve-month forecast, with MRR over time.
- **MRR movements by month**: start, new, reactivated, expansion, contraction, churned, paused,
  net new MRR, end. Summed month by month, so a price change of somebody who joined inside a long
  window is still an expansion.
- **Retention by start month**: the share of each cohort still there after 1, 2, 3, 6 and 12
  months.
- **Charges due in the next 30 days** and **Revenue forecast from running subscriptions**.

The rules: a quarterly cycle counts a third, a yearly one a twelfth. Payment plans and trials are
not MRR; plans are their own line in charges and forecast. Currencies are never added — every
figure is per currency, with a switch on the screen, because the suite has no exchange rate.
A paused subscription, one suspended after a failed payment, and one in a status this addon does
not know are *held*: their own movement, counted as retained, never churn; an unknown status is
named on screen. The price at a moment is what the latest renewal charged, not the checkout,
which may carry a bump or a setup fee; a charge marked `meta.proration` is ignored.

The database only selects; every date is compared in PHP, so the figures are the same on SQLite,
MySQL and Postgres — the new tests were run against all three (`INSIGHTS_TEST_DB_URL`).
No contract changed; the reports use the existing `Report` contract. No migration, no new config key.

## 1.4.0 — 2026-09-16

### Added: the website's own traffic

Until now every figure came from a sibling addon, and the seam for measuring something directly
was an empty method with a note saying the day it filled would be the day this addon needed
another addon's table again. That is not what happened. The website's readers are the one thing
no addon in the family records and none ever will, and a site's traffic belongs beside its
revenue rather than in a second browser tab.

Six figures — visitors, sessions, pageviews, bounce rate, pages per session, session length —
and five tables: most read pages, referrers, countries, devices, browsers. They come from a
self-hosted [Rybbit](https://rybbit.io) instance, read through one client class that is the only
place in this addon talking to a network.

Configuration is environment only, and nothing appears anywhere until it is set:

```dotenv
STATAMIC_INSIGHTS_WEBSITE_URL=https://analytics.example
STATAMIC_INSIGHTS_WEBSITE_KEY=rb_…
STATAMIC_INSIGHTS_WEBSITE_SITE=7
```

An unconfigured installation registers no website metric and no website table at all. A heading
over nothing would promise a source that is not there, and the "not installed, run `composer
require`" sentence the screens show for a missing sibling is not true of a web service.

Three things the API does that cost an afternoon each are written into the client rather than
left for the next reader: `?past=7d` is accepted and ignored, an explicit date range is silently
dropped unless `time_zone` travels with it, and the bucketed endpoint answers an unbounded
window with rows that have no timestamp. The rate figures answer `null` rather than `0` in a
window with no sessions, following the house rule the metric contract already sets.

The same rule twice more, because a figure read over a network has two extra ways of turning
into a flattering nought. A figure the service did not send at all answers `null` rather than
`0` — `0,0 %` beside twenty sessions is the most flattering wrong answer there is. And an answer
of `200` carrying something that is not an answer — a login page from an access proxy, say —
raises rather than decoding to six zeroes: "nobody asked successfully" and "nobody visited" have
the same shape on a screen and only one of them is a measurement.

### Added: a report may say how it wants to be sorted

New optional `HasDefaultSort`, the sibling of `HasBreakdowns` for tables. The listing sorts
client-side and, left alone, sorts by the first column — right for revenue by month, wrong for
the twenty busiest pages, which read alphabetically as a complete site with twenty entries. The
rows a ranking cut are invisible as well as absent.

`Report` itself is unchanged, so nothing outside this package has to do anything.

### Changed

- `PaymentsByCountry` and the new countries table spell out a country code through the same
  `Support\Countries`, instead of two copies that drift.

## 1.3.0 — 2026-09-07

### Changed: the metrics are a table, not a card grid

Every metric was a card with a heading, a figure, the change and two or three lines of
explanation. Five metrics filled a screen that way, and anyone with ten scrolled through
sections instead of having an overview. On top of that, the Statamic Control Panel has no card
grid for figures at all: it has widgets on the dashboard and tables everywhere else.

It is now one row per metric in `Listing`, the table that also draws the entries view: metric,
source, value, change, series. It opens sorted by source, so the grouping by addon survives that
previously came from one panel each. The explanation sits on the detail page, which already
existed and which the same click opens as the card did before, period included.

The empty state for "no addon reports a figure" is unchanged.

`MetricReader::overview()` therefore returns the series (`series`) per metric as well — the
series column needs it, and the detail page had asked the same question before anyway. That
costs one query per metric on this screen.

### Added: the two settings are in the Control Panel

`default_period` and `currency` were reachable only through `config/statamic-insights.php`. Both
now sit under *Addon settings* (`/cp/brand-settings`), per brand, through the shared layer from
`goldnead/statamic-brand-context` — no controller of its own, no page of its own, no table of
its own. Whatever is not changed there keeps following the config file.

Both values are read at request time in a controller; a key that is read at boot does not belong
on this page, because the overrides only take effect afterwards.

New permission: `manage insights settings`. Existing permissions are unchanged.

`goldnead/statamic-brand-context` ^1.12 is therefore a real dependency of this addon.

## 1.2.2 — 2026-09-05

### Fixed: a split with tied figures came back in whatever order the driver felt like

`splitByColumn()` sorted by the figure alone. For rows that **tie** that promises nothing, and
the database decides — differently per driver. The same two rows came back one way on SQLite and
the other way on MySQL. On screen that is a list which reshuffles for no reason; in a suite it is
green on the laptop and red on CI, which is where it surfaced (`statamic-events`, 2026-09-05).
Small counts, fresh installs and quiet weeks produce ties constantly, so the case is not rare.

There is now a second sort key: the column itself. At the cut-off it is more than cosmetic — with
a tie sitting exactly where `$limit` cuts, it used to depend on the driver **which** row came back
at all, not merely in what order.

This is the same lesson as 1.2.1 one method over. `bucketExpression()` learned it for series
back then; the split never had it.

Thirteen addons carry `TableMetric` as a byte-for-byte copy under `tests/Fakes/`. All of them
have been brought along.

## 1.2.1 — 2026-09-05

### Fixed: series buckets come back in order on MySQL

`TableMetric::bucketed()` grouped by the bucket and never ordered by it. `GROUP BY`
promises no order; SQLite happens to return the groups sorted, MySQL 8 returns them in the
order it met the rows. A series built on MySQL could therefore arrive with its days out of
sequence, and every addon whose metrics extend `TableMetric` inherited that. The query now
carries an explicit `ORDER BY bucket`; a test inserts the rows newest-first and asserts the
keys ascend.

Addons that keep a verbatim copy of `TableMetric` in their test suite
(`tests/Fakes/insights-table-metric.php`) need to copy this version across.

## 1.2.0 — 2026-09-02

### Added: Reports, a third screen

Tables, where the metrics are numbers. A metric with a breakdown carries one value per
row; "revenue per month with the order count and the average beside it" is three. So
there is now a `Report` contract next to `Metric` — handle, label, group, columns,
rows, `usesPeriod()` — with its own registry (`Insights::registerReport()`), reader and
two screens under **Tools → Insights → Reports**. Same period picker, same failure
containment: a report whose `rows()` throws costs its own table and a line in the log,
never the page.

One difference from metrics, on purpose: a report whose source is missing is **not
hidden**. It stays on the list with a badge and opens to a sentence naming the package it
would need. A list of reports doubles as a list of what the suite can tell you.

### Added: six reports of its own

This is the day the empty `registerOwnMetrics()` seam warned about, and it happens
visibly. Insights now reads the tables of three siblings directly, each read behind a
class-existence and table-existence probe (`Support\Neighbours`):

| Report | Reads | Rows |
| --- | --- | --- |
| Revenue by month | `payments` | month × currency: gross, count, average |
| Revenue by product | `payment_items` ⋈ `payments` | product × currency: sold, orders, gross |
| Payments by country | `payments.country` | country × currency: count, gross; unknown kept |
| Cart abandonment | `payments.created_at`, `status` | month: paid, open+expired, rate over the cohort |
| Order bumps and post-purchase offers | `offers`, plus `payment_items` when payments is there | offer: shown, accepted, conversion, revenue by product and slot |
| Active access by product | `entitlements` | slug: active, in grace, expired — a snapshot |

Taken because these questions span several addons' tables at once and no single sibling
is the natural owner of "upsell revenue". The neighbours' own `TableMetric` is left
untouched — four sibling suites pin its source — so the reports carry a transcription of
its brand narrowing and month bucketing in two traits under `Support\Concerns`, to be
kept in step by hand until a coordinated release lets `TableMetric` use them.

Nothing in the public contract layer changed: `Metric`, `HasBreakdowns`,
`HasFilterOptions`, `MetricQuery`, `Period`, `Unit` and `TableMetric` are byte-identical.

## 1.1.1 — 2026-08-29

Documentation only, no code difference to 1.1.0. Two things that ship in the
package and were not yet in 1.1.0: the note at the top that
`php artisan cache:clear` has to run once after the upgrade, and a promise in
`docs/reading-the-numbers.md` that the addon cannot keep and that was therefore
withdrawn.

A patch version of its own, because 1.1.0 had already been published at that
point. A published tag is not moved.

## 1.1.0 — 2026-08-29

Insights stops being a revenue report with a screen and becomes what it was
meant to be: **reporting for the family, where any addon contributes a number.**

### Upgrading from 1.0

Run `php artisan cache:clear` once. This release adds a second page under the
Insights nav item, and Statamic caches the list of URLs its navigation knows
about — until that cache is rebuilt, the breadcrumb on the new *Metrics* screen
still reads *Revenue* while the heading and the sidebar read *Metrics*. Nothing
is wrong with the figures.

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
- The contract states two house rules it had left to each contributor: what
  `available()` may answer, and how a rate is counted. Existing departures stay
  allowed, but have to be named in the metric's own `description()`.
- `suggest` lists all fourteen contributing addons rather than two.

### Fixed

- **Figures crossed brand boundaries.** On a multi-brand install with a brand
  selected, several tiles summed every brand — one of them showing four
  invoices belonging to three other brands while the switcher said a fourth.
  The rule now lives once, in `TableMetric::brandScoped()`, transcribed from
  `statamic-brand-context`'s own scope; a contributor declares `brandColumn()`
  and the figure, the chart and every split narrow together.
- **An unresolved brand removed metrics from the screen** instead of reading
  zero. `available()` answers whether a metric exists; a brand nobody has picked
  is not the metric ceasing to exist.
- **The clamp on "now" read the wrong clock.** `untilNow()` compared against the
  application's timezone, so a column stored in UTC lost the newest hours —
  five of them on a US host, and none at all on a UTC site, which is why
  whoever wrote the metric never saw it. A metric names its column's zone with
  `zone()` now; two addons had answered it by restating `untilNow()`, which is
  how the three earlier defects in that class reached only half the family.
- **Groups appeared in service-provider boot order**, so installing any addon
  reshuffled every other addon's section. They are sorted by heading now.

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
