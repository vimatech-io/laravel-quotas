<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Support;

use VimaTech\LaravelQuotas\Contracts\SubscriptionResolverInterface;
use VimaTech\LaravelQuotas\Exceptions\LocalSubscriptionsDisabledException;
use VimaTech\LaravelQuotas\Resolvers\LocalSubscriptionResolver;

/**
 * Refuses writes to the local subscriptions table when something else owns the
 * subscription.
 *
 * Without this, `$user->subscribe('pro')` against a Cashier-backed setup would
 * quietly hand out a plan nobody is paying for — the exact failure mode this
 * package exists to avoid. Better a loud exception at the call site.
 */
final class LocalSubscriptionGuard
{
    public static function ensureEnabled(string $method): void
    {
        // Resolved from the container on demand rather than injected: the local
        // resolver itself depends on the subscription layer this guards.
        $resolver = app(SubscriptionResolverInterface::class);

        if (! $resolver instanceof LocalSubscriptionResolver) {
            throw LocalSubscriptionsDisabledException::forMethod($method);
        }
    }
}
