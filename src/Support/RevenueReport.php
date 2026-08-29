<?php

namespace Goldnead\StatamicInsights\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What was sold, to whom it can be credited, and what came back.
 *
 * Read straight from the payments tables with SQL aggregates, not through the
 * sibling's models. An aggregate is a read, not a call: hydrating ten thousand
 * payment rows to add up a column would be slower and no more correct. Product
 * *names* are the exception — those go through the catalogue, because a handle
 * only becomes a product there, and an offer's handle resolves nowhere else.
 *
 * Three decisions that shape every number below:
 *
 * 1. **Sales count on `paid_at`, refunds on `refunded_at`.** A refund in March
 *    of a January sale belongs to March, because that is the month the money
 *    left. It means "net" mixes this period's sales with this period's refunds,
 *    which is the cash view a person actually asks for — and it is said on the
 *    screen rather than left for somebody to discover.
 * 2. **One currency at a time.** Adding 100 EUR to 100 CHF produces a number
 *    with no meaning. The report filters to one and reports what it left out,
 *    rather than summing and being confidently wrong.
 * 3. **Missing is missing.** A payment with no campaign is grouped under "no
 *    campaign", never dropped. A report that quietly excludes rows is the
 *    hardest kind of wrong to notice.
 */
class RevenueReport
{
    public function __construct(
        protected Period $period,
        protected string $currency,
    ) {}

    public static function available(): bool
    {
        return Schema::hasTable('payments');
    }

    /** Currencies that have ever been taken, most orders first. */
    public static function currencies(): array
    {
        if (! self::available()) {
            return [];
        }

        return DB::table('payments')
            ->where('status', 'paid')
            ->whereNotNull('currency')
            ->groupBy('currency')
            ->orderByRaw('count(*) desc')
            ->pluck('currency')
            ->all();
    }

    /**
     * The headline numbers, each with the same figure for the period before.
     *
     * The comparison is the point. A revenue figure on its own says nothing —
     * every number in this row is only readable next to what it was.
     */
    public function totals(): array
    {
        $jetzt = $this->totalsFor($this->period);
        $davor = ($vorher = $this->period->previous()) ? $this->totalsFor($vorher) : null;

        return [
            'gross_cent' => $jetzt['gross_cent'],
            'refunded_cent' => $jetzt['refunded_cent'],
            'net_cent' => $jetzt['gross_cent'] - $jetzt['refunded_cent'],
            'orders' => $jetzt['orders'],
            'buyers' => $jetzt['buyers'],
            'average_cent' => $jetzt['orders'] > 0 ? intdiv($jetzt['gross_cent'], $jetzt['orders']) : 0,
            // Null, nicht null Prozent. Wurde im Zeitraum nichts eingenommen
            // und trotzdem etwas erstattet, ist jede Quote eine Falschaussage
            // direkt neben ihrem eigenen Gegenbeweis — der Bildschirm zeigt den
            // Betrag ja an. Kein Wert heisst hier: die Frage ergibt keinen Sinn.
            'refund_rate' => $jetzt['gross_cent'] > 0
                ? round($jetzt['refunded_cent'] / $jetzt['gross_cent'] * 100, 1)
                : null,
            'previous' => $davor === null ? null : [
                'gross_cent' => $davor['gross_cent'],
                'net_cent' => $davor['gross_cent'] - $davor['refunded_cent'],
                'orders' => $davor['orders'],
                'buyers' => $davor['buyers'],
                'average_cent' => $davor['orders'] > 0 ? intdiv($davor['gross_cent'], $davor['orders']) : 0,
            ],
        ];
    }

    /**
     * Which campaign sold what.
     *
     * The question this whole addon exists for. `utm_campaign` is frozen on the
     * payment at the checkout; a sale that carries none is grouped, not hidden.
     */
    public function byCampaign(int $limit = 20): array
    {
        if (! self::available()) {
            return [];
        }

        $zeilen = $this->paidInPeriod()
            ->selectRaw('utm_campaign, utm_source, count(*) as orders, sum(amount_cent) as gross_cent')
            ->groupBy('utm_campaign', 'utm_source')
            ->orderByRaw('sum(amount_cent) desc')
            ->limit($limit)
            ->get();

        return $zeilen->map(fn ($zeile) => [
            'campaign' => $zeile->utm_campaign ?: null,
            'source' => $zeile->utm_source ?: null,
            'orders' => (int) $zeile->orders,
            'gross_cent' => (int) $zeile->gross_cent,
        ])->all();
    }

