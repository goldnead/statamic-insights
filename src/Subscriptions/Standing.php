<?php

namespace Goldnead\StatamicInsights\Subscriptions;

/**
 * Where an agreement stands, in four words, whatever the provider called it.
 *
 * The status column is the provider's vocabulary passed through
 * `statamic-payments`, and it grows: a pause is being added while this is
 * written, under a name nobody has settled yet. So the figures never compare
 * against a status directly. They ask this class, and this class has one rule
 * for a word it has never seen: **held, not ended**. An unknown status
 * counted as churn would turn a new feature into a wave of cancellations on
 * the day it ships; counted as held, the worst case is a customer who really
 * left sitting one row too long in the paused column, and the screen names
 * the unknown word so somebody can add it here.
 */
final class Standing
{
    /** Running, or agreed and waiting for its first charge. */
    public const LIVE = 'live';

    /** Stopped on purpose, with the intention to continue. Not churn. */
    public const PAUSED = 'paused';

    /** The provider stopped charging after a failed payment. Not churn either, yet. */
    public const SUSPENDED = 'suspended';

    /** Over. For a subscription this is churn; for a payment plan it is done. */
    public const ENDED = 'ended';

    /** Never confirmed by the provider. Not an agreement; read by nothing. */
    public const DRAFT = 'draft';

    /** @var array<string, string> */
    private const KNOWN = [
        'active' => self::LIVE,
        'pending' => self::LIVE,
        'trialing' => self::LIVE,

        'paused' => self::PAUSED,

        'suspended' => self::SUSPENDED,
        'past_due' => self::SUSPENDED,
        'unpaid' => self::SUSPENDED,

        'cancelled' => self::ENDED,
        'canceled' => self::ENDED,
        'completed' => self::ENDED,
        'expired' => self::ENDED,
        'incomplete_expired' => self::ENDED,

        'initiated' => self::DRAFT,
    ];

    public static function of(string $status): string
    {
        return self::KNOWN[self::normalise($status)] ?? self::PAUSED;
    }

    public static function isKnown(string $status): bool
    {
        return array_key_exists(self::normalise($status), self::KNOWN);
    }

    /** Held: neither paying nor gone. */
    public static function isHeld(string $standing): bool
    {
        return $standing === self::PAUSED || $standing === self::SUSPENDED;
    }

    private static function normalise(string $status): string
    {
        return strtolower(trim($status));
    }
}
