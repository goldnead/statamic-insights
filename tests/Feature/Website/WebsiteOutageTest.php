<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Website;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Website\Rybbit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * Ein ausgefallener Dienst wird nicht bei jedem Aufruf neu angefragt, bis der
 * Timeout abgelaufen ist. Der Fehler bleibt kurz gemerkt und wird geloggt.
 */
class WebsiteOutageTest extends WebsiteTestCase
{
    #[Test]
    public function a_failed_service_is_not_asked_again_for_a_minute(): void
    {
        Cache::flush();
        Log::spy();
        Http::fake(['*' => Http::response('', 500)]);

        $query = new MetricQuery(Period::fromPreset('30d'));
        $fehler = 0;

        // Eine Instanz, wie im Container (Singleton): der Ausfall wird pro
        // Request gemerkt, nicht bei jedem Aufruf neu abgewartet.
        $rybbit = new Rybbit;

        for ($i = 0; $i < 2; $i++) {
            try {
                $rybbit->overview($query);
            } catch (Throwable) {
                $fehler++;
            }
        }

        $this->assertSame(2, $fehler, 'Beide Aufrufe müssen scheitern.');
        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }
}
