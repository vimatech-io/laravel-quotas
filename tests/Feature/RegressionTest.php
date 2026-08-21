<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use VimaTech\LaravelQuotas\Contracts\QuotaAware;
use VimaTech\LaravelQuotas\Contracts\SubscriptionResolverInterface;
use VimaTech\LaravelQuotas\Enums\BillingInterval;
use VimaTech\LaravelQuotas\Enums\SubscriptionStatus;
use VimaTech\LaravelQuotas\Exceptions\AlreadySubscribedException;
use VimaTech\LaravelQuotas\Exceptions\PlanNotFoundException;
use VimaTech\LaravelQuotas\Exceptions\SubscriptionNotCancelledException;
use VimaTech\LaravelQuotas\Facades\Quotas;
use VimaTech\LaravelQuotas\Managers\QuotaManager;
use VimaTech\LaravelQuotas\Models\Plan;
use VimaTech\LaravelQuotas\Models\Subscription;
use VimaTech\LaravelQuotas\Models\Usage;
use VimaTech\LaravelQuotas\Quota\QuotaPeriod;
use VimaTech\LaravelQuotas\Tests\Fixtures\User;

/*
|--------------------------------------------------------------------------
| Regressions
|--------------------------------------------------------------------------
|
| Each of these reproduces a defect that shipped once. They are grouped here
| rather than scattered through the behavioural suites so that the reason they
| exist stays legible: none of them is testing a feature, every one is holding
| a door shut.
|
*/

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures');
});

function regressionUser(string $email = 'reg@example.com'): User
{
    return User::query()->create(['name' => 'Regression', 'email' => $email]);
}

it('honours the reset interval from the config file as published', function () {
    // The bug: the code read quotas.reset_interval while the published config
    // defined quotas.quotas.reset_interval, so every interval silently became
    // monthly. The old test set the flat key by hand and never noticed.
    config()->set('quotas', require __DIR__.'/../../config/quotas.php');
    config()->set('quotas.quotas.reset_interval', 'daily');

    $start = QuotaPeriod::fromConfig()->currentStart(
        CarbonImmutable::parse('2026-01-01 00:00'),
        CarbonImmutable::parse('2026-01-05 12:00'),
    );

    expect($start?->toDateString())->toBe('2026-01-05');
});

it('registers the reset sweep only when the published config asks for it', function () {
    config()->set('quotas', require __DIR__.'/../../config/quotas.php');

    expect(config('quotas.quotas.schedule_reset'))->toBeTrue();

    config()->set('quotas.quotas.schedule_reset', false);

    expect(config('quotas.quotas.schedule_reset'))->toBeFalse();
});

it('revokes entitlements once the term has run out', function () {
    // The bug: scopeActive() checked only the status, so an Active row whose
    // ends_at had passed went on resolving its plan forever.
    $plan = Plan::query()->create(['name' => 'Pro', 'slug' => 'pro', 'features' => ['a'], 'limits' => ['a' => 5]]);
    $user = regressionUser();

    Subscription::query()->create([
        'billable_type' => $user->getMorphClass(),
        'billable_id' => $user->getKey(),
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->subDay(),
    ]);

    expect(app(QuotaManager::class)->currentPlan($user))->toBeNull()
        ->and($user->isSubscribed())->toBeFalse();
});

it('keeps entitlements through the cancellation grace period', function () {
    Plan::query()->create(['name' => 'Pro', 'slug' => 'pro', 'features' => ['a'], 'limits' => ['a' => 5]]);
    $user = regressionUser();
    $user->subscribe('pro');

    $user->cancelSubscription();
    app(QuotaManager::class)->forgetPlan($user);

    // Cancelled at period end: paid for, still owed.
    expect($user->currentPlan()?->slug)->toBe('pro');
});

