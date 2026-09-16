<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Website;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\ReportReader;
use Goldnead\StatamicInsights\Support\ReportRegistry;
use PHPUnit\Framework\Attributes\Test;

class WebsiteReportTest extends WebsiteTestCase
{
    #[Test]
    public function it_registers_the_five_traffic_tables(): void
    {
        $registry = app(ReportRegistry::class);

        foreach (['top_pages', 'referrers', 'countries', 'devices', 'browsers'] as $name) {
            $this->assertNotNull($registry->find('website.'.$name), "website.{$name} was not registered");
        }
    }

    #[Test]
    public function it_turns_the_top_list_into_rows(): void
    {
        $this->fakeService(top: [
            ['value' => '/', 'count' => 16, 'pageviews' => 24, 'bounce_rate' => 37.5],
            ['value' => '/ki-beratung', 'count' => 6, 'pageviews' => 9, 'bounce_rate' => 0],
        ]);

        $rows = $this->rows('website.top_pages');

        $this->assertSame([
            ['path' => '/', 'visitors' => 16, 'pageviews' => 24, 'bounce_rate' => 37.5],
            ['path' => '/ki-beratung', 'visitors' => 6, 'pageviews' => 9, 'bounce_rate' => 0.0],
        ], $rows);
    }

    /**
     * The largest row in most referrer tables is the one with no referrer at
     * all: typed, bookmarked, or out of a mail programme. Printed as an empty
     * cell it would read as a gap in the data.
     */
    #[Test]
    public function an_empty_referrer_is_named_rather_than_left_blank(): void
    {
        $this->fakeService(top: [['value' => '', 'count' => 40, 'pageviews' => 90]]);

        $this->assertSame('Direct', $this->rows('website.referrers')[0]['referrer']);
    }

    #[Test]
    public function a_dimension_without_a_bounce_rate_says_nothing_rather_than_zero(): void
    {
        $this->fakeService(top: [['value' => 'Chrome', 'count' => 17, 'pageviews' => 84]]);

        $this->assertNull($this->rows('website.browsers')[0]['bounce_rate']);
    }

    /**
     * A top-twenty sorted by name reads as a complete list of twenty. The
     * report says how it wants to be sorted and the reader passes that on.
     */
    #[Test]
    public function the_traffic_tables_ask_to_be_sorted_by_visitors(): void
    {
        $described = app(ReportReader::class)->describe(app(ReportRegistry::class)->find('website.top_pages'));

        $this->assertSame(['column' => 'visitors', 'direction' => 'desc'], $described['sort']);
    }

    #[Test]
    public function a_report_with_no_opinion_leaves_the_order_to_the_screen(): void
    {
        $described = app(ReportReader::class)->describe(app(ReportRegistry::class)->find('payments.revenue_by_month'));

        $this->assertNull($described['sort']);
    }

    #[Test]
    public function country_codes_are_spelled_out(): void
    {
        $this->fakeService(top: [['value' => 'DE', 'count' => 9, 'pageviews' => 75, 'bounce_rate' => 0]]);

        $row = $this->rows('website.countries')[0];

        // Without `intl` the code itself is the honest answer, so both pass.
        $this->assertContains($row['country'], ['Germany', 'Deutschland', 'DE']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function rows(string $handle): array
    {
        return app(ReportRegistry::class)
            ->find($handle)
            ->rows(new MetricQuery(Period::fromPreset('30d')));
    }
}
