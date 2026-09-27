<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use VimaTech\LaravelQuotas\Actions\IncrementUsageAction;
use VimaTech\LaravelQuotas\DTOs\PlanData;
use VimaTech\LaravelQuotas\Events\UsageLimitReached;
use VimaTech\LaravelQuotas\Events\UsageReset;
use VimaTech\LaravelQuotas\Exceptions\UsageLimitExceededException;
use VimaTech\LaravelQuotas\Managers\PlanManager;
use VimaTech\LaravelQuotas\Models\Usage;
use VimaTech\LaravelQuotas\Quota\UsageCache;
use VimaTech\LaravelQuotas\Tests\Fixtures\User;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures');

    app(PlanManager::class)->create(new PlanData(
        name: 'Free',
        slug: 'free',
        features: ['executions'],
        limits: ['executions' => 2],
    ));

    config()->set('quotas.subscriptions.default_plan', 'free');

    $this->user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
});

function recordExecution(User $user, callable $afterIncrement): void
{
    DB::transaction(function () use ($user, $afterIncrement): void {
        app(IncrementUsageAction::class)->execute($user, 'executions');
        $afterIncrement();
    });
}

it('gives the unit back when the enclosing transaction rolls back', function () {
    $this->user->incrementUsage('executions');

    expect(fn () => recordExecution($this->user, fn () => throw new RuntimeException('downstream write failed')))
        ->toThrow(RuntimeException::class);

    expect(Usage::query()->forBillable($this->user)->forFeature('executions')->value('used'))->toBe(1)
        ->and($this->user->usageOf('executions'))->toBe(1);
});

it('rolls back the counter row it created for a first use', function () {
    expect(fn () => recordExecution($this->user, fn () => throw new RuntimeException('downstream write failed')))
        ->toThrow(RuntimeException::class);

    expect(Usage::query()->forBillable($this->user)->forFeature('executions')->exists())->toBeFalse();

    recordExecution($this->user, fn () => null);

    expect($this->user->usageOf('executions'))->toBe(1);
});

it('does not cache a count read before the enclosing transaction rolled back', function () {
    expect(fn () => recordExecution($this->user, function (): void {
        expect($this->user->remainingUsage('executions'))->toBe(1);

        throw new RuntimeException('downstream write failed');
    }))->toThrow(RuntimeException::class);

    expect($this->user->remainingUsage('executions'))->toBe(2)
        ->and($this->user->canUse('executions'))->toBeTrue();
});

it('invalidates the cached count when the enclosing transaction commits', function () {
    $this->user->incrementUsage('executions');

    recordExecution($this->user, function (): void {
        // another process reads the committed count while this one is still open
        Cache::put(app(UsageCache::class)->key($this->user, 'executions'), 1, 60);
    });

    expect($this->user->usageOf('executions'))->toBe(2);
});

it('announces a reached limit only once the enclosing transaction commits', function () {
    Event::fake([UsageLimitReached::class]);
    $this->user->incrementUsage('executions');

    expect(fn () => recordExecution($this->user, fn () => throw new RuntimeException('downstream write failed')))
        ->toThrow(RuntimeException::class);

    Event::assertNotDispatched(UsageLimitReached::class);

    recordExecution($this->user, fn () => null);

    Event::assertDispatched(UsageLimitReached::class);
});

it('survives a counter row created concurrently between the check and the insert', function () {
    $raced = false;

    DB::connection()->beforeExecuting(function (string $query) use (&$raced): void {
        if ($raced || ! str_starts_with(strtolower($query), 'insert or ignore')) {
            return;
        }

        $raced = true;

        DB::table('quota_usages')->insert([
            'billable_type' => $this->user->getMorphClass(),
            'billable_id' => $this->user->getKey(),
            'feature' => 'executions',
            'used' => 1,
            'limit' => 2,
            'reset_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    recordExecution($this->user, fn () => DB::table('users')->update(['name' => 'Ada Lovelace']));

    expect($raced)->toBeTrue()
        ->and($this->user->usageOf('executions'))->toBe(2)
        ->and(DB::table('users')->value('name'))->toBe('Ada Lovelace')
        ->and(fn () => recordExecution($this->user, fn () => null))->toThrow(UsageLimitExceededException::class);
});

it('announces a rollover spent through the write path', function () {
    $this->travelTo('2026-01-20 09:00:00');
    $this->user->incrementUsage('executions', 2);

    Event::fake([UsageReset::class]);

    $this->travelTo('2026-02-01 00:00:00');
    $this->user->incrementUsage('executions');

    Event::assertDispatched(UsageReset::class, fn (UsageReset $event): bool => $event->previousUsage === 2);
});
