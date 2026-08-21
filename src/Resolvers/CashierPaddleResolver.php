<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Resolvers;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves entitlements from a `laravel/cashier-paddle` subscription.
 */
final class CashierPaddleResolver extends CashierResolver
{
    protected function gateway(): string
    {
        return 'paddle';
    }

    /**
     * @return array<int, string>
     */
    protected function priceIds(Model $subscription): array
    {
        // Cashier Paddle always models prices as subscription items, even when
        // there is only one of them.
        $prices = [];

        foreach ($this->items($subscription) as $item) {
            if ($price = $this->attribute($item, 'price_id')) {
                $prices[] = $price;
            }
        }

        return $prices;
    }
}
