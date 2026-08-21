<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Resolvers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use VimaTech\LaravelQuotas\Contracts\LocalSubscriptionSource;
use VimaTech\LaravelQuotas\Contracts\SubscriptionResolverInterface;
use VimaTech\LaravelQuotas\Managers\SubscriptionManager;
use VimaTech\LaravelQuotas\Models\Plan;

/**
 * Reads the subscriptions this package stores itself.
 *
 * For accounts nobody charges through a provider: seats granted by hand,
 * self-hosted licences, internal tenants, free tiers. It is also the only
 * resolver that supports several billable types at once, since the local table
 * is polymorphic where Cashier's is not.
 */
final class LocalSubscriptionResolver implements LocalSubscriptionSource, SubscriptionResolverInterface
{
    public function __construct(
        private readonly SubscriptionManager $subscriptions,
    ) {}

    public function resolvePlan(Model $billable): ?Plan
    {
        return $this->subscriptions->findActive($billable)?->plan;
    }

    public function isSubscribed(Model $billable): bool
    {
        return $this->subscriptions->findActive($billable) !== null;
    }

    public function onTrial(Model $billable): bool
    {
        return $this->subscriptions->isOnTrial($billable);
    }

    /**
     * The date quota periods are measured from: the start of the period the
     * customer is currently being billed for, falling back to when the
     * subscription was created for rows that predate the column.
     */
    public function anchor(Model $billable): ?CarbonImmutable
    {
        $subscription = $this->subscriptions->findActive($billable);

        if ($subscription === null) {
            return null;
        }

        $anchor = $subscription->current_period_start ?? $subscription->created_at;

        return $anchor === null ? null : CarbonImmutable::instance($anchor);
    }
}
