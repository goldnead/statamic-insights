# Reading the numbers

Every figure on the revenue screen is the answer to a question that could have
been asked three different ways. This page says which way was chosen, and why —
so that a number which looks surprising can be checked rather than distrusted.

## A sale counts on the day it was paid

Not the day the checkout started, not the day the order was fulfilled. The
payment's `paid_at`, which is the moment the provider confirmed the money.

## A refund counts on the day it went back

This is the one that surprises people, so it is worth the paragraph.

A refund in March of a January sale belongs to **March**. The alternative —
crediting it against the sale — means January's closed figure changes after the
fact, and a reported number that moves after it was read is worse than one that
is merely inconvenient.

The consequence is real and the screen states it: a period can carry a refund
for a sale it never contained, and net revenue can fall below zero. When it
does, the screen says why rather than showing a negative figure and leaving you
to work it out.

And when a period took nothing in but something went back, there is **no refund
rate at all**. A percentage against zero is not a small number, it is a question
that does not apply; printing "0 %" beside a refund amount would be a statement
contradicted by the figure next to it.

## One currency at a time

Adding 100 EUR to 100 CHF produces a number with no meaning. The report filters
to a single currency and puts a badge on screen naming the ones it left out. It
never sums across them, and it never picks silently: the switch appears as soon
as a second currency has ever been taken.

## A sale with no campaign is grouped, not dropped

It appears as *no campaign*. This matters more than it sounds: a report that
quietly excludes rows is the hardest kind of wrong to notice, because the totals
and the table disagree and nothing says why.

The same rule applies to products. A payment written before line items existed —
imported, entered by hand, left over from an older catalogue — falls back to its
own product handle rather than vanishing.

## Products are split across line items

An order bump and the product it was attached to are one payment and two
products. Crediting the whole amount to the first would overstate one and hide
the other, so the split comes from `payment_items`:

```
amount_cent × quantity − discount_cent
```

`discount_cent` is the share of the payment's discount that fell on that line,
distributed proportionally by the checkout. The parts add up to the payment.

**When they do not**, the screen says so. Line items written past the checkout —
by an importer, a seeder, a migration — can leave the sum of the products
different from what was actually charged. The payment's own amount is
authoritative; the note names the difference rather than showing two totals and
letting you guess which is real.

## The lists are gross

By campaign and by product show what was **paid**, before refunds. A refund is
recorded against a payment, not against the line that earned it, so subtracting
it per campaign or per product would be an invention. The refund total sits
above, against the whole period.

## Percentages are shares of the total

Not of the largest row. Both lists are capped at twenty entries; a percentage of
the rows on screen would quietly grow too large the moment a twenty-first
campaign existed, while the label still promised a share of the whole.

Anything above zero but below half a percent shows as `<1%`, because "0 %" for a
row that earned something is the wrong answer in the direction that matters.

## The chart has a bar for every day

Including the days that earned nothing — those get no bar at all, not a short
one. A chart built only from the days with sales draws a bad month as a good
one, and a floor under the empty days draws revenue that never happened.

Over ranges longer than about three months the bars become months, or the chart
would have three hundred columns.

## Where the campaign comes from

`statamic-payments` freezes the UTM values on the payment at the checkout. The
host hands them in; the addon reads no request and invents nothing.

A visitor arrives from a newsletter, browses for three days and buys. By the
time the money lands, the campaign lives nowhere but in that session — read from
the success redirect it is already gone. That is why the freeze happens at the
start of the checkout and not at the end, and why a payment taken before those
columns existed reports under *no campaign*: the honest answer, rather than a
guess.
