<?php

declare(strict_types=1);

use VimaTech\LaravelQuotas\Enums\SubscriptionStatus;
use VimaTech\LaravelQuotas\Models\Plan;
use VimaTech\LaravelQuotas\Models\Subscription;
use VimaTech\LaravelQuotas\Tests\Fixtures\User;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures');

    $this->plan = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'monthly_price' => 2900,
        'currency' => 'USD',
        'features' => ['agents', 'analytics'],
        'limits' => ['executions' => 1000, 'agents' => 10],
        'is_active' => true,
        'trial_days' => 0,
    ]);

    $this->user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);
});

it('can subscribe a user to a plan', function () {
    $subscription = $this->user->subscribe('pro');

    expect($subscription)->toBeInstanceOf(Subscription::class)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->plan_id)->toBe($this->plan->id)
        ->and($subscription->billable_type)->toBe($this->user->getMorphClass())
        ->and($subscription->billable_id)->toBe($this->user->id);
});

it('can subscribe with trial period', function () {
    $this->plan->update(['trial_days' => 14]);

    $subscription = $this->user->subscribe('pro');

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at)->not->toBeNull()
        ->and($subscription->trial_ends_at->isFuture())->toBeTrue();
});

it('can cancel a subscription', function () {
    $this->user->subscribe('pro');

    $subscription = $this->user->cancelSubscription();

    expect($subscription->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->cancelled_at)->not->toBeNull()
        ->and($subscription->ends_at)->not->toBeNull();
});

it('can check subscription status', function () {
    $this->user->subscribe('pro');

    expect($this->user->isSubscribed())->toBeTrue()
        ->and($this->user->isSubscribedTo('pro'))->toBeTrue()
        ->and($this->user->currentPlan()->slug)->toBe('pro');
});

it('can swap plan', function () {
    $newPlan = Plan::create([
        'name' => 'Enterprise',
        'slug' => 'enterprise',
        'monthly_price' => 9900,
        'currency' => 'USD',
        'features' => ['agents', 'analytics', 'api', 'support'],
        'limits' => ['executions' => 10000, 'agents' => 100],
        'is_active' => true,
    ]);

    $this->user->subscribe('pro');
    $subscription = $this->user->swapPlan('enterprise');

    expect($subscription->plan_id)->toBe($newPlan->id)
        ->and($this->user->currentPlan()->slug)->toBe('enterprise');
});

it('detects grace period', function () {
    $this->user->subscribe('pro');
    $subscription = $this->user->cancelSubscription();

    expect($subscription->isOnGracePeriod())->toBeTrue();
});
