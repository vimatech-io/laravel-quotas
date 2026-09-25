<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use VimaTech\LaravelQuotas\Contracts\SubscriptionResolverInterface;
use VimaTech\LaravelQuotas\Models\Plan;
use VimaTech\LaravelQuotas\Resolvers\CashierPaddleResolver;

/**
 * A team is entitled to the most generous plan among its owners, each of whom
 * pays through Paddle. Generosity is the application's call; here it is the
 * plan's sort_order.
 */
final class TeamOwnersResolver implements SubscriptionResolverInterface
{
    public function __construct(
        private readonly CashierPaddleResolver $paddle,
    ) {}

    public function resolvePlan(Model $billable): ?Plan
    {
        $plans = array_filter(array_map($this->paddle->resolvePlan(...), $this->payers($billable)));

        usort($plans, fn (Plan $a, Plan $b): int => $b->sort_order <=> $a->sort_order);

        return $plans[0] ?? null;
    }

    public function isSubscribed(Model $billable): bool
    {
        return array_filter($this->payers($billable), $this->paddle->isSubscribed(...)) !== [];
    }

    public function onTrial(Model $billable): bool
    {
        return array_filter($this->payers($billable), $this->paddle->onTrial(...)) !== [];
    }

    public function anchor(Model $billable): ?CarbonImmutable
    {
        return null;
    }

    /**
     * @return array<int, Model>
     */
    private function payers(Model $billable): array
    {
        return $billable instanceof Team ? $billable->owners : [$billable];
    }
}
