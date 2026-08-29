<?php

namespace Goldnead\StatamicInsights\Tests\Fakes;

/**
 * The same metric, but on a table that knows brands.
 *
 * Declaring the column is the entire difference — which is the point of the
 * mechanism: an addon opts in with one method and cannot then forget to apply
 * the filter in its chart or in one of its splits.
 */
class BrandedWidgetMetric extends WidgetMetric
{
    protected function brandColumn(): ?string
    {
        return 'brand_id';
    }
}
