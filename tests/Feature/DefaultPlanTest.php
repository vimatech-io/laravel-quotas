<?php

declare(strict_types=1);

use VimaTech\LaravelQuotas\DTOs\PlanData;
use VimaTech\LaravelQuotas\Exceptions\BillableNotCashierReadyException;
use VimaTech\LaravelQuotas\Exceptions\PlanNotFoundException;
use VimaTech\LaravelQuotas\Exceptions\UsageLimitExceededException;
use VimaTech\LaravelQuotas\Managers\PlanManager;
use VimaTech\LaravelQuotas\Managers\QuotaManager;
use VimaTech\LaravelQuotas\Models\Usage;
use VimaTech\LaravelQuotas\Tests\Fixtures\CashierUser;
use VimaTech\LaravelQuotas\Tests\Fixtures\DeclaredExceptionCatcher;
use VimaTech\LaravelQuotas\Tests\Fixtures\FakeCashierSubscription;
use VimaTech\LaravelQuotas\Tests\Fixtures\PaddleWithLocalFallbackResolver;
use VimaTech\LaravelQuotas\Tests\Fixtures\Team;
use VimaTech\LaravelQuotas\Tests\Fixtures\TeamOwnersResolver;
use VimaTech\LaravelQuotas\Tests\Fixtures\User;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures');

    config()->set('quotas.subscriptions.resolver', 'cashier-paddle');
    config()->set('quotas.subscriptions.default_plan', 'free');

    app(PlanManager::class)->create(new PlanData(
        name: 'Free',
        slug: 'free',
        features: ['invoice_issuing'],
        limits: ['invoice_issuing' => 5],
        sortOrder: 0,
    ));

    app(PlanManager::class)->create(new PlanData(
        name: 'Pro',
        slug: 'pro',
        monthlyPrice: 1900,
        features: ['invoice_issuing'],
        limits: ['invoice_issuing' => Usage::UNLIMITED],
        gatewayPrices: ['paddle' => ['pri_pro']],
        sortOrder: 1,
    ));
});

function paddleUser(?string $price = null, string $email = 'ada@example.test'): CashierUser
{
    $user = CashierUser::query()->create(['name' => 'Ada', 'email' => $email]);

    if ($price !== null) {
        $user->fakeSubscription = new FakeCashierSubscription(['created_at' => now()]);
        $user->fakeSubscription->setAttribute('items', [(object) ['price_id' => $price]]);
    }

    return $user;
}

it('gives a billable without a subscription the default plan', function () {
    $user = paddleUser();

    $user->incrementUsage('invoice_issuing', 5);

    expect($user->currentPlan()?->slug)->toBe('free')
        ->and($user->isSubscribed())->toBeFalse()
        ->and($user->isSubscribedTo('free'))->toBeFalse()
        ->and($user->canUse('invoice_issuing'))->toBeFalse()
        ->and(fn () => $user->incrementUsage('invoice_issuing'))->toThrow(UsageLimitExceededException::class);
});

it('prefers the plan a subscription resolves to', function () {
    expect(paddleUser('pri_pro')->currentPlan()?->slug)->toBe('pro');
});

it('does not hand the default plan to a subscriber whose price maps to no plan', function () {
    $user = paddleUser('pri_unmapped');

    expect($user->isSubscribed())->toBeTrue()
        ->and($user->currentPlan())->toBeNull()
        ->and($user->canUse('invoice_issuing'))->toBeFalse();
});

it('refuses a default plan that does not exist', function () {
    config()->set('quotas.subscriptions.default_plan', 'fre');

    paddleUser()->canUse('invoice_issuing');
})->throws(PlanNotFoundException::class, 'The default plan [fre] set in quotas.subscriptions.default_plan does not exist.');

it('lets a missing default plan escape an increment, where a consumer can catch it', function () {
    config()->set('quotas.subscriptions.default_plan', 'fre');
    $catcher = app(DeclaredExceptionCatcher::class);

    expect($catcher->increment(paddleUser(), 'invoice_issuing'))->toBeInstanceOf(PlanNotFoundException::class)
        ->and($catcher->incrementUsage(paddleUser(email: 'b@example.test'), 'invoice_issuing'))->toBeInstanceOf(PlanNotFoundException::class)
        ->and(Usage::query()->count())->toBe(0);
});

it('lets a billable Cashier cannot read escape a feature check', function () {
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);

    expect(app(DeclaredExceptionCatcher::class)->canUse($user, 'invoice_issuing'))
        ->toBeInstanceOf(BillableNotCashierReadyException::class);
});

it('leaves a billable without a subscription planless when no default is set', function () {
    config()->set('quotas.subscriptions.default_plan', null);

    expect(paddleUser()->currentPlan())->toBeNull();
});

it('applies the default plan behind a custom resolver', function () {
    config()->set('quotas.subscriptions.resolver', PaddleWithLocalFallbackResolver::class);

    expect(paddleUser()->currentPlan()?->slug)->toBe('free');
});

it('reads the configured Cashier subscription type from a Cashier resolver a custom one composes', function () {
    config()->set('quotas.subscriptions.resolver', PaddleWithLocalFallbackResolver::class);
    config()->set('quotas.subscriptions.cashier_type', 'invoicing');

    $user = paddleUser('pri_pro');
    $user->currentPlan();

    expect($user->requestedType)->toBe('invoicing');
});

it('counts per team while the owners hold the subscriptions', function () {
    config()->set('quotas.subscriptions.resolver', TeamOwnersResolver::class);
    config()->set('quotas.quotas.feature_anchors', ['invoice_issuing' => 'calendar']);

    $team = Team::query()->create(['name' => 'Acme']);
    $team->owners = [paddleUser(email: 'a@example.test'), paddleUser(email: 'b@example.test')];

    $team->incrementUsage('invoice_issuing', 5);

    expect($team->currentPlan()?->slug)->toBe('free')
        ->and($team->canUse('invoice_issuing'))->toBeFalse()
        ->and(app(QuotaManager::class)->periodEndsAt($team, 'invoice_issuing')?->day)->toBe(1);

    $team->owners[1]->fakeSubscription = new FakeCashierSubscription(['created_at' => now()]);
    $team->owners[1]->fakeSubscription->setAttribute('items', [(object) ['price_id' => 'pri_pro']]);
    app(QuotaManager::class)->forgetPlan($team);

    expect($team->currentPlan()?->slug)->toBe('pro')
        ->and($team->canUse('invoice_issuing'))->toBeTrue()
        ->and(Usage::query()->where('billable_type', $team->getMorphClass())->value('used'))->toBe(5);
});
