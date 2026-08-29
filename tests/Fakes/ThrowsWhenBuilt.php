<?php

namespace Goldnead\StatamicInsights\Tests\Fakes;

/**
 * A metric whose constructor fails.
 *
 * The registry resolves lazily, so this only blows up when somebody asks — and
 * that somebody is a screen mid-render. It must be absent, not fatal.
 */
class ThrowsWhenBuilt
{
    public function __construct()
    {
        throw new \RuntimeException('laesst sich nicht bauen');
    }
}
