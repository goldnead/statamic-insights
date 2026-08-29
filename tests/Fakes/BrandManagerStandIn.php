<?php

namespace Goldnead\StatamicInsights\Tests\Fakes;

/**
 * A stand-in for statamic-brand-context's manager, with its four questions.
 *
 * Deliberately no more permissive than the real one: it answers exactly the
 * methods `BrandScope::apply()` asks and nothing else, so a metric that
 * invented a fifth question would fail here rather than pass against a
 * mock that says yes to everything.
 */
class BrandManagerStandIn
{
    public function __construct(
        protected bool $multi = true,
        protected ?int $current = 1,
        protected string $failMode = 'closed',
        protected bool $disabled = false,
    ) {}

    public function scopeIsDisabled(): bool
    {
        return $this->disabled;
    }

    public function multiBrandEnabled(): bool
    {
        return $this->multi;
    }

    public function hasCurrent(): bool
    {
        return $this->current !== null;
    }

    public function currentId(): ?int
    {
        return $this->current;
    }

    public function failMode(): string
    {
        return $this->failMode;
    }
}
