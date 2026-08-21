<?php

declare(strict_types=1);

use VimaTech\LaravelQuotas\Enums\BillingInterval;
use VimaTech\LaravelQuotas\Enums\SubscriptionStatus;

it('subscription status enum works correctly', function () {
    expect(SubscriptionStatus::Active->isActive())->toBeTrue()
        ->and(SubscriptionStatus::Trialing->isActive())->toBeTrue()
        ->and(SubscriptionStatus::Cancelled->isActive())->toBeFalse()
        ->and(SubscriptionStatus::Cancelled->isCancelled())->toBeTrue()
        ->and(SubscriptionStatus::Expired->isExpired())->toBeTrue();
});

it('billing interval enum values are correct', function () {
    expect(BillingInterval::Monthly->value)->toBe('monthly')
        ->and(BillingInterval::Yearly->value)->toBe('yearly')
        ->and(BillingInterval::Lifetime->value)->toBe('lifetime');
});
