<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for a Cashier subscription model.
 *
 * Cashier is an optional dependency, so the resolvers read subscriptions
 * through the narrow surface both Cashier Stripe and Cashier Paddle expose:
 * valid(), onTrial(), created_at, a price column and/or items. This fixture
 * reproduces exactly that surface, and nothing more.
 */
class FakeCashierSubscription extends Model
{
    protected $guarded = [];

    public bool $isValid = true;

    public bool $isOnTrial = false;

    public function valid(): bool
    {
        return $this->isValid;
    }

    public function onTrial(): bool
    {
        return $this->isOnTrial;
    }
}