    /**
     * Which product earned what.
     *
     * Over the line items, not the payment: an order bump and its main product
     * are one payment and two products, and crediting the whole amount to the
     * first would overstate one and hide the other. Payments written before
     * line items existed — or by something that does not use the checkout —
     * fall back to their own handle, so nothing is dropped.
     */
    public function byProduct(int $limit = 20): array
    {
        if (! self::available() || ! Schema::hasTable('payment_items')) {
            return [];
        }

        $positionen = DB::table('payment_items')
            ->join('payments', 'payments.id', '=', 'payment_items.payment_id')
            ->where('payments.status', 'paid')
            ->where('payments.currency', $this->currency)
            ->when($this->period->from, fn ($q) => $q->where('payments.paid_at', '>=', $this->period->from))
            ->when($this->period->to, fn ($q) => $q->where('payments.paid_at', '<=', $this->period->to))
            ->selectRaw('payment_items.product as handle, count(distinct payments.id) as orders, sum(payment_items.amount_cent * payment_items.quantity - payment_items.discount_cent) as gross_cent, sum(payment_items.quantity) as quantity')
            ->groupBy('payment_items.product')
            ->get();

        $ohnePositionen = $this->paidInPeriod()
            ->whereNotExists(fn (Builder $q) => $q->from('payment_items')->whereColumn('payment_items.payment_id', 'payments.id'))
            ->selectRaw('product as handle, count(*) as orders, sum(amount_cent) as gross_cent, count(*) as quantity')
            ->groupBy('product')
            ->get();

        $zusammen = [];

        foreach ($positionen->concat($ohnePositionen) as $zeile) {
            $handle = (string) $zeile->handle;

            $zusammen[$handle] ??= ['handle' => $handle, 'orders' => 0, 'gross_cent' => 0, 'quantity' => 0];
            $zusammen[$handle]['orders'] += (int) $zeile->orders;
            $zusammen[$handle]['gross_cent'] += (int) $zeile->gross_cent;
            $zusammen[$handle]['quantity'] += (int) $zeile->quantity;
        }

        usort($zusammen, fn ($a, $b) => $b['gross_cent'] <=> $a['gross_cent']);

        return array_map(
            fn (array $zeile) => $zeile + ['name' => $this->productName($zeile['handle'])],
            array_slice($zusammen, 0, $limit),
        );
    }

    /**
     * Revenue over time, one bucket per day or per month.
     *
     * Every bucket in the range is present, including the empty ones. A chart
     * built from only the days that had sales draws a line that skips the
     * quiet weeks and makes a bad month look like a good one.
     */
    public function overTime(): array
    {
        if (! self::available()) {
            return [];
        }

        if ($this->period->isOpenEnded()) {
            return $this->openEndedOverTime();
        }

        $monatlich = ($this->period->days() ?? 0) > 92;

        $gemessen = $this->paidInPeriod()
            ->selectRaw($this->bucketExpression($monatlich).' as bucket, sum(amount_cent) as gross_cent')
            ->groupBy('bucket')
            ->pluck('gross_cent', 'bucket')
            ->all();

        $eimer = [];
        $zeiger = $this->period->from->copy();

        while ($zeiger <= $this->period->to) {
            $key = $monatlich ? $zeiger->format('Y-m') : $zeiger->format('Y-m-d');
            $eimer[$key] = (int) ($gemessen[$key] ?? 0);
            $zeiger = $monatlich ? $zeiger->addMonth() : $zeiger->addDay();
        }

        return array_map(
            fn ($key, $wert) => ['bucket' => $key, 'gross_cent' => $wert],
            array_keys($eimer),
            $eimer,
        );
    }

    /**
     * What the product rows add up to.
     *
     * Reported separately because it can disagree with the payments' own total,
     * and when it does, the honest move is to say so rather than to show two
     * different sums on one screen and let a reader decide which to believe.
     * The payment's `amount_cent` is authoritative — it is what was charged;
     * the line items are a split of it, and a split that does not add up means
     * rows were written past the checkout.
     */
    public function productSumCent(): int
    {
        return array_sum(array_column($this->byProduct(1000), 'gross_cent'));
    }

