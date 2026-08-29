/**
 * Printing a number whose meaning the screen does not know.
 *
 * The generic screens render metrics contributed by addons this one has never
 * heard of, so formatting cannot branch on what the number *is* — only on the
 * unit the metric declared and whatever it put in `meta`.
 */

/** The language the Control Panel is being read in, not the browser's. */
function cpLocale() {
    return document.documentElement.lang || undefined;
}

/**
 * @param {number|null} value
 * @param {string} unit  count | currency | percent | duration
 * @param {object} meta  e.g. { currency: 'EUR' }
 */
export function formatValue(value, unit, meta = {}) {
    // Null is not zero, and the difference is the whole reason the contract
    // allows it: a rate against nothing has no answer, and printing 0 there is
    // a statement its own neighbour contradicts.
    if (value === null || value === undefined) return '—';

    switch (unit) {
        case 'currency':
            return new Intl.NumberFormat(cpLocale(), {
                style: 'currency',
                currency: meta.currency || 'EUR',
            }).format(Number(value) / 100);

        case 'percent':
            return `${new Intl.NumberFormat(cpLocale(), { maximumFractionDigits: 1 }).format(value)} %`;

        case 'duration':
            return formatDuration(Number(value));

        default:
            return new Intl.NumberFormat(cpLocale()).format(value);
    }
}

/** Seconds as something a person reads, not as a number of seconds. */
function formatDuration(seconds) {
    if (seconds < 60) return `${Math.round(seconds)} s`;
    if (seconds < 3600) return `${Math.round(seconds / 60)} min`;
    if (seconds < 86400) return `${Math.round(seconds / 3600)} h`;

    return `${Math.round(seconds / 86400)} d`;
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
