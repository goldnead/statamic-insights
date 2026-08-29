<?php

namespace Goldnead\StatamicInsights\Support;

/**
 * How to format a number, not what it means.
 *
 * Deliberately short. Every entry here is a formatting rule the screen has to
 * implement, so the list grows only when a number genuinely cannot be printed
 * with one of the existing ones.
 */
final class Unit
{
    /** A plain count. Printed with thousands separators, no decimals. */
    public const COUNT = 'count';

    /** Minor units of `meta['currency']`. Always an integer — never a float of euros. */
    public const CURRENCY = 'currency';

    /** 0–100. The metric does the dividing; the screen adds the sign. */
    public const PERCENT = 'percent';

    /** Whole seconds. The screen turns them into something readable. */
    public const DURATION = 'duration';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [self::COUNT, self::CURRENCY, self::PERCENT, self::DURATION];
    }
}
