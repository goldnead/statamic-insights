<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use Goldnead\StatamicInsights\Facades\Insights;
use Goldnead\StatamicInsights\Http\Controllers\Cp\RevenueController;
use Goldnead\StatamicInsights\Reports\MrrMovements;
use Goldnead\StatamicInsights\Subscriptions\AgreementReader;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\MetricReader;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\ReportReader;
use Goldnead\StatamicInsights\Tests\Fakes\BrokenMetric;
use Goldnead\StatamicInsights\Tests\Fakes\FakeRevenueMetric;
use Goldnead\StatamicInsights\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;

/**
 * Stille Fehler aus dem Auftrag "Mittel": jeder Fehlerpfad sagt etwas im Log
 * und liefert trotzdem das bisherige Ergebnis.
 */
class StilleFehlerMittelTest extends TestCase
{
    /** Punkt 3: der catch in currencies() schluckt den Fehler ohne Log. */
    #[Test]
    public function a_failing_currency_list_is_logged(): void
    {
        Log::spy();

        $metrik = Mockery::mock(new FakeRevenueMetric('payments.revenue_gross', 'Einnahmen'))->makePartial();
        $metrik->shouldReceive('filterOptions')->andThrow(new RuntimeException('kaputte Währungsliste'));
        Insights::registerMetric($metrik);

        $ergebnis = $this->invoke(app(RevenueController::class), 'currencies');

        $this->assertSame([], $ergebnis);
        Log::shouldHaveReceived('warning')->with(
            Mockery::type('string'),
            Mockery::on(fn ($kontext) => in_array(RuntimeException::class, $kontext, true)),
        )->once();
    }

    /** Punkt 12: die konfigurierte Währung wird wie beim Abonnement normalisiert. */
    #[Test]
    public function a_configured_currency_in_lower_case_is_normalised(): void
    {
        config(['statamic-insights.currency' => 'usd']);

        $gewaehlt = $this->invoke(
            app(RevenueController::class),
            'currencyFor',
            Request::create('/', 'GET'),
            ['EUR', 'USD'],
        );

        $this->assertSame('USD', $gewaehlt);
    }

    /** Punkt 7: der Log-Eintrag nennt die Exception-Klasse und das Metrik-Handle. */
    #[Test]
    public function a_failing_series_logs_the_exception_class_and_the_handle(): void
    {
        Log::spy();

        app(MetricReader::class)->series(
            new BrokenMetric('series'),
            new MetricQuery(Period::fromPreset('30d')),
        );

        Log::shouldHaveReceived('warning')->with(
            Mockery::type('string'),
            Mockery::on(fn ($kontext) => in_array(RuntimeException::class, $kontext, true)
                && in_array('test.broken', $kontext, true)),
        )->once();
    }

    /** Punkt 7 (Report-Seite): Sortierung schlägt fehl, Log nennt Klasse und Report-Handle. */
    #[Test]
    public function a_failing_default_sort_logs_the_exception_class_and_the_handle(): void
    {
        Log::spy();

        $report = Mockery::mock(new MrrMovements)->makePartial();
        $report->shouldReceive('defaultSort')->andThrow(new RuntimeException('kaputte Sortierung'));

        $beschreibung = app(ReportReader::class)->describe($report);

        $this->assertNull($beschreibung['sort']);
        Log::shouldHaveReceived('warning')->with(
            Mockery::type('string'),
            Mockery::on(fn ($kontext) => in_array(RuntimeException::class, $kontext, true)
                && in_array($report->handle(), $kontext, true)),
        )->once();
    }

    /** Punkt 11: ein nicht lesbares Datum wird als null gewertet und dabei geloggt. */
    #[Test]
    public function an_unreadable_timestamp_is_logged_not_silently_dropped(): void
    {
        Log::spy();

        $ergebnis = $this->invoke(app(AgreementReader::class), 'time', 'kein datum 2026-99-99 xx');

        $this->assertNull($ergebnis);
        Log::shouldHaveReceived('warning')->once();
    }

    private function invoke(object $objekt, string $methode, mixed ...$argumente): mixed
    {
        $reflexion = new ReflectionMethod($objekt, $methode);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs($objekt, $argumente);
    }
}
