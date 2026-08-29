/**
 * The language the Control Panel is being read in.
 *
 * Not the browser's. A German CP opened in a browser set to English would
 * otherwise print "€1,226.00" beside German labels — and the thousands
 * separator and the decimal comma are exactly the two characters a person
 * reads a money figure by. Falls back to the document language, then to the
 * browser, so it degrades rather than throws.
 */
function cpLocale() {
    return document.documentElement.lang || undefined;
}

/**
 * An amount in minor units, as money.
 *
 * The currency is a real argument, not a hard-coded EUR: every payment row
 * carries its own `currency` column and the report never mixes two, so there
 * is something honest to read it from.
 *
 * @param {number|null|undefined} cent
 * @param {string} currency ISO 4217
 * @returns {string}
 */
export function money(cent, currency = 'EUR') {
    const value = Number(cent ?? 0) / 100;

    return new Intl.NumberFormat(cpLocale(), {
        style: 'currency',
        currency: currency || 'EUR',
    }).format(value);
}

/** A bucket key ('2026-08-29' or '2026-08') as a short date in the CP's language. */
export function bucketDate(bucket) {
    if (!bucket) return '';

    const parts = bucket.split('-').map(Number);
    const date = new Date(parts[0], (parts[1] ?? 1) - 1, parts[2] ?? 1);

    return parts.length === 2
        ? date.toLocaleDateString(cpLocale(), { month: 'short', year: '2-digit' })
        : date.toLocaleDateString(cpLocale(), { day: 'numeric', month: 'short' });
}

/**
 * How this figure compares with the same figure one period earlier.
 *
 * Returns null when there is nothing to compare against — an "all time" report
 * has no period before it, and a first month has no previous month. A percentage
 * invented for those cases would read as a fact.
 *
 * Growth from zero is deliberately not expressed as a percentage either: every
 * increase from nothing is infinite, and "+∞ %" tells a reader less than the
 * absolute number already did.
 */
export function delta(now, before) {
    if (before === null || before === undefined) return null;
    if (before === 0) return null;

    return Math.round(((now - before) / before) * 100);
}

/**
 * A percentage in the Control Panel's language.
 *
 * A bare `1.2` interpolated into German copy prints "1.2 %" beside "1.226,00 €"
 * — the decimal separator disagreeing with the money two lines above it, which
 * is exactly the kind of seam that makes a screen read as bolted on.
 */
export function percent(value, digits = 1) {
    return new Intl.NumberFormat(cpLocale(), {
        minimumFractionDigits: 0,
        maximumFractionDigits: digits,
    }).format(Number(value ?? 0));
}
