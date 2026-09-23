<?php

namespace Goldnead\StatamicInsights\Subscriptions;

use Goldnead\StatamicInsights\Support\Concerns\NarrowsToBrand;
use Goldnead\StatamicInsights\Support\Neighbours;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the agreements of `statamic-payments` and the cycles charged on them.
 *
 * **The database only selects; it never calculates.** No date function, no
 * bucket expression, no sum: two plain reads, and every figure is worked out
 * in PHP from the rows. That is what makes the answer the same on SQLite,
 * MySQL and Postgres — the family has already paid once for date arithmetic
 * that each driver does its own way (`backlog-insights-kennzahlen-weichen-
 * unter-mysql-ab`). The price is holding one brand's agreements in memory,
 * which for a shop that sells subscriptions by hand is a few thousand rows.
 *
 * Narrowed to the current brand, like every other figure in the addon.
 * Read directly from the table, guarded by {@see Neighbours}, as the six
 * shipped reports do; `subscriptions` came with payments 1.9, so a site on an
 * older payments has the addon and not the table, and says so.
 */
class AgreementReader
{
    use NarrowsToBrand;

    /** Parameters per `whereIn`, below every driver's limit. */
    private const CHUNK = 500;

    protected function table(): string
    {
        return 'subscriptions';
    }

    protected function brandColumn(): ?string
    {
        return Schema::hasColumn('subscriptions', 'brand_id') ? 'brand_id' : null;
    }

    public function available(): bool
    {
        return Neighbours::installed(Neighbours::PAYMENTS) && Schema::hasTable('subscriptions');
    }

    /** @return array<int, Agreement> */
    public function all(): array
    {
        if (! $this->available()) {
            return [];
        }

        $zeilen = $this->brandScoped(DB::table('subscriptions'))->orderBy('id')->get();

        if ($zeilen->isEmpty()) {
            return [];
        }

        $zyklen = $this->cycles($zeilen->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $zeilen
            ->map(fn ($zeile) => $this->agreement($zeile, $zyklen[(int) $zeile->id] ?? []))
            ->values()
            ->all();
    }

    /**
     * Paid charges per agreement, oldest first.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<int, object>>
     */
    protected function cycles(array $ids): array
    {
        if (! Schema::hasColumn('payments', 'subscription_id')) {
            return [];
        }

        $spalten = ['id', 'subscription_id', 'amount_cent', 'paid_at'];

        if (Schema::hasColumn('payments', 'meta')) {
            $spalten[] = 'meta';
        }

        $je = [];

        foreach (array_chunk($ids, self::CHUNK) as $teil) {
            $reihen = DB::table('payments')
                ->whereIn('subscription_id', $teil)
                ->where('status', 'paid')
                ->whereNotNull('paid_at')
                ->get($spalten);

            foreach ($reihen as $reihe) {
                $je[(int) $reihe->subscription_id][] = $reihe;
            }
        }

        // Sorted here rather than by the database: `paid_at` then `id`, the
        // same order on every driver.
        foreach ($je as &$liste) {
            usort($liste, fn ($a, $b) => [$this->time($a->paid_at)?->getTimestamp(), (int) $a->id]
                <=> [$this->time($b->paid_at)?->getTimestamp(), (int) $b->id]);
        }

        return $je;
    }

    /** @param  array<int, object>  $zyklen */
    protected function agreement(object $zeile, array $zyklen): Agreement
    {
        $status = (string) ($zeile->status ?? '');
        $standing = Standing::of($status);

        // Charges that cost something, without settling-up charges.
        $bezahlt = array_values(array_filter(
            $zyklen,
            fn ($z) => (int) $z->amount_cent > 0 && ! $this->isProration($z),
        ));

        $start = $this->time($zeile->starts_at ?? null) ?? $this->time($zeile->created_at ?? null);

        if ($bezahlt !== []) {
            $erste = $this->time($bezahlt[0]->paid_at);

            if ($erste !== null && ($start === null || $erste->lt($start))) {
                $start = $erste;
            }
        }

        $preise = [];

        foreach (array_slice($bezahlt, 1) as $zyklus) {
            $wann = $this->time($zyklus->paid_at);

            if ($wann !== null) {
                $preise[] = [$wann, (int) $zyklus->amount_cent];
            }
        }

        return new Agreement(
            id: (int) $zeile->id,
            customer: $this->customer($zeile),
            name: $this->text($zeile->name ?? null) ?? $this->text($zeile->email ?? null),
            product: (string) ($zeile->product ?? ''),
            currency: strtoupper((string) ($zeile->currency ?? '')),
            interval: (string) ($zeile->interval ?? ''),
            times: isset($zeile->times) ? (int) $zeile->times : null,
            timesCharged: (int) ($zeile->times_charged ?? 0),
            status: strtolower(trim($status)),
            standing: $standing,
            amountCent: (int) ($zeile->amount_cent ?? 0),
            start: $start,
            stop: $standing === Standing::LIVE ? null : $this->stop($zeile, $standing),
            nextPaymentAt: $this->time($zeile->next_payment_at ?? null),
            prices: $preise,
        );
    }

    protected function stop(object $zeile, string $standing): ?Carbon
    {
        $spalten = ['ended_at', 'cancelled_at', 'dunning_started_at', 'updated_at'];

        if ($standing === Standing::PAUSED) {
            array_unshift($spalten, 'paused_at');
        }

        foreach ($spalten as $spalte) {
            $wann = $this->time($zeile->{$spalte} ?? null);

            if ($wann !== null) {
                return $wann;
            }
        }

        return null;
    }

    /**
     * Who the agreement belongs to, for counting customers rather than rows.
     * The address as the payments addon recorded it, case and space aside;
     * the provider's customer reference when there is none.
     */
    protected function customer(object $zeile): string
    {
        $email = strtolower(trim((string) ($zeile->email ?? '')));

        if ($email !== '') {
            return 'email:'.$email;
        }

        $ref = trim((string) ($zeile->customer_reference ?? ''));

        return $ref !== '' ? 'ref:'.$ref : 'id:'.$zeile->id;
    }

    protected function isProration(object $zyklus): bool
    {
        $meta = $zyklus->meta ?? null;

        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        return is_array($meta) && ! empty($meta['proration']);
    }

    /**
     * A stored timestamp as the moment the application means by it.
     *
     * Every driver hands a timestamp back as `Y-m-d H:i:s` in the application's
     * timezone; parsed here, compared here, never by the database.
     */
    protected function time(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
