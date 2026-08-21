<?php

declare(strict_types=1);

use VimaTech\LaravelQuotas\Exceptions\UsageLimitExceededException;
use VimaTech\LaravelQuotas\Managers\QuotaManager;
use VimaTech\LaravelQuotas\Models\Plan;
use VimaTech\LaravelQuotas\Tests\Fixtures\User;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures');

    $this->plan = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'monthly_price' => 2900,
        'currency' => 'USD',
        'features' => ['agents', 'analytics', 'executions'],
        'limits' => ['executions' => 5, 'agents' => 3],
        'is_active' => true,
        'trial_days' => 0,
    ]);

    $this->user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    $this->user->subscribe('pro');
});

it('can check feature availability', function () {
    expect($this->user->hasFeature('agents'))->toBeTrue()
        ->and($this->user->hasFeature('unknown-feature'))->toBeFalse();
});

it('can increment usage', function () {
    $this->user->incrementUsage('executions');

    $quotaManager = app(QuotaManager::class);
    $quotaManager->clearCache($this->user, 'executions');

    expect($quotaManager->getUsage($this->user, 'executions'))->toBe(1);
});

it('can check if limit is reached', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->user->incrementUsage('executions');
    }

    expect($this->user->hasReachedLimit('executions'))->toBeTrue();
});

it('throws exception when exceeding limit', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->user->incrementUsage('executions');
    }

    $this->user->incrementUsage('executions');
})->throws(UsageLimitExceededException::class);

it('can reset usage', function () {
    $this->user->incrementUsage('executions', 3);
    $this->user->resetUsage('executions');

    $quotaManager = app(QuotaManager::class);
    $quotaManager->clearCache($this->user, 'executions');

    expect($quotaManager->getUsage($this->user, 'executions'))->toBe(0);
});

it('can check remaining usage', function () {
    $this->user->incrementUsage('executions', 2);

    expect($this->user->remainingUsage('executions'))->toBe(3);
});

it('reports unlimited for features without limits', function () {
    $plan = Plan::create([
        'name' => 'Unlimited',
        'slug' => 'unlimited',
        'features' => ['agents', 'analytics'],
        'limits' => ['agents' => -1],
        'is_active' => true,
    ]);

    expect($plan->isUnlimited('agents'))->toBeTrue();
});

it('treats a feature the plan does not grant as unavailable, not as unlimited', function () {
    // hasReachedLimit() is sometimes the only gate a caller writes. It has to
    // agree with remaining(), which reports nothing left in the same case.
    expect($this->user->hasFeature('unknown-feature'))->toBeFalse()
        ->and($this->user->hasReachedLimit('unknown-feature'))->toBeTrue()
        ->and($this->user->remainingUsage('unknown-feature'))->toBe(0)
        ->and($this->user->canUse('unknown-feature'))->toBeFalse();
});

it('reports no ceiling as null rather than as a huge number', function () {
    // PHP_INT_MAX used to leak into interfaces as "9223372036854775807 left".
    expect($this->user->remainingUsage('analytics'))->toBeNull()
        ->and($this->user->hasUnlimited('analytics'))->toBeTrue()
        ->and($this->user->hasReachedLimit('analytics'))->toBeFalse()
        ->and($this->user->canUse('analytics'))->toBeTrue();
});

it('gates on the plan when the billable has no subscription at all', function () {
    $stranger = User::create(['name' => 'Nobody', 'email' => 'nobody@example.com']);

    expect($stranger->currentPlan())->toBeNull()
        ->and($stranger->isSubscribed())->toBeFalse()
        ->and($stranger->canUse('executions'))->toBeFalse()
        ->and($stranger->hasReachedLimit('executions'))->toBeTrue()
        ->and($stranger->remainingUsage('executions'))->toBe(0)
        ->and(fn () => $stranger->incrementUsage('executions'))
        ->toThrow(UsageLimitExceededException::class);
});
