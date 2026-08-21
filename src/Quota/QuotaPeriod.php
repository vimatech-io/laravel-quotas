<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Quota;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Works out when the current quota period began.
 *
 * Everything else about resetting follows from this one answer: a counter is
 * stale exactly when it was last reset before the current period started.
 *
 * Periods are measured from the subscription's anniversary rather than from the
 * calendar, so someone who subscribes on the 20th gets their allowance back on
 * the 20th — not three days later because the month happened to turn over.
 * With no anchor available the calendar is used instead.
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
     * The period governing one feature.
     *
     * Most features follow the application-wide interval; a feature named in
     * `quotas.quotas.feature_intervals` follows its own instead. This is what
     * lets AI tokens come back weekly while exports stay monthly — one global
     * interval cannot say both.
     */
    public static function forFeature(string $feature): self
    {
        $overrides = config('quotas.quotas.feature_intervals', []);

        if (is_array($overrides) && isset($overrides[$feature]) && is_string($overrides[$feature])) {
            return new self($overrides[$feature]);
        }

        return self::fromConfig();
    }

    /**
     * The start of the period currently in progress, or null when quotas are
     * reset by hand and therefore have no period at all.
     */
    public function currentStart(?CarbonImmutable $anchor = null, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $now = $now ?? CarbonImmutable::now();

        if ($this->interval === self::MANUAL) {
            return null;
        }

        // An anchor in the future belongs to a subscription that has not begun;
        // treat the period as starting with it rather than winding backwards.
        if ($anchor !== null && $anchor->greaterThan($now)) {
            return $anchor;
        }

        return $anchor === null
            ? $this->calendarStart($now)
            : $this->anniversaryStart($anchor, $now);
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
     * Roll the anchor forward by whole intervals until the next one would
     * overshoot the present.
     *
     * The no-overflow variants are the whole point of this method: plain
     * addMonths() turns a January 31st anchor into March 3rd, skipping February
     * entirely and handing out a free month of quota. Clamping to the 28th is
     * the rule payment providers apply to billing anniversaries.
     *
     * The diff is only a starting estimate for the same reason — between
     * January 31st and February 28th, Carbon counts less than a whole month
     * even though the anniversary has come round — so it is corrected against
     * the actual dates rather than trusted.
     */
    private function anniversaryStart(CarbonImmutable $anchor, CarbonImmutable $now): CarbonImmutable
    {
        $elapsed = max(0, (int) match ($this->interval) {
            self::DAILY => $anchor->diffInDays($now),
            self::WEEKLY => $anchor->diffInWeeks($now),
            self::YEARLY => $anchor->diffInYears($now),
            default => $anchor->diffInMonths($now),
        });

        // The estimate undershot: the next anniversary has already passed.
        while ($this->addIntervals($anchor, $elapsed + 1)->lessThanOrEqualTo($now)) {
            $elapsed++;
        }

        // The estimate overshot: the period it names has not begun yet.
        while ($elapsed > 0 && $this->addIntervals($anchor, $elapsed)->greaterThan($now)) {
            $elapsed--;
        }

        return $this->addIntervals($anchor, $elapsed);
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
