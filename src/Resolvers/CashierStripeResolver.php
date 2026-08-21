<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Resolvers;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves entitlements from a `laravel/cashier` (Stripe) subscription.
 */
final class CashierStripeResolver extends CashierResolver
{
    protected function gateway(): string
    {
        return 'stripe';
    }

    /**
     * @return array<int, string>
     */
    protected function priceIds(Model $subscription): array
    {
        // Single-price subscriptions carry the price on the subscription row.
        if ($price = $this->attribute($subscription, 'stripe_price')) {
            return [$price];
        }

        // Multi-price subscriptions leave that column null and hold one row per
        // price instead. Any of them may be the one mapped to a plan.
        $prices = [];

        foreach ($this->items($subscription) as $item) {
            if ($price = $this->attribute($item, 'stripe_price')) {
                $prices[] = $price;
            }
        }

        return $prices;
    }
}
