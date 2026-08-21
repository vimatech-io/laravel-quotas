<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use VimaTech\LaravelQuotas\Events\SubscriptionCancelled;
use VimaTech\LaravelQuotas\Events\SubscriptionCreated;
use VimaTech\LaravelQuotas\Models\Plan;
use VimaTech\LaravelQuotas\Tests\Fixtures\User;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures');

    Plan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'monthly_price' => 2900,
        'currency' => 'USD',
        'features' => ['agents'],
        'limits' => ['executions' => 1000],
        'is_active' => true,
        'trial_days' => 0,
    ]);

    $this->user = User::create([
        'name' => 'John',
        'email' => 'john@example.com',
    ]);
});

it('dispatches SubscriptionCreated event', function () {
    Event::fake([SubscriptionCreated::class]);

    $this->user->subscribe('pro');

    Event::assertDispatched(SubscriptionCreated::class, function ($event) {
        return $event->billable->is($this->user);
    });
});

it('dispatches SubscriptionCancelled event', function () {
    Event::fake([SubscriptionCancelled::class]);

    $this->user->subscribe('pro');
    $this->user->cancelSubscription();

    Event::assertDispatched(SubscriptionCancelled::class);
});
