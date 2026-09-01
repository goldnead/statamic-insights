<?php

namespace Goldnead\StatamicInsights\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Which sibling addons are installed and have migrated.
 *
 * The reports this addon ships read the tables of `statamic-payments`,
 * `statamic-offers` and `statamic-entitlements` directly. Every one of those
 * is optional, so every read is guarded here first: a class the sibling
 * ships has to exist — the same `class_exists` probe the rest of the family
 * uses for an optional neighbour — and the table has to be there, because the
 * window between `composer require` and `php artisan migrate` is minutes on a
 * real host and months on a forgotten staging box.
 *
 * Answered by class *name*, never by touching the class: naming
 * `\Goldnead\StatamicPayments\Models\Payment` in a string loads nothing, so an
 * install without that addon never trips over it.
 *
 * The test suite has none of the siblings installed and cannot get them
 * without dragging three packages into `require-dev`. {@see pretend()} lets a
 * test declare one present after creating its table by hand, and
 * {@see forget()} clears that between tests.
 */
final class Neighbours
{
    public const PAYMENTS = 'payments';

    public const OFFERS = 'offers';

    public const ENTITLEMENTS = 'entitlements';

    /** @var array<string, array{class: string, table: string, package: string}> */
    private const KNOWN = [
        self::PAYMENTS => [
            'class' => '\Goldnead\StatamicPayments\Models\Payment',
            'table' => 'payments',
            'package' => 'goldnead/statamic-payments',
        ],
        self::OFFERS => [
            'class' => '\Goldnead\StatamicOffers\Models\Offer',
            'table' => 'offers',
            'package' => 'goldnead/statamic-offers',
        ],
        self::ENTITLEMENTS => [
            'class' => '\Goldnead\Entitlements\Models\Entitlement',
            'table' => 'entitlements',
            'package' => 'goldnead/statamic-entitlements',
        ],
    ];

    /** @var array<string, bool> */
    private static array $pretended = [];

    public static function installed(string $neighbour): bool
    {
        if (array_key_exists($neighbour, self::$pretended)) {
            return self::$pretended[$neighbour];
        }

        $known = self::KNOWN[$neighbour] ?? null;

        if ($known === null) {
            return false;
        }

        return class_exists($known['class']) && Schema::hasTable($known['table']);
    }

    /** The Composer name, for the sentence a screen shows when it is missing. */
    public static function package(string $neighbour): ?string
    {
        return self::KNOWN[$neighbour]['package'] ?? null;
    }

    /** For tests: declare a neighbour present or absent regardless of the autoloader. */
    public static function pretend(string $neighbour, bool $installed = true): void
    {
        self::$pretended[$neighbour] = $installed;
    }

    public static function forget(): void
    {
        self::$pretended = [];
    }
}
