<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Quota;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use VimaTech\LaravelQuotas\Enums\PeriodAnchor;

/**
 * Works out when the current quota period began.
 *
 * Everything else about resetting follows from this one answer: a counter is
 * stale exactly when it was last reset before the current period started.
 *
 * Periods are measured from the subscription's anniversary rather than from the
 * calendar, so someone who subscribes on the 20th gets their allowance back on
 * the 20th, not three days later because the month happened to turn over.
 * With no anchor available, or for a feature anchored to the calendar, periods
 * follow the calendar in the application timezone.
 */
final class QuotaPeriod
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const YEARLY = 'yearly';

    /**
     * Never rolls over on its own; only an explicit reset clears the counter.
     */
    public const MANUAL = 'manual';

    private const INTERVALS = [
        self::DAILY,
        self::WEEKLY,
        self::MONTHLY,
        self::YEARLY,
        self::MANUAL,
    ];

    public function __construct(
        private readonly string $interval,
        private readonly PeriodAnchor $anchoring = PeriodAnchor::Subscription,
    ) {
        // Loud, not lenient: a misspelt interval that silently became monthly
        // would change when customers get their allowance back, and nobody
        // would notice until an invoice dispute. Same policy as the resolver
        // key in the service provider.
        if (! in_array($interval, self::INTERVALS, true)) {
            $known = implode(', ', self::INTERVALS);

            throw new InvalidArgumentException(
                "Unknown quota reset interval [{$interval}]. Use one of [{$known}]."
            );
        }
    }

    public static function fromConfig(): self
    {
        return new self((string) config('quotas.quotas.reset_interval', self::MONTHLY));
    }

    /**
     * The period governing one feature: its interval from
     * `quotas.quotas.feature_intervals` or `reset_interval`, and its anchoring
     * from `quotas.quotas.feature_anchors`.
     */
    public static function forFeature(string $feature): self
    {
        $intervals = config('quotas.quotas.feature_intervals', []);

        $interval = is_array($intervals) && isset($intervals[$feature]) && is_string($intervals[$feature])
            ? $intervals[$feature]
            : (string) config('quotas.quotas.reset_interval', self::MONTHLY);

        return new self($interval, self::anchoringFor($feature));
    }

    public function followsSubscription(): bool
    {
        return $this->anchoring === PeriodAnchor::Subscription && $this->interval !== self::MANUAL;
    }

    /**
     * The start of the period currently in progress, or null when quotas are
     * reset by hand and therefore have no period at all.
     */
    public function currentStart(?CarbonImmutable $anchor = null, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        return $this->bounds($anchor, $now)[0] ?? null;
    }

    /**
     * The first instant of the next period, when the allowance comes back, or
     * null on the manual interval.
     */
    public function currentEnd(?CarbonImmutable $anchor = null, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        return $this->bounds($anchor, $now)[1] ?? null;
    }

    /**
     * Whether a counter last reset at the given time belongs to a period that
     * has since ended.
     */
    public function hasRolledOver(?CarbonImmutable $lastResetAt, ?CarbonImmutable $anchor = null, ?CarbonImmutable $now = null): bool
    {
        $start = $this->currentStart($anchor, $now);

        if ($start === null) {
            return false;
        }

        // A counter that has never been reset is treated as belonging to the
        // period it was created in, which the caller records as reset_at.
        return $lastResetAt === null || $lastResetAt->lessThan($start);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function bounds(?CarbonImmutable $anchor, ?CarbonImmutable $now): ?array
    {
        $now = $now ?? CarbonImmutable::now();

        if ($this->interval === self::MANUAL) {
            return null;
        }

        if ($anchor === null || $this->anchoring === PeriodAnchor::Calendar) {
            $start = $this->calendarStart($now);

            return [$start, $this->addIntervals($start, 1)];
        }

        // An anchor in the future belongs to a subscription that has not begun:
        // the period starts with it rather than winding backwards.
        $elapsed = $anchor->greaterThan($now) ? 0 : $this->elapsedIntervals($anchor, $now);

        return [$this->addIntervals($anchor, $elapsed), $this->addIntervals($anchor, $elapsed + 1)];
    }

    private static function anchoringFor(string $feature): PeriodAnchor
    {
        $anchors = config('quotas.quotas.feature_anchors', []);

        if (! is_array($anchors)) {
            throw new InvalidArgumentException('quotas.quotas.feature_anchors must be an array of feature => anchor.');
        }

        if (! array_key_exists($feature, $anchors)) {
            return PeriodAnchor::Subscription;
        }

        $anchoring = is_string($anchors[$feature]) ? PeriodAnchor::tryFrom($anchors[$feature]) : null;

        if ($anchoring === null) {
            $given = is_scalar($anchors[$feature]) ? (string) $anchors[$feature] : get_debug_type($anchors[$feature]);
            $known = implode(', ', array_column(PeriodAnchor::cases(), 'value'));

            throw new InvalidArgumentException(
                "Unknown quota period anchor [{$given}] for feature [{$feature}] in quotas.quotas.feature_anchors. Use one of [{$known}]."
            );
        }

        return $anchoring;
    }

    private function calendarStart(CarbonImmutable $now): CarbonImmutable
    {
        return match ($this->interval) {
            self::DAILY => $now->startOfDay(),
            self::WEEKLY => $now->startOfWeek(),
            self::YEARLY => $now->startOfYear(),
            default => $now->startOfMonth(),
        };
    }

    /**
     * How many whole intervals separate the anchor from now.
     *
     * The no-overflow variants in addIntervals() are the point: plain
     * addMonths() turns a January 31st anchor into March 3rd, skipping February
     * and handing out a free month of quota. Carbon's diff counts less than a
     * month between January 31st and February 28th, so it is only an estimate,
     * corrected against the actual dates.
     */
    private function elapsedIntervals(CarbonImmutable $anchor, CarbonImmutable $now): int
    {
        $elapsed = max(0, (int) match ($this->interval) {
            self::DAILY => $anchor->diffInDays($now),
            self::WEEKLY => $anchor->diffInWeeks($now),
            self::YEARLY => $anchor->diffInYears($now),
            default => $anchor->diffInMonths($now),
        });

        while ($this->addIntervals($anchor, $elapsed + 1)->lessThanOrEqualTo($now)) {
            $elapsed++;
        }

        while ($elapsed > 0 && $this->addIntervals($anchor, $elapsed)->greaterThan($now)) {
            $elapsed--;
        }

        return $elapsed;
    }

    private function addIntervals(CarbonImmutable $anchor, int $count): CarbonImmutable
    {
        return match ($this->interval) {
            self::DAILY => $anchor->addDays($count),
            // Weeks cannot overflow: every week has seven days.
            self::WEEKLY => $anchor->addWeeks($count),
            self::YEARLY => $anchor->addYearsNoOverflow($count),
            default => $anchor->addMonthsNoOverflow($count),
        };
    }
}
