<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use VimaTech\LaravelQuotas\Contracts\SubscriptionResolverInterface;
use VimaTech\LaravelQuotas\DTOs\PlanData;
use VimaTech\LaravelQuotas\Exceptions\BillableNotCashierReadyException;
use VimaTech\LaravelQuotas\Exceptions\LocalSubscriptionsDisabledException;
use VimaTech\LaravelQuotas\Exceptions\UsageLimitExceededException;
use VimaTech\LaravelQuotas\Managers\PlanManager;
use VimaTech\LaravelQuotas\Managers\QuotaManager;
use VimaTech\LaravelQuotas\Tests\Fixtures\CashierUser;
use VimaTech\LaravelQuotas\Tests\Fixtures\FakeCashierSubscription;
use VimaTech\LaravelQuotas\Tests\Fixtures\User;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures');

    config()->set('quotas.subscriptions.resolver', 'cashier-stripe');

    app(PlanManager::class)->create(new PlanData(
        name: 'Pro',
        slug: 'pro',
        monthlyPrice: 2900,
        features: ['executions'],
        limits: ['executions' => 5],
        gatewayPrices: ['stripe' => ['price_monthly_pro', 'price_yearly_pro']],
    ));

    $this->user = CashierUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
});

function stripeSubscription(array $attributes = [], bool $valid = true, bool $onTrial = false): FakeCashierSubscription
{
    $subscription = new FakeCashierSubscription([
        'stripe_price' => 'price_monthly_pro',
        'created_at' => now(),
        ...$attributes,
    ]);

    $subscription->isValid = $valid;
    $subscription->isOnTrial = $onTrial;

    return $subscription;
}

it('resolves the plan behind a cashier subscription', function () {
    $this->user->fakeSubscription = stripeSubscription();

    expect($this->user->currentPlan()?->slug)->toBe('pro')
        ->and($this->user->isSubscribed())->toBeTrue()
        ->and($this->user->hasFeature('executions'))->toBeTrue()
        ->and($this->user->canUse('executions'))->toBeTrue();
});

it('matches any price the plan is sold under', function () {
    $this->user->fakeSubscription = stripeSubscription(['stripe_price' => 'price_yearly_pro']);

    expect($this->user->currentPlan()?->slug)->toBe('pro');
});

it('reads prices off the items of a multi-price subscription', function () {
    $subscription = stripeSubscription(['stripe_price' => null]);
    $subscription->setAttribute('items', new Collection([
        (object) ['stripe_price' => 'price_some_addon'],
        (object) ['stripe_price' => 'price_monthly_pro'],
    ]));

    $this->user->fakeSubscription = $subscription;

    expect($this->user->currentPlan()?->slug)->toBe('pro');
});

it('grants nothing when cashier reports the subscription is not valid', function () {
    $this->user->fakeSubscription = stripeSubscription(valid: false);

    expect($this->user->currentPlan())->toBeNull()
        ->and($this->user->isSubscribed())->toBeFalse()
        ->and($this->user->hasFeature('executions'))->toBeFalse()
        ->and($this->user->canUse('executions'))->toBeFalse()
        ->and($this->user->hasReachedLimit('executions'))->toBeTrue();
});

it('grants nothing when there is no subscription at all', function () {
    $this->user->fakeSubscription = null;

    expect($this->user->currentPlan())->toBeNull()
        ->and($this->user->canUse('executions'))->toBeFalse();
});

it('grants nothing when the price maps to no plan', function () {
    $this->user->fakeSubscription = stripeSubscription(['stripe_price' => 'price_unknown']);

    expect($this->user->currentPlan())->toBeNull();
});

it('reports trials from cashier', function () {
    $this->user->fakeSubscription = stripeSubscription(onTrial: true);

    expect($this->user->onTrial())->toBeTrue();
});

it('enforces quotas against the cashier-resolved plan', function () {
    $this->user->fakeSubscription = stripeSubscription();

    for ($i = 0; $i < 5; $i++) {
        $this->user->incrementUsage('executions');
    }

    expect($this->user->remainingUsage('executions'))->toBe(0)
        ->and(fn () => $this->user->incrementUsage('executions'))
        ->toThrow(UsageLimitExceededException::class);
});

it('anchors quota periods to the cashier subscription start date', function () {
    $this->travelTo('2026-03-10 08:00:00');

    $this->user->fakeSubscription = stripeSubscription([
        'created_at' => now(),
    ]);

    $this->user->incrementUsage('executions', 5);
    expect($this->user->canUse('executions'))->toBeFalse();

    $this->travelTo('2026-04-01 08:00:00');
    expect($this->user->usageOf('executions'))->toBe(5);

    $this->travelTo('2026-04-10 09:00:00');
    expect($this->user->usageOf('executions'))->toBe(0);
});

it('asks cashier for the configured subscription type', function () {
    config()->set('quotas.subscriptions.cashier_type', 'main');
    app()->forgetScopedInstances();

    $this->user->fakeSubscription = stripeSubscription();
    $this->user->currentPlan();

    expect($this->user->requestedType)->toBe('main');
});

it('refuses local subscription writes when cashier owns the subscription', function () {
    expect(fn () => $this->user->subscribe('pro'))
        ->toThrow(LocalSubscriptionsDisabledException::class);
});

it('explains itself when the billable is not cashier-ready', function () {
    $plain = User::query()->create(['name' => 'Bob', 'email' => 'bob@example.test']);

    expect(fn () => app(QuotaManager::class)->currentPlan($plain))
        ->toThrow(BillableNotCashierReadyException::class);
});

it('rejects an unknown resolver name', function () {
    config()->set('quotas.subscriptions.resolver', 'nope');
    app()->forgetScopedInstances();

    expect(fn () => app(SubscriptionResolverInterface::class))
        ->toThrow(InvalidArgumentException::class);
});

it('still honours a retired plan someone is subscribed to', function () {
    $plan = app(PlanManager::class)->findBySlug('pro');
    $plan->delete();

    app()->forgetScopedInstances();

    $this->user->fakeSubscription = stripeSubscription();

    expect($this->user->currentPlan()?->slug)->toBe('pro');
});
