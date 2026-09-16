<?php

namespace Goldnead\StatamicInsights\Support;

use Locale;

/**
 * A two-letter country code as a word, in the language the screen is read in.
 *
 * Two reports need this — payments by country and visitors by country — and
 * they get it from the same six lines rather than from two copies that drift
 * the first time one of them learns about an installation without `intl`.
 */
final class Countries
{
    public static function name(string $code): string
    {
        $code = strtoupper(trim($code));

        if ($code === '' || ! class_exists(Locale::class)) {
            return $code;
        }

        $name = Locale::getDisplayRegion('-'.$code, app()->getLocale());

        return ($name === '' || $name === false) ? $code : $name;
    }
}
