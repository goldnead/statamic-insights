/**
 * Printing one cell of a report whose columns the screen has never seen.
 *
 * The report declares a unit per column; the row may carry a `currency` of its
 * own. Nothing here knows what the number is — only how the column asked for
 * it to be shown.
 */

import { formatValue, bucketDate } from './format.js';

function cpLocale() {
    return document.documentElement.lang || undefined;
}

/**
 * @param {object} row
 * @param {{ key: string, unit: string }} column
 * @returns {string}
 */
export function formatCell(row, column) {
    const value = row[column.key];

    if (value === null || value === undefined || value === '') return '—';

    switch (column.unit) {
        case 'currency':
            return formatValue(value, 'currency', { currency: row.currency || 'EUR' });

        case 'month':
            return bucketDate(String(value));

        case 'date': {
            const date = new Date(String(value).replace(' ', 'T'));

            return Number.isNaN(date.getTime())
                ? String(value)
                : date.toLocaleString(cpLocale(), { dateStyle: 'medium', timeStyle: 'short' });
        }

        case 'text':
        case 'code':
            return String(value);

        default:
            return formatValue(value, column.unit, {});
    }
}

/** Numbers sit on the right so their digits line up; words sit on the left. */
export function isNumeric(column) {
    return !['text', 'code', 'month', 'date'].includes(column.unit);
}
