<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use VimaTech\LaravelQuotas\Contracts\LocalSubscriptionSource;
use VimaTech\LaravelQuotas\Contracts\SubscriptionResolverInterface;
use VimaTech\LaravelQuotas\Models\Plan;
use VimaTech\LaravelQuotas\Resolvers\CashierPaddleResolver;
use VimaTech\LaravelQuotas\Resolvers\LocalSubscriptionResolver;

/**
 * The AppSumo shape: paying customers come through Paddle, lifetime-deal
 * customers are redeemed into the local table. Paddle wins when both exist,
 * so a lifetime holder who later buys a paid plan gets the paid plan.
 *
 * Declaring LocalSubscriptionSource is what authorises subscribe() and
 * friends to write the local table while this resolver is active.
 */
final class PaddleWithLocalFallbackResolver implements LocalSubscriptionSource, SubscriptionResolverInterface
{
    public function __construct(
        private readonly CashierPaddleResolver $paddle,
        private readonly LocalSubscriptionResolver $local,
    ) {}

    public function resolvePlan(Model $billable): ?Plan
    {
        return $this->paddleFor($billable)?->resolvePlan($billable)
            ?? $this->local->resolvePlan($billable);
    }

    public function isSubscribed(Model $billable): bool
    {
        return ($this->paddleFor($billable)?->isSubscribed($billable) ?? false)
            || $this->local->isSubscribed($billable);
    }

    public function onTrial(Model $billable): bool
    {
        return ($this->paddleFor($billable)?->onTrial($billable) ?? false)
            || $this->local->onTrial($billable);
    }

    public function anchor(Model $billable): ?CarbonImmutable
    {
        return $this->paddleFor($billable)?->isSubscribed($billable) === true
            ? $this->paddle->anchor($billable)
            : $this->local->anchor($billable);
    }

    /**
     * Paddle only answers for billables that are Cashier-ready; anything else
     * belongs to the local table alone.
     */
    private function paddleFor(Model $billable): ?CashierPaddleResolver
    {
        return method_exists($billable, 'subscription') ? $this->paddle : null;
    }
}