it('gives a yearly subscriber the year they paid for when they cancel', function () {
    // The bug: ends_at was hardcoded to now()->addMonth(), which cost a yearly
    // subscriber eleven months of access they had already been charged for.
    Plan::query()->create(['name' => 'Yearly', 'slug' => 'yearly', 'yearly_price' => 10000, 'features' => ['a'], 'limits' => ['a' => 5]]);
    $user = regressionUser();
    $user->subscribe('yearly', BillingInterval::Yearly);

    $subscription = $user->cancelSubscription();

    expect((int) now()->diffInDays($subscription->ends_at, absolute: true))->toBeGreaterThan(300);
});

it('cancels a monthly subscriber at the end of their month', function () {
    Plan::query()->create(['name' => 'Pro', 'slug' => 'pro', 'features' => ['a'], 'limits' => ['a' => 5]]);
    $user = regressionUser();
    $user->subscribe('pro');

    $subscription = $user->cancelSubscription();

    expect((int) now()->diffInDays($subscription->ends_at, absolute: true))->toBeGreaterThan(25)
        ->and((int) now()->diffInDays($subscription->ends_at, absolute: true))->toBeLessThan(35);
});

it('refuses a second subscription instead of creating a duplicate', function () {
    // The bug: nothing guarded the write, so a double-clicked form produced two
    // active rows and findActive() picked between them arbitrarily.
    Plan::query()->create(['name' => 'Pro', 'slug' => 'pro', 'features' => ['a'], 'limits' => ['a' => 5]]);
    $user = regressionUser();
    $user->subscribe('pro');

    expect(fn () => $user->subscribe('pro'))->toThrow(AlreadySubscribedException::class)
        ->and(Subscription::query()->forBillable($user)->active()->count())->toBe(1);
});

it('forgets the memoised plan when swapping to a plan with no limits', function () {
    // The bug: forgetPlan() sat inside foreach ($limits), so a downgrade to a
    // plan that meters nothing never purged anything and the old plan stood.
    Plan::query()->create(['name' => 'Pro', 'slug' => 'pro', 'features' => ['a'], 'limits' => ['a' => 5]]);
    Plan::query()->create(['name' => 'Free', 'slug' => 'free', 'features' => [], 'limits' => []]);

    $user = regressionUser();
    $user->subscribe('pro');

    $quotas = app(QuotaManager::class);
    expect($quotas->currentPlan($user)?->slug)->toBe('pro');

    $user->swapPlan('free');

    expect($quotas->currentPlan($user)?->slug)->toBe('free');
});

it('revokes the counters of features a downgrade drops', function () {
    // The bug: only the new plan's limits were walked, so a feature that
    // disappeared kept its old ceiling in the usage table indefinitely.
    Plan::query()->create(['name' => 'Pro', 'slug' => 'pro', 'features' => ['a', 'b'], 'limits' => ['a' => 100, 'b' => 100]]);
    Plan::query()->create(['name' => 'Free', 'slug' => 'free', 'features' => ['a'], 'limits' => ['a' => 5]]);

    $user = regressionUser();
    $user->subscribe('pro');
    $user->swapPlan('free');

    expect(Usage::query()->forBillable($user)->forFeature('b')->exists())->toBeFalse()
        ->and(Usage::query()->forBillable($user)->forFeature('a')->first()?->limit)->toBe(5);
});

it('refuses to sell a deactivated plan', function () {
    // The bug: findBySlug() ignored is_active, so a retired plan stayed
    // subscribable by slug — including through the portal's public route.
    Plan::query()->create(['name' => 'Retired', 'slug' => 'retired', 'is_active' => false, 'features' => ['a'], 'limits' => ['a' => 5]]);
    $user = regressionUser();

    expect(fn () => $user->subscribe('retired'))->toThrow(PlanNotFoundException::class);
});

it('still resolves a retired plan for someone already on it', function () {
    // The other half of the same fix: grandfathered subscribers keep what they
    // bought, so reading a plan and selling one stay separate questions.
    $plan = Plan::query()->create(['name' => 'Retired', 'slug' => 'retired', 'features' => ['a'], 'limits' => ['a' => 5]]);
    $user = regressionUser();
    $user->subscribe('retired');

    $plan->update(['is_active' => false]);
    app(QuotaManager::class)->forgetPlan($user);

    expect($user->currentPlan()?->slug)->toBe('retired');
});

