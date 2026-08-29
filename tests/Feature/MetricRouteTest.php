<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use Goldnead\StatamicInsights\Facades\Insights;
use Goldnead\StatamicInsights\Tests\Fakes\BrokenMetric;
use Goldnead\StatamicInsights\Tests\Fakes\CountingMetric;
use Goldnead\StatamicInsights\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The generic screens, asked for over HTTP.
 *
 * Written first this time. The last review found the one failure no unit test
 * could see — a screen that answered 500 because nothing ever requested it —
 * and the lesson was that a reporting addon without a route test checks its
 * arithmetic and not its purpose.
 */
class MetricRouteTest extends TestCase
{
    protected function benutzer(bool $darf = true)
    {
        if ($darf) {
            return tap(User::make()->email('darf@example.com')->makeSuper())->save();
        }

        $rolle = tap(Role::make('nur-cp')->addPermission('access cp'))->save();

        return tap(User::make()->email('darfnicht@example.com')->assignRole($rolle))->save();
    }

    /** A site with no reporting siblings is a state, not an error page. */
    #[Test]
    public function the_list_answers_when_nothing_is_registered(): void
    {
        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.metrics'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('insights::Metrics')
                ->has('groups', 0)
            );
    }

    #[Test]
    public function a_registered_metric_appears_on_the_list(): void
    {
        Insights::registerMetric(new CountingMetric(fixed: 12));

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.metrics'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('groups', 1)
                ->where('groups.0.group', 'Attrappe')
                ->where('groups.0.metrics.0.handle', 'test.counted')
                ->where('groups.0.metrics.0.value', 12)
                ->where('groups.0.metrics.0.unit', 'count')
            );
    }

    /** A broken contributor costs its tile, not the page. */
    #[Test]
    public function a_broken_metric_does_not_take_the_list_down(): void
    {
        Insights::registerMetric(new CountingMetric(fixed: 1));
        Insights::registerMetric(new BrokenMetric);

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.metrics'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('groups.0.metrics', 1));
    }

    #[Test]
    public function the_detail_screen_shows_series_and_a_split(): void
    {
        $heute = Carbon::now()->format('Y-m-d');
        Insights::registerMetric(new CountingMetric(perBucket: [$heute => 9]));

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.metrics.show', ['metric' => 'test.counted']).'?period=7d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('insights::Metric')
                ->where('metric.handle', 'test.counted')
                ->has('series', 7)
                ->where('dimension', 'farbe')
                ->has('breakdown', 3)
            );
    }

    /** The split can be chosen, and only from the ones the metric offers. */
    #[Test]
    public function the_split_can_be_switched_and_an_unknown_one_falls_back(): void
    {
        Insights::registerMetric(new CountingMetric);
        $user = $this->benutzer();

        $this->actingAs($user)
            ->get(cp_route('insights.metrics.show', ['metric' => 'test.counted']).'?by=form')
            ->assertInertia(fn ($page) => $page->where('dimension', 'form'));

        $this->actingAs($user)
            ->get(cp_route('insights.metrics.show', ['metric' => 'test.counted']).'?by=unsinn')
            ->assertInertia(fn ($page) => $page->where('dimension', 'farbe'));
    }

    /**
     * A handle nobody registered is a 404, not a screen full of zeroes.
     *
     * A saved link with a typo must not read as "this number is nothing".
     */
    /**
     * A metric that is registered, says it is available, and then throws.
     *
     * The list leaves it out. This screen used to hand the page a null and let
     * the browser fall over on `metric.label` — the reader's promise that one
     * broken contributor costs its own tile broken on exactly the URL that ends
     * up in a saved link.
     */
    #[Test]
    public function a_metric_that_throws_gives_a_404_and_not_a_white_page(): void
    {
        Insights::registerMetric(new BrokenMetric);

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.metrics.show', ['metric' => 'test.broken']))
            ->assertNotFound();
    }

    #[Test]
    public function an_unknown_metric_is_not_found(): void
    {
        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.metrics.show', ['metric' => 'gibts.nicht']))
            ->assertNotFound();
    }

    /** Registered but unable to answer is also not a screen. */
    #[Test]
    public function an_unavailable_metric_is_not_found_either(): void
    {
        Insights::registerMetric(new CountingMetric(isAvailable: false));

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.metrics.show', ['metric' => 'test.counted']))
            ->assertNotFound();
    }

    #[Test]
    public function both_screens_refuse_somebody_without_the_permission(): void
    {
        Insights::registerMetric(new CountingMetric);
        $user = $this->benutzer(darf: false);

        $this->actingAs($user)->get(cp_route('insights.metrics'))->assertForbidden();
        $this->actingAs($user)
            ->get(cp_route('insights.metrics.show', ['metric' => 'test.counted']))
            ->assertForbidden();
    }
}
