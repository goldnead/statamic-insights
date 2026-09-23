<?php

namespace Goldnead\StatamicInsights\Reports;

use Goldnead\StatamicInsights\Contracts\HasDefaultSort;
use Goldnead\StatamicInsights\Subscriptions\SubscriptionFigures;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How recurring revenue moved, month by month: where it stood, what came in,
 * what went, where it ended.
 *
 * One row per month **and currency**, like {@see RevenueByMonth}. The running
 * month ends now. The rules behind every column are on
 * {@see SubscriptionFigures}.
 */
class MrrMovements extends SubscriptionReport implements HasDefaultSort
{
    /** The latest month first: it is the one somebody opens this to see. */
    public function defaultSort(): array
    {
        return ['column' => 'month', 'direction' => 'desc'];
    }

    public function handle(): string
    {
        return 'payments.mrr_movements';
    }

    public function label(): string
    {
        return $this->t('mrr_movements');
    }

    public function description(): ?string
    {
        return $this->t('mrr_movements_description');
    }

    public function usesPeriod(): bool
    {
        return true;
    }

    /**
     * Whether the rows last built had anybody coming back. Most shops never do,
     * and a column of zeros costs the table the width its last column needs.
     * Read after {@see rows()}; the reader builds the rows first.
     */
    private bool $mitRueckkehr = false;

    public function columns(): array
    {
        return array_values(array_filter([
            $this->column('month', $this->t('col_month'), 'month'),
            $this->column('mrr_start', $this->t('col_mrr_start'), Unit::CURRENCY),
            $this->column('new', $this->t('col_new'), Unit::CURRENCY),
            $this->mitRueckkehr ? $this->column('reactivation', $this->t('col_reactivation'), Unit::CURRENCY) : null,
            $this->column('expansion', $this->t('col_expansion'), Unit::CURRENCY),
            $this->column('contraction', $this->t('col_contraction'), Unit::CURRENCY),
            $this->column('churn', $this->t('col_churn'), Unit::CURRENCY),
            $this->column('paused', $this->t('col_paused'), Unit::CURRENCY),
            $this->column('net', $this->t('col_net'), Unit::CURRENCY),
            $this->column('mrr_end', $this->t('col_mrr_end'), Unit::CURRENCY),
        ]));
    }

    /**
     * One currency, losses with their minus: a column reads as what it did
     * to the total. A pause that ended shows as a plus in the pause column.
     */
    public function rows(MetricQuery $query): array
    {
        $this->fresh();

        $zeilen = array_map(fn (array $r) => array_merge($r, [
            'contraction' => -$r['contraction'],
            'churn' => -$r['churn'],
            'paused' => -$r['paused'],
        ]), $this->only(
            $this->figures()->monthlyMovements($query->period->from, $query->period->toExclusive()),
            $query,
        ));

        $this->mitRueckkehr = array_filter($zeilen, fn (array $r) => ($r['reactivation'] ?? 0) !== 0) !== [];

        return $zeilen;
    }
}
