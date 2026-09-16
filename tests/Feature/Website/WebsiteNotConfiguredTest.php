<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Website;

use Goldnead\StatamicInsights\Support\MetricRegistry;
use Goldnead\StatamicInsights\Support\ReportRegistry;
use Goldnead\StatamicInsights\Tests\TestCase;
use Goldnead\StatamicInsights\Website\Rybbit;
use PHPUnit\Framework\Attributes\Test;

/**
 * An installation with no analytics service configured.
 *
 * Nothing about the website appears anywhere: no heading over no figures, no
 * table that says "not installed" about a web service nobody can install. The
 * Website group exists for an installation that has one, and for no other.
 */
class WebsiteNotConfiguredTest extends TestCase
{
    #[Test]
    public function it_registers_no_website_figures(): void
    {
        $this->assertNull(app(MetricRegistry::class)->find('website.visitors'));
    }

    #[Test]
    public function it_registers_no_website_tables(): void
    {
        $this->assertNull(app(ReportRegistry::class)->find('website.top_pages'));
    }

    /**
     * Half-configured is not configured. A url with no site id would send
     * every request to `/api/sites//overview`, which is a 404 dressed up as a
     * missing number.
     */
    #[Test]
    public function a_site_id_alone_is_not_enough(): void
    {
        config(['statamic-insights.website.site_id' => '7']);

        $this->assertFalse(Rybbit::configured());
    }

    #[Test]
    public function a_blank_site_id_reads_as_no_site_and_not_as_site_zero(): void
    {
        config([
            'statamic-insights.website.url' => 'https://analytics.example',
            'statamic-insights.website.key' => 'rb_test',
            'statamic-insights.website.site_id' => '  ',
        ]);

        $this->assertFalse(Rybbit::configured());
    }
}
