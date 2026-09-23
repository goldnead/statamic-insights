<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use Goldnead\StatamicInsights\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * Which nav entry the breadcrumb names on each screen.
 *
 * Statamic marks a nav child active by URL. A screen whose URL is not itself
 * in the nav — a single report, a single metric — falls back to a pattern per
 * child, "this URL or anything below it" (`NavItem::generateActivePatternForCpUrl()`),
 * and the breadcrumb takes the **first** child that matches. The revenue entry
 * used to live at `/cp/insights`, above every other screen, and it comes first:
 * every report and every metric read "Revenue" in the breadcrumb.
 *
 * Statamic 6 has no way to set a nav item's pattern by hand, so the fix is the
 * address: no entry may sit above a sibling. This test states that rule rather
 * than rendering a breadcrumb, which is built in the browser from the nav.
 */
class BreadcrumbTest extends TestCase
{
    /** @return array<string, string> */
    protected function entries(): array
    {
        return [
            'revenue' => cp_route('insights.revenue'),
            'subscriptions' => cp_route('insights.subscriptions'),
            'metrics' => cp_route('insights.metrics'),
            'reports' => cp_route('insights.reports'),
        ];
    }

    #[Test]
    public function no_insights_entry_sits_above_a_sibling(): void
    {
        foreach ($this->entries() as $name => $url) {
            foreach ($this->entries() as $other => $unter) {
                if ($name === $other) {
                    continue;
                }

                $this->assertFalse(
                    str_starts_with($unter.'/', rtrim($url, '/').'/'),
                    "[{$other}] lies under [{$name}]; every page of [{$other}] that is not itself in the nav would show [{$name}] in the breadcrumb",
                );
            }
        }
    }

    #[Test]
    public function a_report_matches_the_reports_pattern_and_no_earlier_one(): void
    {
        $bericht = parse_url(cp_route('insights.reports.show', ['report' => 'payments.revenue_by_month']), PHP_URL_PATH);

        $treffer = [];

        foreach ($this->entries() as $name => $url) {
            // Statamic's own pattern, `<relative url>(/(.*)?|$)`, unanchored.
            $muster = '#'.trim((string) parse_url($url, PHP_URL_PATH), '/').'(/(.*)?|$)#';

            if (preg_match($muster, ltrim((string) $bericht, '/')) === 1) {
                $treffer[] = $name;
            }
        }

        $this->assertSame(['reports'], $treffer);
    }

    #[Test]
    public function the_old_address_of_the_revenue_screen_still_leads_there(): void
    {
        $this->actingAs(tap(User::make()->email('darf@example.com')->makeSuper())->save())
            ->get(cp_route('insights.index'))
            ->assertRedirect(cp_route('insights.revenue'));
    }

    #[Test]
    public function the_old_address_keeps_its_period_and_currency(): void
    {
        $ziel = $this->actingAs(tap(User::make()->email('darf@example.com')->makeSuper())->save())
            ->get(cp_route('insights.index').'?period=90d&currency=CHF')
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith(cp_route('insights.revenue').'?', (string) $ziel);
        parse_str((string) parse_url((string) $ziel, PHP_URL_QUERY), $query);
        // Order aside, both survive.
        ksort($query);
        $this->assertSame(['currency' => 'CHF', 'period' => '90d'], $query);
    }
}
