<?php

declare(strict_types=1);

use VimaTech\LaravelQuotas\DTOs\PlanData;
use VimaTech\LaravelQuotas\Exceptions\PlanNotFoundException;
use VimaTech\LaravelQuotas\Managers\PlanManager;
use VimaTech\LaravelQuotas\Models\Plan;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures');
    $this->planManager = app(PlanManager::class);
});

it('can create a plan', function () {
    $data = new PlanData(
        name: 'Pro Plan',
        slug: 'pro',
        description: 'For professional teams',
        monthlyPrice: 2900,
        yearlyPrice: 29000,
        currency: 'USD',
        features: ['agents', 'analytics', 'api'],
        limits: ['executions' => 1000, 'agents' => 10],
        metadata: ['popular' => true],
        trialDays: 14,
        sortOrder: 1,
    );

    $plan = $this->planManager->create($data);

    expect($plan)->toBeInstanceOf(Plan::class)
        ->and($plan->name)->toBe('Pro Plan')
        ->and($plan->slug)->toBe('pro')
        ->and($plan->monthly_price)->toBe(2900)
        ->and($plan->yearly_price)->toBe(29000)
        ->and($plan->features)->toBe(['agents', 'analytics', 'api'])
        ->and($plan->limits)->toBe(['executions' => 1000, 'agents' => 10])
        ->and($plan->trial_days)->toBe(14)
        ->and($plan->is_active)->toBeTrue();
});

it('can find a plan by slug', function () {
    Plan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'monthly_price' => 900,
        'currency' => 'USD',
        'features' => ['basic'],
        'limits' => ['executions' => 100],
        'is_active' => true,
    ]);

    $plan = $this->planManager->findBySlug('starter');

    expect($plan->name)->toBe('Starter');
});

it('throws exception for non-existent plan slug', function () {
    $this->planManager->findBySlug('non-existent');
})->throws(PlanNotFoundException::class);

it('can list all active plans', function () {
    Plan::create(['name' => 'Active', 'slug' => 'active', 'is_active' => true, 'sort_order' => 1]);
    Plan::create(['name' => 'Inactive', 'slug' => 'inactive', 'is_active' => false, 'sort_order' => 2]);

    $plans = $this->planManager->all();

    expect($plans)->toHaveCount(1)
        ->and($plans->first()->name)->toBe('Active');
});

it('can deactivate a plan', function () {
    $plan = Plan::create(['name' => 'Test', 'slug' => 'test', 'is_active' => true]);

    $updated = $this->planManager->deactivate($plan);

    expect($updated->is_active)->toBeFalse();
});

it('can check plan features', function () {
    $plan = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'features' => ['agents', 'analytics'],
        'limits' => ['executions' => 1000],
        'is_active' => true,
    ]);

    expect($plan->hasFeature('agents'))->toBeTrue()
        ->and($plan->hasFeature('unknown'))->toBeFalse()
        ->and($plan->getLimit('executions'))->toBe(1000)
        ->and($plan->getLimit('unknown'))->toBeNull();
});
