<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use VimaTech\LaravelQuotas\DTOs\PlanData;
use VimaTech\LaravelQuotas\Events\UsageReset;
use VimaTech\LaravelQuotas\Managers\PlanManager;
use VimaTech\LaravelQuotas\Models\Usage;
use VimaTech\LaravelQuotas\Tests\Fixtures\User;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures');

    app(PlanManager::class)->create(new PlanData(
        name: 'Pro',
        slug: 'pro',
        monthlyPrice: 2900,
        features: ['executions'],
        limits: ['executions' => 5],
    ));

    $this->user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
});

it('gives the allowance back once the billing month turns over', function () {
    $this->travelTo('2026-01-20 09:00:00');
    $this->user->subscribe('pro');

    for ($i = 0; $i < 5; $i++) {
        $this->user->incrementUsage('executions');
    }

    expect($this->user->canUse('executions'))->toBeFalse();

    // Still inside the same billing month, even though the calendar turned over.
    $this->travelTo('2026-02-05 09:00:00');
    expect($this->user->usageOf('executions'))->toBe(5)
        ->and($this->user->canUse('executions'))->toBeFalse();

    // The anniversary comes round: the allowance is back.
    $this->travelTo('2026-02-20 10:00:00');
    expect($this->user->usageOf('executions'))->toBe(0)
        ->and($this->user->canUse('executions'))->toBeTrue()
        ->and($this->user->remainingUsage('executions'))->toBe(5);
});

it('rolls the period over inside the lock when an increment lands on the boundary', function () {
    $this->travelTo('2026-01-20 09:00:00');
    $this->user->subscribe('pro');

    for ($i = 0; $i < 5; $i++) {
        $this->user->incrementUsage('executions');
    }

    // Nothing reads the counter before the increment: the reset has to happen
    // in the write path too, not only on the lazy read path.
    $this->travelTo('2026-02-20 10:00:00');
    $this->user->incrementUsage('executions');

    $usage = Usage::query()->forBillable($this->user)->forFeature('executions')->first();

    expect($usage->used)->toBe(1);
});

it('resets untouched counters through the sweep command', function () {
    $this->travelTo('2026-01-20 09:00:00');
    $this->user->subscribe('pro');
    $this->user->incrementUsage('executions', 3);

    $this->travelTo('2026-02-20 10:00:00');

    $this->artisan('quotas:reset')
        ->expectsOutputToContain('Reset 1 usage counter(s).')
        ->assertSuccessful();

    $usage = Usage::query()->forBillable($this->user)->forFeature('executions')->first();

    expect($usage->used)->toBe(0)
        ->and($usage->reset_at->toDateTimeString())->toBe('2026-02-20 10:00:00');
});

it('leaves counters alone when their period is still running', function () {
    $this->travelTo('2026-01-20 09:00:00');
    $this->user->subscribe('pro');
    $this->user->incrementUsage('executions', 3);

    $this->travelTo('2026-02-05 10:00:00');

    $this->artisan('quotas:reset')
        ->expectsOutputToContain('No usage counters needed resetting.')
        ->assertSuccessful();

    expect($this->user->usageOf('executions'))->toBe(3);
});

it('never rolls over on the manual interval', function () {
    config()->set('quotas.quotas.reset_interval', 'manual');

    $this->travelTo('2026-01-20 09:00:00');
    $this->user->subscribe('pro');
    $this->user->incrementUsage('executions', 3);

    $this->travelTo('2027-06-01 10:00:00');

    expect($this->user->usageOf('executions'))->toBe(3);
});

it('fires an event carrying what the counter stood at', function () {
    $this->travelTo('2026-01-20 09:00:00');
    $this->user->subscribe('pro');
    $this->user->incrementUsage('executions', 4);

    Event::fake([UsageReset::class]);

    $this->travelTo('2026-02-20 10:00:00');
    $this->user->usageOf('executions');

    Event::assertDispatched(UsageReset::class, function (UsageReset $event): bool {
        return $event->feature === 'executions'
            && $event->previousUsage === 4
            && $event->billable->is($this->user);
    });
});

it('resets a weekly feature while its monthly neighbour keeps counting', function () {
    config()->set('quotas.quotas.reset_interval', 'monthly');
    config()->set('quotas.quotas.feature_intervals', ['ai_tokens' => 'weekly']);

    app(PlanManager::class)->create(new PlanData(
        name: 'Free',
        slug: 'free',
        features: ['ai_tokens', 'exports'],
        limits: ['ai_tokens' => 2000, 'exports' => 10],
    ));

    $this->travelTo('2026-01-06 09:00:00'); // a Tuesday
    $user = User::query()->create(['name' => 'Sam', 'email' => 'sam@example.test']);
    $user->subscribe('free');

    $user->incrementUsage('ai_tokens', 1500);
    $user->incrementUsage('exports', 4);

    // The following Tuesday: the token week has turned, the export month has not.
    $this->travelTo('2026-01-13 10:00:00');

    expect($user->usageOf('ai_tokens'))->toBe(0)
        ->and($user->remainingUsage('ai_tokens'))->toBe(2000)
        ->and($user->usageOf('exports'))->toBe(4);
});
