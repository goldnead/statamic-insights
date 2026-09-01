<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Reports;

use Goldnead\StatamicInsights\Reports\PaymentsByCountry;
use PHPUnit\Framework\Attributes\Test;

class PaymentsByCountryTest extends ReportsTestCase
{
    #[Test]
    public function it_is_unavailable_when_payments_is_missing(): void
    {
        $this->assertFalse((new PaymentsByCountry)->available());
    }

    #[Test]
    public function it_counts_and_sums_paid_payments_per_country_and_keeps_the_unknown(): void
    {
        $this->createPaymentsTables();

        $this->payment(['country' => 'DE', 'amount_cent' => 1000]);
        $this->payment(['country' => 'de', 'amount_cent' => 2000]);
        $this->payment(['country' => 'AT', 'amount_cent' => 500]);
        $this->payment(['country' => null, 'amount_cent' => 300]);
        $this->payment(['country' => 'DE', 'amount_cent' => 99900, 'status' => 'expired']);

        $rows = (new PaymentsByCountry)->rows($this->frage('30d'));

        $de = $this->row($rows, 'code', 'DE');
        $this->assertSame(2, $de['payments']);
        $this->assertSame(3000, $de['revenue_cent']);
        $this->assertNotSame('DE', $de['country'], 'the code should be spelled out when intl is there');

        $this->assertSame(500, $this->row($rows, 'code', 'AT')['revenue_cent']);

        // A row, not an omission.
        $unbekannt = $this->row($rows, 'code', null);
        $this->assertNotNull($unbekannt);
        $this->assertSame(300, $unbekannt['revenue_cent']);

        // Busiest first.
        $this->assertSame('DE', $rows[0]['code']);
    }
}
