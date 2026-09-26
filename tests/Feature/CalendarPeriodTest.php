<?php

declare(strict_types=1);

use VimaTech\LaravelQuotas\DTOs\PlanData;
use VimaTech\LaravelQuotas\Exceptions\UsageLimitExceededException;
use VimaTech\LaravelQuotas\Managers\PlanManager;
use VimaTech\LaravelQuotas\Managers\QuotaManager;
use VimaTech\LaravelQuotas\Tests\Fixtures\User;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures');

    config()->set('quotas.quotas.feature_anchors', ['invoice_issuing' => 'calendar']);

    app(PlanManager::class)->create(new PlanData(
        name: 'Pro',
        slug: 'pro',
        features: ['invoice_issuing', 'exports'],
        limits: ['invoice_issuing' => 5, 'exports' => 5],
    ));

    $this->travelTo('2026-01-20 09:00:00');
    $this->user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
    $this->user->subscribe('pro');
});

function periodEnd(User $user, string $feature): ?string
{
    return app(QuotaManager::class)->periodEndsAt($user, $feature)?->toDateTimeString();
}

it('renews a calendar feature on the 1st whatever the subscription anniversary', function () {
    $this->user->incrementUsage('invoice_issuing', 5);
    $this->user->incrementUsage('exports', 5);

    $this->travelTo('2026-01-31 23:59:59');
    expect($this->user->canUse('invoice_issuing'))->toBeFalse()
        ->and(periodEnd($this->user, 'invoice_issuing'))->toBe('2026-02-01 00:00:00');

    $this->travelTo('2026-02-01 00:00:00');
    expect($this->user->remainingUsage('invoice_issuing'))->toBe(5)
        ->and($this->user->remainingUsage('exports'))->toBe(0)
        ->and(periodEnd($this->user, 'invoice_issuing'))->toBe('2026-03-01 00:00:00')
        ->and(periodEnd($this->user, 'exports'))->toBe('2026-02-20 09:00:00');
});

it('spends the new month on the write path at the first second of the month', function () {
    $this->user->incrementUsage('invoice_issuing', 5);

    $this->travelTo('2026-01-31 23:59:59');
    expect(fn () => $this->user->incrementUsage('invoice_issuing'))->toThrow(UsageLimitExceededException::class);

    $this->travelTo('2026-02-01 00:00:00');
    $this->user->incrementUsage('invoice_issuing', 5);

    $this->travelTo('2026-02-28 23:59:59');
    expect(fn () => $this->user->incrementUsage('invoice_issuing'))->toThrow(UsageLimitExceededException::class);

    $this->travelTo('2026-03-01 00:00:00');
    $this->user->incrementUsage('invoice_issuing');

    expect($this->user->usageOf('invoice_issuing'))->toBe(1);
});

it('reports the end of an anniversary period, clamped on short months', function () {
    config()->set('quotas.quotas.feature_anchors', []);

    $this->travelTo('2026-01-31 08:00:00');
    $user = User::query()->create(['name' => 'Sam', 'email' => 'sam@example.test']);
    $user->subscribe('pro');

    expect(periodEnd($user, 'exports'))->toBe('2026-02-28 08:00:00');

    $this->travelTo('2026-02-28 08:00:00');
    expect(periodEnd($user, 'exports'))->toBe('2026-03-31 08:00:00');
});

it('returns the allowance exactly at the reported end of the period', function () {
    $this->user->incrementUsage('exports', 5);
    $end = app(QuotaManager::class)->periodEndsAt($this->user, 'exports');

    $this->travelTo($end->subSecond());
    expect($this->user->remainingUsage('exports'))->toBe(0);

    $this->travelTo($end);
    expect($this->user->remainingUsage('exports'))->toBe(5);
});

it('has no end on the manual interval', function () {
    config()->set('quotas.quotas.feature_intervals', ['invoice_issuing' => 'manual']);

    expect(periodEnd($this->user, 'invoice_issuing'))->toBeNull();
});

it('follows the calendar in the application timezone', function () {
    $default = date_default_timezone_get();
    date_default_timezone_set('Pacific/Auckland');

    try {
        $this->travelTo('2026-01-31 12:00:00');

        expect(app(QuotaManager::class)->periodEndsAt($this->user, 'invoice_issuing')?->format('Y-m-d H:i:s e'))
            ->toBe('2026-02-01 00:00:00 Pacific/Auckland');
    } finally {
        date_default_timezone_set($default);
    }
});

it('refuses an anchor it does not know', function () {
    config()->set('quotas.quotas.feature_anchors', ['invoice_issuing' => 'calender']);

    $this->user->canUse('invoice_issuing');
})->throws(InvalidArgumentException::class, 'Unknown quota period anchor [calender] for feature [invoice_issuing]');

it('refuses anchors that are not a map of features', function () {
    config()->set('quotas.quotas.feature_anchors', 'calendar');

    $this->user->canUse('invoice_issuing');
})->throws(InvalidArgumentException::class, 'quotas.quotas.feature_anchors must be an array');
