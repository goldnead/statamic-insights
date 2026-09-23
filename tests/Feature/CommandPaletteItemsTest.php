<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every command palette entry says what it does, as a `url` or an `action`.
 *
 * Core's palette registers an entry from those two props and ignores events.
 * An entry wired with `@selected` renders, sits in the palette, does nothing
 * when chosen, and logs "You must provide a `url` string or `action` function"
 * once per entry on every page load. Found on adg-staging (1.5.0-rc.1): seven
 * errors on the subscriptions screen, and the revenue screen had carried the
 * same six since the palette entries were added.
 *
 * Textual, like {@see EveryInertiaPageIsRegisteredTest}: there is no JS test
 * runner in this addon, and the failure is visible in the source.
 */
class CommandPaletteItemsTest extends TestCase
{
    #[Test]
    public function every_palette_entry_has_a_url_or_an_action(): void
    {
        $fehlend = [];

        foreach ((new Finder)->files()->in(__DIR__.'/../../resources/js')->name('*.vue') as $datei) {
            preg_match_all('/<CommandPaletteItem\b(.*?)\/?>/s', $datei->getContents(), $treffer);

            foreach ($treffer[1] as $attribute) {
                if (! preg_match('/(^|\s):?(url|action)=/', $attribute)) {
                    $fehlend[] = $datei->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $fehlend, 'these palette entries neither link nor act');
    }
}
