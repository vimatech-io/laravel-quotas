<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use VimaTech\LaravelQuotas\Managers\PlanManager;
use VimaTech\LaravelQuotas\Managers\QuotaManager;
use VimaTech\LaravelQuotas\Models\Plan;
use VimaTech\LaravelQuotas\Tests\Fixtures\User;

it('does not resolve a gateway price from a previous request catalogue', function () {
    Plan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'is_active' => true,
        'gateway_prices' => ['stripe' => ['price_starter']],
    ]);

    // request one memoises the catalogue
    expect(app(PlanManager::class)->findByGatewayPrice('stripe', ['price_starter']))->not->toBeNull()
        ->and(app(PlanManager::class)->findByGatewayPrice('stripe', ['price_pro']))->toBeNull();

    // a bare worker loop terminates the request without dropping scoped instances
    app()->terminate();

    // a price added elsewhere: another process, a deploy, an admin action
    Plan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'is_active' => true,
        'gateway_prices' => ['stripe' => ['price_pro']],
    ]);

    expect(app(PlanManager::class)->findByGatewayPrice('stripe', ['price_pro']))->not->toBeNull();
});

it('does not answer an entitlement from a previous request', function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures');

    Plan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'features' => ['agents'],
        'limits' => ['agents' => 3],
        'is_active' => true,
        'trial_days' => 0,
    ]);

    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $user->subscribe('pro');

    expect($user->isSubscribed())->toBeTrue()
        ->and($user->hasFeature('agents'))->toBeTrue();

    app()->terminate();

    // the subscription ends elsewhere: a webhook on another worker, an admin action
    DB::table('quota_subscriptions')->delete();

    expect($user->isSubscribed())->toBeFalse()
        ->and($user->hasFeature('agents'))->toBeFalse();
});

it('does not measure a quota period from a previous request anchor', function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures');

    Plan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'features' => ['agents'],
        'limits' => ['agents' => 3],
        'is_active' => true,
        'trial_days' => 0,
    ]);

    $this->travelTo('2026-01-20 09:00:00');
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $user->subscribe('pro');
    $user->incrementUsage('agents', 3);

    $this->travelTo('2026-02-03 09:00:00');
    expect($user->usageOf('agents'))->toBe(3);

    app()->terminate();

    // the billing cycle moves elsewhere: a prorated plan change at the provider
    DB::table('quota_subscriptions')->update(['current_period_start' => '2026-02-05 09:00:00']);

    $this->travelTo('2026-03-06 09:00:00');

    expect(app(QuotaManager::class)->periodEndsAt($user, 'agents')?->toDateTimeString())->toBe('2026-04-05 09:00:00');
});

it('forgets the anchor with the plan', function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures');

    Plan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'features' => ['agents'],
        'limits' => ['agents' => 3],
        'is_active' => true,
        'trial_days' => 0,
    ]);

    config()->set('quotas.subscriptions.default_plan', 'pro');

    $this->travelTo('2026-01-20 09:00:00');
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    expect(app(QuotaManager::class)->periodEndsAt($user, 'agents')?->toDateTimeString())->toBe('2026-02-01 00:00:00');

    $user->subscribe('pro');

    expect(app(QuotaManager::class)->periodEndsAt($user, 'agents')?->toDateTimeString())->toBe('2026-02-20 09:00:00');
});