    /** Currencies taken in this period that this report is not showing. */
    public function otherCurrencies(): array
    {
        if (! self::available()) {
            return [];
        }

        return DB::table('payments')
            ->where('status', 'paid')
            ->where('currency', '!=', $this->currency)
            ->when($this->period->from, fn ($q) => $q->where('paid_at', '>=', $this->period->from))
            ->when($this->period->to, fn ($q) => $q->where('paid_at', '<=', $this->period->to))
            ->groupBy('currency')
            ->pluck('currency')
            ->all();
    }

    protected function totalsFor(Period $period): array
    {
        if (! self::available()) {
            return ['gross_cent' => 0, 'refunded_cent' => 0, 'orders' => 0, 'buyers' => 0];
        }

        $verkauf = DB::table('payments')
            ->where('status', 'paid')
            ->where('currency', $this->currency)
            ->when($period->from, fn ($q) => $q->where('paid_at', '>=', $period->from))
            ->when($period->to, fn ($q) => $q->where('paid_at', '<=', $period->to))
            ->selectRaw('coalesce(sum(amount_cent), 0) as gross_cent, count(*) as orders, count(distinct email) as buyers')
            ->first();

        // Its own query on its own date. Joining it onto the sales would credit
        // a refund to the month the sale happened, which is not when the money
        // left the account.
        $erstattet = DB::table('payments')
            ->where('status', 'paid')
            ->where('currency', $this->currency)
            ->where('refunded_cent', '>', 0)
            ->when($period->from, fn ($q) => $q->where('refunded_at', '>=', $period->from))
            ->when($period->to, fn ($q) => $q->where('refunded_at', '<=', $period->to))
            ->sum('refunded_cent');

        return [
            'gross_cent' => (int) ($verkauf->gross_cent ?? 0),
            'refunded_cent' => (int) $erstattet,
            'orders' => (int) ($verkauf->orders ?? 0),
            'buyers' => (int) ($verkauf->buyers ?? 0),
        ];
    }

    /**
     * Truncating a timestamp to a day or a month, in the dialect at hand.
     *
     * `strftime` is SQLite's and MySQL has never heard of it. Written for one
     * engine, this screen would be green in a test suite on SQLite and a 500
     * on the first production install that runs MySQL — the exact failure this
     * family has already paid for once.
     */
    protected function bucketExpression(bool $monatlich): string
    {
        $treiber = DB::connection()->getDriverName();

        return match ($treiber) {
            'mysql', 'mariadb' => $monatlich
                ? "date_format(paid_at, '%Y-%m')"
                : "date_format(paid_at, '%Y-%m-%d')",
            'pgsql' => $monatlich
                ? "to_char(paid_at, 'YYYY-MM')"
                : "to_char(paid_at, 'YYYY-MM-DD')",
            default => $monatlich
                ? "strftime('%Y-%m', paid_at)"
                : "strftime('%Y-%m-%d', paid_at)",
        };
    }

    protected function paidInPeriod(): Builder
    {
        return DB::table('payments')
            ->where('status', 'paid')
            ->where('currency', $this->currency)
            ->when($this->period->from, fn ($q) => $q->where('paid_at', '>=', $this->period->from))
            ->when($this->period->to, fn ($q) => $q->where('paid_at', '<=', $this->period->to));
    }

    /** For "all time" the range comes from the data, because nothing else defines it. */
    protected function openEndedOverTime(): array
    {
        if (! self::available()) {
            return [];
        }

        $erste = DB::table('payments')->where('status', 'paid')->min('paid_at');

        if ($erste === null) {
            return [];
        }

        return (new self(
            Period::between(Carbon::parse($erste)->startOfMonth(), Carbon::now()->endOfDay()),
            $this->currency,
        ))->overTime();
    }

    /**
     * What the buyer would recognise.
     *
     * Through the catalogue, never the config array: an offer registers its own
     * resolver there, and a handle sold through one resolves nowhere else. A
     * handle with no catalogue entry keeps its handle rather than vanishing.
     */
    protected function productName(string $handle): string
    {
        $catalogue = '\Goldnead\StatamicPayments\Support\Catalogue';

        if (! class_exists($catalogue)) {
            return $handle;
        }

        try {
            $produkt = app($catalogue)->find($handle);
        } catch (\Throwable) {
            return $handle;
        }

        return is_array($produkt) && is_string($produkt['name'] ?? null) && $produkt['name'] !== ''
            ? $produkt['name']
            : $handle;
    }
}
