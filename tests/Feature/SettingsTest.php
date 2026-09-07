<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use Goldnead\BrandContext\ServiceProvider;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\StatamicInsights\Facades\Insights;
use Goldnead\StatamicInsights\Support\Settings;
use Goldnead\StatamicInsights\Support\Unit;
use Goldnead\StatamicInsights\Tests\Fakes\FakeRevenueMetric;
use Goldnead\StatamicInsights\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;
use Statamic\Providers\StatamicServiceProvider;

/**
 * This addon plugged into the shared settings layer, and the value arriving.
 *
 * The screen, the store and the validation belong to
 * `goldnead/statamic-brand-context` and are tested there. What is this addon's
 * — and what this file therefore checks — is the declaration: the namespace,
 * the config root (which is *not* the namespace here), the permission name, and
 * that a value saved through the shared endpoint actually changes what a screen
 * of this addon shows.
 *
 * The last one is the point. `config('statamic-insights.default_period')`
 * holding the new string proves the store works; it does not prove that any
 * screen of this addon reads it. So the assertion is made on the Inertia prop
 * of the metrics screen, one HTTP request later.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    /** brand-context has to boot before this addon's provider registers with it. */
    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);

        array_splice(
            $providers,
            (int) array_search(StatamicServiceProvider::class, $providers, true) + 1,
            0,
            [ServiceProvider::class]
        );

        return $providers;
    }

    protected function superuser()
    {
        return tap(User::make()->email('chef@example.com')->makeSuper())->save();
    }

    /**
     * The form always submits every field of a namespace — the rules are
     * `present` — so a test that cares about one key fills the rest in from the
     * config.
     */
    protected function speichern(array $overrides)
    {
        $settings = [];

        foreach (array_keys(app(SettingsRegistry::class)->fields('insights')) as $key) {
            $settings[$key] = config('statamic-insights.'.$key);
        }

        return $this->actingAs($this->superuser())->patchJson(cp_route('brand-context.settings.update'), [
            'namespace' => 'insights',
            'settings' => array_replace($settings, $overrides),
        ]);
    }

    #[Test]
    public function it_registers_itself_with_the_shared_settings_layer(): void
    {
        $registry = app(SettingsRegistry::class);

        $this->assertTrue($registry->has('insights'), 'boot() did not register the settings provider');
        $this->assertSame(Settings::class, $registry->provider('insights'));

        // The one place in this family where namespace and config root differ:
        // the file is `config/statamic-insights.php`. Read as `insights.…`,
        // every override would land beside the values nobody reads.
        $this->assertSame('statamic-insights', $registry->configPath('insights'));
        $this->assertSame('manage insights settings', $registry->permission('insights'));
    }

    /** Crossing the border: saved through the shared screen, read by this addon's. */
    #[Test]
    public function a_changed_period_reaches_the_metrics_screen(): void
    {
        $this->assertSame('30d', config('statamic-insights.default_period'));

        $this->speichern(['default_period' => '7d'])->assertRedirect();

        $this->assertSame('7d', config('statamic-insights.default_period'));

        $this->actingAs($this->superuser())
            ->get(cp_route('insights.metrics'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('period', '7d'));
    }

    /** A period that is not one of the six never reaches a screen. */
    #[Test]
    public function it_refuses_a_period_that_is_not_one_of_the_six(): void
    {
        $this->speichern(['default_period' => 'letzte 30 Tage'])
            ->assertStatus(422);

        $this->assertSame('30d', config('statamic-insights.default_period'));
    }

    /**
     * The second field, taken the same way to the screen it steers.
     *
     * Deliberately without `?currency=` in the URL: that parameter outranks the
     * setting, so a test that passed it would prove the query string works and
     * say nothing about the saved value.
     */
    #[Test]
    public function a_changed_currency_reaches_the_revenue_screen(): void
    {
        Insights::registerMetric(new FakeRevenueMetric(
            'payments.revenue_gross', 'Einnahmen', Unit::CURRENCY,
            perBucket: [Carbon::now()->format('Y-m-d') => 1000], currencies: ['EUR', 'CHF'],
        ));

        $this->speichern(['currency' => 'CHF'])->assertRedirect();

        $this->actingAs($this->superuser())
            ->get(cp_route('insights.revenue'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('currency', 'CHF'));
    }

    /** Empty currency is a real answer — "follow the payments addon" — not "". */
    #[Test]
    public function an_empty_currency_is_stored_as_null(): void
    {
        $this->speichern(['currency' => 'CHF'])->assertRedirect();
        $this->assertSame('CHF', config('statamic-insights.currency'));

        $this->speichern(['currency' => ''])->assertRedirect();
        $this->assertNull(config('statamic-insights.currency'));
    }
}
