<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every page a controller can answer with has to exist on the JavaScript side.
 *
 * The failure it guards is invisible to every other kind of test: the route
 * answers 200, the Inertia payload names the component, and the browser paints
 * a white page because nothing registered that name. A route test asserts the
 * status; an Inertia test asserts the name. Neither asks whether the name
 * resolves to anything.
 *
 * Purely textual — no application boot, no fixtures, milliseconds. It compares
 * what `Inertia::render()` names anywhere in src/ against what cp.js registers,
 * in both directions: a registration whose controller is gone is dead weight in
 * the bundle that reads as if the screen still exists.
 */
class EveryInertiaPageIsRegisteredTest extends TestCase
{
    #[Test]
    public function it_registers_every_page_a_controller_can_render(): void
    {
        $fehlend = array_values(array_diff($this->rendered(), $this->registered()));

        $this->assertSame([], $fehlend, 'diese Seiten erschienen als weisse Seite');
    }

    #[Test]
    public function it_renders_every_page_it_registers(): void
    {
        $ueberzaehlig = array_values(array_diff($this->registered(), $this->rendered()));

        $this->assertSame([], $ueberzaehlig, 'diese Registrierungen zeigen auf nichts mehr');
    }

    /** @return array<int, string> */
    protected function rendered(): array
    {
        $namen = [];

        foreach (Finder::create()->files()->in(__DIR__.'/../../src')->name('*.php') as $datei) {
            preg_match_all("/Inertia::render\(\s*'([^']+)'/", $datei->getContents(), $treffer);
            $namen = array_merge($namen, $treffer[1]);
        }

        return array_values(array_unique($namen));
    }

    /** @return array<int, string> */
    protected function registered(): array
    {
        $cp = file_get_contents(__DIR__.'/../../resources/js/cp.js');

        preg_match_all("/\\\$inertia\.register\(\s*'([^']+)'/", $cp, $treffer);

        return array_values(array_unique($treffer[1]));
    }
}
