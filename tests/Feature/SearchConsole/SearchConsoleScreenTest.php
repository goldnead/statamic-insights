<?php

namespace Goldnead\StatamicInsights\Tests\Feature\SearchConsole;

use Goldnead\StatamicInsights\SearchConsole\SnapshotReader;
use Goldnead\StatamicInsights\ServiceProvider;
use Goldnead\StatamicInsights\Tests\Support\SearchConsoleSnapshot;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\CP\Navigation\Nav;

class SearchConsoleScreenTest extends SearchConsoleTestCase
{
    /** @return array<int, string> the names of the entries under Insights */
    protected function insightsChildren(): array
    {
        // The harness mocks the Nav facade, so a real builder is handed to the
        // provider. The reader singleton is forgotten so each call looks at the
        // disk as it is now.
        $this->app->forgetInstance(SnapshotReader::class);

        $nav = new Nav;
        $this->app->getProvider(ServiceProvider::class)->buildNavigation($nav);

        $item = collect($nav->items())->first(fn ($item) => $item->display() === __('statamic-insights::nav.insights'));

        $this->assertNotNull($item, 'Insights is not in the navigation at all');

        return collect($item->children())->map->display()->all();
    }

    #[Test]
    public function it_is_closed_to_somebody_without_the_permission(): void
    {
        SearchConsoleSnapshot::write($this->file, SearchConsoleSnapshot::make(Carbon::now()));

        $this->actingAs($this->benutzer(false))
            ->get(cp_route('insights.search-console'))
            ->assertForbidden();
    }

    #[Test]
    public function a_good_snapshot_renders_the_screen_with_its_figures(): void
    {
        SearchConsoleSnapshot::write($this->file, SearchConsoleSnapshot::make(Carbon::now()->subHours(6)));

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.search-console'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('insights::SearchConsole')
                ->where('status', 'ok')
                ->where('property', 'sc-domain:example.test')
                ->where('stale', false)
                ->has('windows', 2)
                ->where('windows.0.name', '7d')
                ->where('windows.1.name', '28d')
                ->has('windows.0.tiles', 4)
                ->has('daily.clicks', 56)
                ->has('queries', 3)
                ->has('pages', 2)
            );
    }

    #[Test]
    public function an_old_snapshot_is_flagged_stale_beside_the_stand(): void
    {
        SearchConsoleSnapshot::write($this->file, SearchConsoleSnapshot::make(Carbon::now()->subDays(5)));

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.search-console'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('status', 'ok')->where('stale', true)->has('fetchedAtLabel'));
    }

    #[Test]
    public function a_broken_file_says_so_and_does_not_crash(): void
    {
        file_put_contents($this->file, '{"version":1,"fetched_at":"2026-10-2');

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.search-console'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('insights::SearchConsole')
                ->where('status', 'unreadable')
                ->missing('windows')
            );
    }

    #[Test]
    public function without_a_file_the_direct_address_answers_quietly(): void
    {
        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.search-console'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('status', 'missing')->missing('windows'));
    }

    #[Test]
    public function the_navigation_has_no_entry_without_a_file(): void
    {
        $this->assertNotContains('Search Console', $this->insightsChildren());
    }

    #[Test]
    public function the_navigation_shows_the_entry_once_the_file_exists(): void
    {
        SearchConsoleSnapshot::write($this->file, SearchConsoleSnapshot::make(Carbon::now()));

        $this->assertContains('Search Console', $this->insightsChildren());
    }

    #[Test]
    public function the_navigation_keeps_the_entry_for_a_broken_file(): void
    {
        file_put_contents($this->file, 'nonsense');

        $this->assertContains('Search Console', $this->insightsChildren());
    }

    #[Test]
    public function the_other_screens_do_not_notice_the_missing_file(): void
    {
        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.metrics'))
            ->assertOk();
    }
}
