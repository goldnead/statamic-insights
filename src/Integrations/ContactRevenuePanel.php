<?php

namespace Goldnead\StatamicInsights\Integrations;

use Throwable;

/**
 * What one person has paid, on the CRM's own contact screen.
 *
 * Contributed from this side through LeadHub's panel registry rather than read
 * from LeadHub's side, because this addon may require LeadHub and LeadHub
 * requires nobody. Reversing that for one panel would make an optional sibling
 * a hard dependency of the CRM.
 *
 * The numbers are the CRM's own — it keeps the ledger. This addon only asks for
 * them and arranges them, which is the whole of what a reporting addon should
 * do: nothing here computes money.
 */
class ContactRevenuePanel
{
    protected const FACADE = '\Goldnead\Leadhub\Facades\LeadHub';

    public static function available(): bool
    {
        $facade = self::FACADE;

        if (! class_exists($facade)) {
            return false;
        }

        try {
            $root = $facade::getFacadeRoot();
        } catch (Throwable) {
            return false;
        }

        // Asked of the object, never of the facade: a facade forwards through
        // `__callStatic` and declares none of what it forwards, so the probe on
        // the facade itself is always false. `revenueFor` arrived after
        // `registerContactPanel` did, so an older sibling gets no panel rather
        // than an error per contact screen.
        return is_object($root) && method_exists($root, 'revenueFor');
    }

    /**
     * @param  mixed  $contact  A LeadHub contact. Typed loosely on purpose:
     *                          this class must not import from the sibling.
     * @return array<string, mixed>|null
     */
    public function __invoke(mixed $contact): ?array
    {
        if (! self::available() || ! is_object($contact)) {
            return null;
        }

        $kaeufe = (int) ($contact->purchase_count ?? 0);

        // Nobody who has never paid needs a panel saying so. An empty card on
        // every contact screen is noise that makes the ones with numbers
        // harder to find — and the registry treats null as a legitimate
        // "nothing to say" for exactly this case.
        if ($kaeufe === 0) {
            return null;
        }

        $bezahlt = (int) ($contact->revenue_cent ?? 0);
        $erstattet = (int) ($contact->revenue_refunded_cent ?? 0);
        $waehrung = is_string($contact->revenue_currency ?? null) && $contact->revenue_currency !== ''
            ? $contact->revenue_currency
            : (string) config('statamic-payments.currency', 'EUR');

        $zeilen = [
            [
                'label' => __('statamic-insights::report.contact_lifetime'),
                'meta' => $this->geld(max(0, $bezahlt - $erstattet), $waehrung),
            ],
            [
                'label' => __('statamic-insights::report.contact_purchases'),
                'meta' => (string) $kaeufe,
            ],
        ];

        if ($erstattet > 0) {
            $zeilen[] = [
                'label' => __('statamic-insights::report.contact_refunded'),
                'meta' => $this->geld($erstattet, $waehrung),
                'badge' => ['text' => __('statamic-insights::report.contact_refund_badge'), 'color' => 'amber'],
            ];
        }

        if ($erst = $this->datum($contact->first_purchase_at ?? null)) {
            $zeilen[] = ['label' => __('statamic-insights::report.contact_first'), 'meta' => $erst];
        }

        if ($letzt = $this->datum($contact->last_purchase_at ?? null)) {
            $zeilen[] = ['label' => __('statamic-insights::report.contact_last'), 'meta' => $letzt];
        }

        return [
            'heading' => __('statamic-insights::report.contact_panel'),
            'rows' => $zeilen,
        ];
    }

    /**
     * Money, formatted here.
     *
     * The panel contract is deliberately dumb — a label and a string — so the
     * formatting cannot happen in the reader's browser the way it does on this
     * addon\'s own screen. `intl` is not guaranteed on every host, hence the
     * plain fallback rather than a fatal on a shared server.
     */
    protected function geld(int $cent, string $waehrung): string
    {
        $betrag = $cent / 100;

        if (class_exists(\NumberFormatter::class)) {
            $f = new \NumberFormatter(app()->getLocale(), \NumberFormatter::CURRENCY);

            return (string) $f->formatCurrency($betrag, $waehrung);
        }

        return number_format($betrag, 2, ',', '.').' '.$waehrung;
    }

    protected function datum(mixed $wert): ?string
    {
        if ($wert === null) {
            return null;
        }

        return method_exists($wert, 'isoFormat')
            ? $wert->isoFormat('LL')
            : (string) $wert;
    }
}
