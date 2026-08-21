<?php

declare(strict_types=1);

use VimaTech\LaravelQuotas\DTOs\PlanData;
use VimaTech\LaravelQuotas\Enums\BillingInterval;
use VimaTech\LaravelQuotas\Managers\PlanManager;
use VimaTech\LaravelQuotas\Managers\QuotaManager;
use VimaTech\LaravelQuotas\Tests\Fixtures\CashierUser;
use VimaTech\LaravelQuotas\Tests\Fixtures\FakeCashierSubscription;
use VimaTech\LaravelQuotas\Tests\Fixtures\PaddleWithLocalFallbackResolver;

/*
|--------------------------------------------------------------------------
| Composite resolver — the AppSumo scenario
|--------------------------------------------------------------------------
|
| One application, two subscription sources: paying customers through Cashier
| Paddle, lifetime-deal customers redeemed into the local table. Everything
| downstream — features, quotas, resets — must not care who came from where.
|
*/

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures');

    config()->set('quotas.subscriptions.resolver', PaddleWithLocalFallbackResolver::class);

    app(PlanManager::class)->create(new PlanData(
        name: 'Pro',
        slug: 'pro',
        monthlyPrice: 1900,
        features: ['projects', 'ai_tokens'],
        limits: ['projects' => -1, 'ai_tokens' => 100_000],
        gatewayPrices: ['paddle' => ['pri_pro_monthly']],
    ));

    app(PlanManager::class)->create(new PlanData(
        name: 'Lifetime (AppSumo)',
        slug: 'sumo-lifetime',
        features: ['projects', 'ai_tokens'],
        limits: ['projects' => 10, 'ai_tokens' => 5_000],
        metadata: ['bespoke' => true],
    ));
});

function paddleCustomer(): CashierUser
{
    $user = CashierUser::query()->create(['name' => 'Paddle', 'email' => 'paddle@example.test']);

    $user->fakeSubscription = new FakeCashierSubscription(['created_at' => now()]);
    $user->fakeSubscription->setAttribute('items', [(object) ['price_id' => 'pri_pro_monthly']]);

    return $user;
}

it('redeems a lifetime deal into the local table while Paddle owns the paying customers', function () {
    $sumo = CashierUser::query()->create(['name' => 'Sumo', 'email' => 'sumo@example.test']);

    // The redemption writes locally — allowed because the composite resolver
    // declares LocalSubscriptionSource.
    $subscription = $sumo->subscribe('sumo-lifetime', BillingInterval::Lifetime);

    expect($subscription->current_period_end)->toBeNull()
        ->and($sumo->currentPlan()?->slug)->toBe('sumo-lifetime')
        ->and($sumo->hasFeature('ai_tokens'))->toBeTrue()
        ->and($sumo->remainingUsage('ai_tokens'))->toBe(5_000);
});

it('resolves a Paddle subscriber through Paddle with the same code path', function () {
    $customer = paddleCustomer();

    expect($customer->currentPlan()?->slug)->toBe('pro')
        ->and($customer->remainingUsage('ai_tokens'))->toBe(100_000);
});

it('limits AI monthly for a lifetime holder — the plan is forever, the tokens are not', function () {
    $this->travelTo('2026-03-10 09:00:00');
    $sumo = CashierUser::query()->create(['name' => 'Sumo', 'email' => 'sumo@example.test']);
    $sumo->subscribe('sumo-lifetime', BillingInterval::Lifetime);

    $sumo->incrementUsage('ai_tokens', 5_000);
    expect($sumo->canUse('ai_tokens'))->toBeFalse();

    // A year later the subscription still stands, and the month's allowance
    // has come back — measured from the redemption anniversary.
    $this->travelTo('2027-03-10 10:00:00');
    expect($sumo->isSubscribed())->toBeTrue()
        ->and($sumo->remainingUsage('ai_tokens'))->toBe(5_000);
});

it('lets the paid plan win when a lifetime holder upgrades through Paddle', function () {
    $user = paddleCustomer();
    $user->fakeSubscription = null;              // starts as Sumo only
    $user->subscribe('sumo-lifetime', BillingInterval::Lifetime);
    expect($user->currentPlan()?->slug)->toBe('sumo-lifetime');

    // Later buys Pro through Paddle: the paid plan takes precedence.
    $user->fakeSubscription = new FakeCashierSubscription(['created_at' => now()]);
    $user->fakeSubscription->setAttribute('items', [(object) ['price_id' => 'pri_pro_monthly']]);
    app(QuotaManager::class)->forgetPlan($user);

    expect($user->currentPlan()?->slug)->toBe('pro')
        ->and($user->remainingUsage('ai_tokens'))->toBe(100_000);
});
