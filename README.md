# Statamic Insights

Reports over what the addon family already records. One screen today: **Revenue**.

> Its single question: which campaign sold anything, and what did it earn?

## Requirements

| | |
|---|---|
| PHP | 8.2+ |
| Statamic | 6.0+ |
| Data source | [`goldnead/statamic-payments`](https://github.com/goldnead/statamic-payments) — without it the screen says so and shows nothing |
| Optional | `goldnead/statamic-leadhub` — lifetime revenue per contact |

## Installation

```bash
composer require goldnead/statamic-insights
```

That is the whole of it. The screen appears under **Tools → Insights**, gated by
a `view insights` permission that is registered with the addon.

Configuration is optional:

```bash
php please vendor:publish --tag=statamic-insights-config
```

```dotenv
STATAMIC_INSIGHTS_CURRENCY=EUR   # which currency the screen opens on
STATAMIC_INSIGHTS_PERIOD=30d     # 7d · 30d · 90d · 12m · ytd · all
```

## Usage

Open **Tools → Insights**. The screen answers four questions at a glance and two
in detail:

| | |
|---|---|
| **Net revenue** | What was paid, minus what went back, in the chosen period |
| **Paid** | What came in, before refunds |
| **Orders** | How many, and how many distinct buyers behind them |
| **Average order** | Paid divided by orders |

Each carries the same figure for the period immediately before it, because a
revenue number on its own says nothing.

Below that: **Over time**, one bar per day (or per month over a long range);
**By campaign**, which is the question this addon exists for; and **By product**,
split across line items so an order bump is credited to itself.

The period and currency live in the query string — `?period=90d&currency=CHF` —
so a view can be shared or bookmarked.

## What the numbers mean

Three decisions shape every figure, and they are stated here rather than left
to be discovered:

**Sales count on the day they were paid. Refunds count on the day they went
back.** A refund in March of a January sale belongs to March, because that is
when the money left. It means a period can show a refund against a sale it never
contained — which is the cash view a person actually asks for, and it is said on
the screen instead of hidden.

**One currency at a time.** Adding 100 EUR to 100 CHF produces a number with no
meaning. The report filters to one currency and names the others it left out.

**Missing is missing.** A payment with no campaign is grouped under *no
campaign*, never dropped. A report that quietly excludes rows is the hardest
kind of wrong to notice: the total and the table disagree and nothing says why.

## Where the campaign comes from

`statamic-payments` freezes `utm_source`, `utm_medium`, `utm_campaign`,
`utm_term`, `utm_content`, `referrer` and `landing_page` on the payment at the
checkout. The host hands them in — see that addon's README. A payment taken
before those columns existed reports under *no campaign*, which is the honest
answer rather than a guess.

## On the contact screen

With `goldnead/statamic-leadhub` installed, every contact who has paid gets a
**Revenue** panel on their own screen: lifetime revenue, how many purchases,
what went back, and the first and last one.

The numbers are the CRM's — it keeps the ledger. This addon asks for them and
arranges them; nothing here computes money. The panel is contributed through
LeadHub's own registry rather than read from its side, because the CRM requires
nobody and one panel must not change that. A contact who has never paid gets no
panel at all: an empty card on every screen is noise that hides the ones with
numbers.

## Permissions

| Permission | What it opens |
|---|---|
| `view insights` | The revenue screen and its navigation entry |

## Development

```bash
composer install     # must run first: @statamic/cms is a file: dependency
npm install
npm run build        # writes resources/dist/build, which is committed
composer test
```

## Screenshots

![The revenue screen](screenshots/01-revenue-overview.png)

More in `screenshots/`, captioned in `MARKETPLACE.md`.

## Further reading

- [`docs/reading-the-numbers.md`](docs/reading-the-numbers.md) — which question each
  figure answers, and the three that are answered differently than you might expect.

## Licence

Proprietary. See `LICENSE.md`.
