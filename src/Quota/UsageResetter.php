<?php

declare(strict_types=1);

namespace VimaTech\LaravelQuotas\Quota;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use VimaTech\LaravelQuotas\Contracts\SubscriptionResolverInterface;
use VimaTech\LaravelQuotas\Events\UsageReset;
use VimaTech\LaravelQuotas\Models\Usage;

/**
 * Rolls usage counters over when their billing period ends.
 *
 * Resets happen two ways, and both matter. The lazy path runs on every read and
 * every increment, so a counter is never allowed to report a stale number even
 * if no scheduler is configured: quotas that silently stop resetting are worse
 * than quotas that reset late. The sweep is the eager counterpart, run by
 * `quotas:reset`, and exists so that counters nobody touches still come
 * back to zero and so dashboards read correctly between requests.
 */
final class UsageResetter
{
    /**
     * Anchors already resolved during this request, keyed by billable.
     * Dropped with QuotaManager::forgetPlan() and flush().
     *
     * @var array<string, CarbonImmutable|null>
     */
    private array $anchors = [];

    public function __construct(
        private readonly SubscriptionResolverInterface $resolver,
        private readonly UsageCache $cache,
    ) {}

    /**
     * Reset the counter if its period has rolled over, and report whether it did.
     */
    public function resetIfRolledOver(Model $billable, Usage $usage): bool
    {
        if (! $this->hasRolledOver($billable, $usage)) {
            return false;
        }

        $previous = $usage->used;

        $usage->used = 0;
        $usage->reset_at = now();
        $usage->save();

        $this->cache->forget($billable, $usage->feature);

        event(new UsageReset($billable, $usage->feature, $previous));

        return true;
    }

    /**
     * Reset every counter whose period has rolled over.
     *
     * @return int the number of counters reset
     */
    public function sweep(int $chunkSize = 500): int
    {
        $reset = 0;

        Usage::query()
            ->with('billable')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($usages) use (&$reset): void {
                foreach ($usages as $usage) {
                    $billable = $usage->billable;

                    // A counter whose owner has been deleted has nothing to
                    // anchor against; model:prune or a cascade should clear it.
                    if (! $billable instanceof Model) {
                        continue;
                    }

                    if ($this->resetIfRolledOver($billable, $usage)) {
                        $reset++;
                    }
                }
            });

        return $reset;
    }

    /**
     * Whether this counter belongs to a period that has already ended.
     */
    public function hasRolledOver(Model $billable, Usage $usage): bool
    {
        $resetAt = $usage->reset_at !== null
            ? CarbonImmutable::instance($usage->reset_at)
            : null;

        $period = QuotaPeriod::forFeature($usage->feature);

        return $period->hasRolledOver($resetAt, $this->anchorFor($billable, $period));
    }

    public function periodEndsAt(Model $billable, string $feature): ?CarbonImmutable
    {
        $period = QuotaPeriod::forFeature($feature);

        return $period->currentEnd($this->anchorFor($billable, $period));
    }

    public function forgetAnchor(Model $billable): void
    {
        unset($this->anchors[$this->memoKey($billable)]);
    }

    public function flush(): void
    {
        $this->anchors = [];
    }

    private function anchorFor(Model $billable, QuotaPeriod $period): ?CarbonImmutable
    {
        if (! $period->followsSubscription()) {
            return null;
        }

        $key = $this->memoKey($billable);

        // array_key_exists, not ??=: a null anchor is a real answer (no
        // subscription to measure from) and re-resolving it for every counter
        // would put the sweep back to one lookup per row.
        if (! array_key_exists($key, $this->anchors)) {
            $this->anchors[$key] = $this->resolver->anchor($billable);
        }

        return $this->anchors[$key];
    }

    private function memoKey(Model $billable): string
    {
        return $billable->getMorphClass().':'.$billable->getKey();
    }
}