it('measures quota periods from the billing period, not the creation date', function () {
    Plan::query()->create(['name' => 'Pro', 'slug' => 'pro', 'features' => ['a'], 'limits' => ['a' => 5]]);
    $user = regressionUser();
    $subscription = $user->subscribe('pro');

    // A plan change moved the billing cycle; the row was created earlier.
    $subscription->update(['current_period_start' => now()->subDays(3)]);

    $anchor = app(SubscriptionResolverInterface::class)->anchor($user);

    expect($anchor?->toDateString())->toBe(now()->subDays(3)->toDateString());
});

it('falls back to the configured trial when the plan names none', function () {
    // The bug: `$plan->trial_days ?? config(...)` never fired, because the
    // column is not nullable and defaults to 0.
    config()->set('quotas.trial_days', 14);
    Plan::query()->create(['name' => 'Pro', 'slug' => 'pro', 'features' => ['a'], 'limits' => ['a' => 5], 'trial_days' => 0]);

    $subscription = regressionUser()->subscribe('pro');

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at)->not->toBeNull();
});

it('keeps a past due subscription alive for the configured grace period', function () {
    config()->set('quotas.subscriptions.past_due_grace_days', 5);

    $plan = Plan::query()->create(['name' => 'Pro', 'slug' => 'pro', 'features' => ['a'], 'limits' => ['a' => 5]]);
    $user = regressionUser();

    Subscription::query()->create([
        'billable_type' => $user->getMorphClass(),
        'billable_id' => $user->getKey(),
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::PastDue,
        'past_due_since' => now()->subDays(2),
    ]);

    expect($user->currentPlan()?->slug)->toBe('pro');
});

it('cuts a past due subscription off once the grace period lapses', function () {
    config()->set('quotas.subscriptions.past_due_grace_days', 5);

    $plan = Plan::query()->create(['name' => 'Pro', 'slug' => 'pro', 'features' => ['a'], 'limits' => ['a' => 5]]);
    $user = regressionUser();

    Subscription::query()->create([
        'billable_type' => $user->getMorphClass(),
        'billable_id' => $user->getKey(),
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::PastDue,
        'past_due_since' => now()->subDays(9),
    ]);

    expect($user->currentPlan())->toBeNull();
});

it('throws a package exception when there is nothing to resume', function () {
    // firstOrFail() used to leak a ModelNotFoundException here, which most
    // handlers render as a 404 — nonsense for a billing action.
    Plan::query()->create(['name' => 'Pro', 'slug' => 'pro', 'features' => ['a'], 'limits' => ['a' => 5]]);
    $user = regressionUser();

    expect(fn () => $user->resumeSubscription())
        ->toThrow(SubscriptionNotCancelledException::class);
});

it('resumes through the facade like it cancels through it', function () {
    Plan::query()->create(['name' => 'Pro', 'slug' => 'pro', 'features' => ['a'], 'limits' => ['a' => 5]]);
    $user = regressionUser();
    $user->subscribe('pro');

    Quotas::cancel($user);
    $resumed = Quotas::resume($user);

    expect($resumed->status)->toBe(SubscriptionStatus::Active)
        ->and($resumed->ends_at)->toBeNull();
});

it('exposes every read-side trait method through the QuotaAware contract', function () {
    // Code in consuming projects is typed against the contract; a method the
    // trait has but the contract lacks fails their static analysis.
    $contract = new ReflectionClass(QuotaAware::class);

    foreach (['hasUnlimited', 'isSubscribedTo', 'resetUsage', 'usageOf', 'remainingUsage'] as $method) {
        expect($contract->hasMethod($method))->toBeTrue("QuotaAware is missing {$method}()");
    }
});
