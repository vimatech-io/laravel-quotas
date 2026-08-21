<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use VimaTech\LaravelQuotas\Events\UsageLimitReached;
use VimaTech\LaravelQuotas\Exceptions\UsageLimitExceededException;
use VimaTech\LaravelQuotas\Managers\QuotaManager;
use VimaTech\LaravelQuotas\Models\Plan;
use VimaTech\LaravelQuotas\Models\Usage;
use VimaTech\LaravelQuotas\Tests\Fixtures\User;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures');

    $this->plan = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'monthly_price' => 2900,
        'currency' => 'USD',
        'features' => ['executions', 'agents', 'exports'],
        'limits' => ['executions' => 5, 'agents' => 3],
        'is_active' => true,
        'trial_days' => 0,
    ]);

    $this->user = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
    $this->user->subscribe('pro');

    $this->usage = fn (string $feature) => Usage::query()
        ->forBillable($this->user)
        ->forFeature($feature)
        ->first();
});

it('rejects a batch increment that would overshoot the limit', function () {
    // The pre-fix check ignored $amount entirely: it only asked "am I at the
    // limit?", so a single call could jump straight past it.
    expect(fn () => $this->user->incrementUsage('executions', 10))
        ->toThrow(UsageLimitExceededException::class);

    expect(($this->usage)('executions')->used)->toBe(0);
});

it('allows a batch increment that lands exactly on the limit', function () {
    $this->user->incrementUsage('executions', 5);

    expect(($this->usage)('executions')->used)->toBe(5)
        ->and($this->user->hasReachedLimit('executions'))->toBeTrue();
});

it('enforces the limit against the database, not the cached counter', function () {
    $quota = app(QuotaManager::class);

    // Warm the cache while usage is still zero.
    expect($quota->getUsage($this->user, 'executions'))->toBe(0);

    // Another process consumes the whole quota. The cache is now stale for up
    // to the configured TTL — enforcement must not trust it.
    ($this->usage)('executions')->forceFill(['used' => 5])->save();

    expect($quota->getUsage($this->user, 'executions'))->toBe(0);

    expect(fn () => $this->user->incrementUsage('executions'))
        ->toThrow(UsageLimitExceededException::class);
});

it('recreates a missing usage row with the plan limit rather than unlimited', function () {
    ($this->usage)('executions')->delete();

    $this->user->incrementUsage('executions');

    $usage = ($this->usage)('executions');

    // The old fallback wrote limit = -1 here, silently granting infinite quota.
    expect($usage->limit)->toBe(5)
        ->and($usage->isUnlimited())->toBeFalse()
        ->and($usage->used)->toBe(1);

    for ($i = 0; $i < 4; $i++) {
        $this->user->incrementUsage('executions');
    }

    expect(fn () => $this->user->incrementUsage('executions'))
        ->toThrow(UsageLimitExceededException::class);
});

it('resyncs a stale row limit from the current plan', function () {
    ($this->usage)('executions')->forceFill(['limit' => Usage::UNLIMITED])->save();

    $this->user->incrementUsage('executions');

    expect(($this->usage)('executions')->limit)->toBe(5);
});

it('treats a feature with no configured limit as unlimited', function () {
    for ($i = 0; $i < 50; $i++) {
        $this->user->incrementUsage('exports');
    }

    $usage = ($this->usage)('exports');

    expect($usage->used)->toBe(50)
        ->and($usage->isUnlimited())->toBeTrue()
        ->and($this->user->hasReachedLimit('exports'))->toBeFalse();
});

it('refuses to increment a feature the plan does not grant', function () {
    expect(fn () => $this->user->incrementUsage('unknown-feature'))
        ->toThrow(UsageLimitExceededException::class);

    expect(Usage::query()->forFeature('unknown-feature')->exists())->toBeFalse();
});

it('refuses a non-positive increment amount', function () {
    expect(fn () => $this->user->incrementUsage('executions', 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->user->incrementUsage('executions', -3))
        ->toThrow(InvalidArgumentException::class);

    expect(($this->usage)('executions')->used)->toBe(0);
});

it('fires UsageLimitReached once the limit is hit', function () {
    Event::fake([UsageLimitReached::class]);

    $this->user->incrementUsage('executions', 4);
    Event::assertNotDispatched(UsageLimitReached::class);

    $this->user->incrementUsage('executions');
    Event::assertDispatched(UsageLimitReached::class);
});

it('leaves usage untouched when the increment is rejected', function () {
    $this->user->incrementUsage('executions', 4);

    expect(fn () => $this->user->incrementUsage('executions', 5))
        ->toThrow(UsageLimitExceededException::class);

    // The rejection rolls back inside the transaction.
    expect(($this->usage)('executions')->used)->toBe(4)
        ->and(app(QuotaManager::class)->remaining($this->user, 'executions'))->toBe(1);
});
