# Statamic Marketplace Copy

Source material for the Statamic Marketplace listing. Not shipped with the
package (`export-ignore`).

---

## Title

**Statamic Insights**

## Tagline / Short Description

Revenue reporting for Statamic: which campaign sold anything, which product earned it, and what came back — read from the payments your checkout already records.

## Long Description

Statamic Insights turns the rows your store already writes into the one screen nobody had: a revenue report inside the Control Panel, in the Control Panel's own design, with no export and no external analytics account.

It reads [Statamic Payments](https://github.com/goldnead/statamic-payments) directly. Net revenue, what was paid, orders and average order — each beside the same figure for the period before, because a revenue number on its own says nothing. Below that, three answers: revenue over time, revenue by campaign, revenue by product.

The campaign is the point. Statamic Payments freezes `utm_source`, `utm_medium`, `utm_campaign`, `referrer` and `landing_page` on the payment at the moment of checkout — the only moment they still exist. Insights groups by them, so "which newsletter actually sold something" is a screen and not a spreadsheet exercise.

With [LeadHub](https://github.com/goldnead/statamic-leadhub) installed, every contact who has paid gets a **Revenue** panel on their own screen: lifetime revenue, purchases, refunds, first and last purchase. The numbers are the CRM's own ledger; Insights arranges them where somebody is already looking.

Three decisions shape every figure, and the screen states them rather than leaving them to be discovered. Sales count on the day they were paid and refunds on the day the money went back — so a period can carry a refund for a sale it never contained, and the screen says so instead of showing a negative number with no explanation. Two currencies are never added together: the report shows one and names the ones it left out. And a payment with no campaign is grouped under *no campaign*, never dropped, because a report that quietly excludes rows is the hardest kind of wrong to notice.

## Positioning Sentence

Your store already knows what it earned and where it came from. Insights is the screen that says so — without an export, an external dashboard, or a second copy of your customer data.

## Key Features

- Revenue screen in the Control Panel's own design (Inertia + Vue 3 + native `@statamic/cms` UI)
- Net revenue, gross, orders, distinct buyers and average order — each with the previous period beside it
- Revenue by campaign, from the UTM values the checkout froze at purchase time
- Revenue by product, split across line items so an order bump is credited to itself
- Revenue over time, one bar per day (or per month over long ranges), including the quiet days
- Periods: 7 days, 30 days, 90 days, 12 months, year to date, all time — shareable in the URL
- Multi-currency aware: one currency at a time, the others named rather than silently summed
- Refund total and refund rate, counted on the day the money went back
- Lifetime revenue per contact on the LeadHub contact screen
- Command-palette entries for every period
- Light and dark, German and English, its own `view insights` permission
- No migrations, no new tables, no second copy of anything

## Who It's For

- Statamic sites selling through Statamic Payments that want to know what a campaign earned
- Anyone who has been exporting orders to a spreadsheet once a month
- Agencies standardising on the goldnead addon family (Payments, LeadHub, Marketing, Automations)

## Requirements

- PHP 8.2+
- Statamic 6.0+
- `goldnead/statamic-payments` — the source of every number. Without it the screen says so and shows nothing.
- Optional: `goldnead/statamic-leadhub` for lifetime revenue per contact

## Screenshots

| File | Caption |
|---|---|
| `screenshots/01-revenue-overview.png` | The revenue screen: net, gross, orders and average order, each with the period before beside it, over a bar per day. |
| `screenshots/02-revenue-dark.png` | Dark mode, like the rest of the Control Panel. |
| `screenshots/03-attribution.png` | Which campaign sold anything, and which product earned it. Sales without a campaign are grouped, never dropped. |
| `screenshots/04-contact-revenue.png` | Lifetime revenue on the LeadHub contact screen, contributed through the CRM's own panel registry. |

## Art

| File | Use |
|---|---|
| `art/icon.svg` / `art/icon.png` | 512×512 addon icon |
| `art/cover.html` / `art/cover.png` | 1200×630 listing cover |
