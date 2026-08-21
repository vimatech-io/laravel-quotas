<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use VimaTech\LaravelQuotas\Quota\QuotaPeriod;

it('measures monthly periods from the subscription anniversary', function () {
    $period = new QuotaPeriod(QuotaPeriod::MONTHLY);
    $anchor = CarbonImmutable::parse('2026-01-20 09:30:00');

    // Two months and a bit after the anchor: the period running on March 5th
    // started on February 20th, not on March 1st.
    $start = $period->currentStart($anchor, CarbonImmutable::parse('2026-03-05 00:00:00'));

    expect($start->toDateTimeString())->toBe('2026-02-20 09:30:00');
});

it('does not roll over before the anniversary comes round', function () {
    $period = new QuotaPeriod(QuotaPeriod::MONTHLY);
    $anchor = CarbonImmutable::parse('2026-01-20 09:30:00');

    // The calendar month has turned over, but the billing month has not: a
    // counter reset on January 20th is still current on February 1st.
    expect($period->hasRolledOver(
        CarbonImmutable::parse('2026-01-20 09:30:00'),
        $anchor,
        CarbonImmutable::parse('2026-02-01 00:00:00'),
    ))->toBeFalse();

    // On the 20th it has.
    expect($period->hasRolledOver(
        CarbonImmutable::parse('2026-01-20 09:30:00'),
        $anchor,
        CarbonImmutable::parse('2026-02-20 10:00:00'),
    ))->toBeTrue();
});

it('clamps anniversaries that overflow a shorter month', function () {
    $period = new QuotaPeriod(QuotaPeriod::MONTHLY);
    $anchor = CarbonImmutable::parse('2026-01-31 08:00:00');

    // February has no 31st. The period must land on the last day it has,
    // exactly as payment providers bill a 31st anniversary.
    $start = $period->currentStart($anchor, CarbonImmutable::parse('2026-02-28 12:00:00'));

    expect($start->format('Y-m-d'))->toBe('2026-02-28');
});

it('falls back to the calendar when there is no anchor', function () {
    $period = new QuotaPeriod(QuotaPeriod::MONTHLY);

    $start = $period->currentStart(null, CarbonImmutable::parse('2026-03-17 12:00:00'));

    expect($start->toDateTimeString())->toBe('2026-03-01 00:00:00');
});

it('treats a counter that never reset as rolled over', function () {
    $period = new QuotaPeriod(QuotaPeriod::MONTHLY);

    expect($period->hasRolledOver(null, null, CarbonImmutable::parse('2026-03-17 12:00:00')))
        ->toBeTrue();
});

it('never rolls over on the manual interval', function () {
    $period = new QuotaPeriod(QuotaPeriod::MANUAL);

    expect($period->currentStart(null, CarbonImmutable::parse('2030-01-01 00:00:00')))->toBeNull()
        ->and($period->hasRolledOver(
            CarbonImmutable::parse('2020-01-01 00:00:00'),
            null,
            CarbonImmutable::parse('2030-01-01 00:00:00'),
        ))->toBeFalse();
});

it('handles daily and yearly intervals', function () {
    $daily = new QuotaPeriod(QuotaPeriod::DAILY);
    $yearly = new QuotaPeriod(QuotaPeriod::YEARLY);
    $anchor = CarbonImmutable::parse('2026-01-20 09:30:00');
    $now = CarbonImmutable::parse('2026-03-05 10:00:00');

    expect($daily->currentStart($anchor, $now)->toDateTimeString())->toBe('2026-03-05 09:30:00')
        ->and($yearly->currentStart($anchor, $now)->toDateTimeString())->toBe('2026-01-20 09:30:00');
});

it('does not wind backwards for a subscription that has not started', function () {
    $period = new QuotaPeriod(QuotaPeriod::MONTHLY);
    $anchor = CarbonImmutable::parse('2026-06-01 00:00:00');

    $start = $period->currentStart($anchor, CarbonImmutable::parse('2026-03-05 00:00:00'));

    expect($start->toDateTimeString())->toBe('2026-06-01 00:00:00');
});

it('measures weekly periods from the subscription anniversary', function () {
    $period = new QuotaPeriod(QuotaPeriod::WEEKLY);
    // A Tuesday.
    $anchor = CarbonImmutable::parse('2026-01-06 09:30:00');

    // The following Thursday: the running week still started on the 6th.
    expect($period->currentStart($anchor, CarbonImmutable::parse('2026-01-08 12:00:00'))
        ->toDateTimeString())->toBe('2026-01-06 09:30:00');

    // Two weeks and a day later: the week of the 20th is in progress.
    expect($period->currentStart($anchor, CarbonImmutable::parse('2026-01-21 12:00:00'))
        ->toDateTimeString())->toBe('2026-01-20 09:30:00');
});

it('rolls a weekly counter over on the anniversary weekday, not on Monday', function () {
    $period = new QuotaPeriod(QuotaPeriod::WEEKLY);
    $anchor = CarbonImmutable::parse('2026-01-06 09:30:00'); // Tuesday

    // Monday of the next calendar week: the billing week has not turned yet.
    expect($period->hasRolledOver(
        CarbonImmutable::parse('2026-01-06 09:30:00'),
        $anchor,
        CarbonImmutable::parse('2026-01-12 08:00:00'),
    ))->toBeFalse();

    // Tuesday it has.
    expect($period->hasRolledOver(
        CarbonImmutable::parse('2026-01-06 09:30:00'),
        $anchor,
        CarbonImmutable::parse('2026-01-13 10:00:00'),
    ))->toBeTrue();
});

it('lets one feature follow its own interval while the rest follow the default', function () {
    config()->set('quotas.quotas.reset_interval', 'monthly');
    config()->set('quotas.quotas.feature_intervals', ['ai_tokens' => 'weekly']);

    expect(QuotaPeriod::forFeature('ai_tokens')->currentStart(
        CarbonImmutable::parse('2026-01-06 09:00:00'),
        CarbonImmutable::parse('2026-01-14 12:00:00'),
    )->toDateString())->toBe('2026-01-13')
        ->and(QuotaPeriod::forFeature('exports')->currentStart(
            CarbonImmutable::parse('2026-01-06 09:00:00'),
            CarbonImmutable::parse('2026-01-14 12:00:00'),
        )->toDateString())->toBe('2026-01-06');
});

it('refuses a misspelt reset interval instead of silently becoming monthly', function () {
    expect(fn () => new QuotaPeriod('weekl'))
        ->toThrow(InvalidArgumentException::class, 'Unknown quota reset interval');
});
